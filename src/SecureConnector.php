<?php

namespace React\Socket;

use React\EventLoop\LoopInterface;
use React\Promise;

final class SecureConnector implements SecureConnectorInterface
{
    private $connector;
    private $streamEncryption;
    private $context;

    public function __construct(ConnectorInterface $connector, LoopInterface $loop, array $context = array())
    {
        $this->connector = $connector;
        $this->streamEncryption = new StreamEncryption($loop, false);
        $this->context = $context;
    }

    public function connect($uri)
    {
        if (!\function_exists('stream_socket_enable_crypto')) {
            return Promise\reject(new \BadMethodCallException('Encryption not supported on your platform (HHVM < 3.8?)')); // @codeCoverageIgnore
        }

        if (\strpos($uri, '://') === false) {
            $uri = 'tls://' . $uri;
        }

        $parts = \parse_url($uri);
        if (!$parts || !isset($parts['scheme']) || $parts['scheme'] !== 'tls') {
            return Promise\reject(new \InvalidArgumentException('Given URI "' . $uri . '" is invalid'));
        }

        $uri = \str_replace('tls://', '', $uri);
        $context = $this->context;

        $that = $this;
        $encryption = $this->streamEncryption;
        $connected = false;

        $promise = $this->connector->connect($uri)->then(function (ConnectionInterface $connection) use ($that, $context, $encryption, $uri, &$promise, &$connected) {
            // (unencrypted) TCP/IP connection succeeded
            $connected = true;

            if (!$connection instanceof ExtConnectionInterface) {
                $connection->close();
                throw new \UnexpectedValueException('Base connector does not use a connection using the extended connection interface');
            }

            // try to enable encryption
            return $promise = $that->enableTLS($connection)->then(null, function ($error) use ($connection, $uri) {
                // establishing encryption failed => close invalid connection and return error
                $connection->close();

                throw new \RuntimeException(
                    'Connection to ' . $uri . ' failed: ' . $error->getMessage(),
                    $error->getCode()
                );
            });
        });

        return new Promise\Promise(
            function ($resolve, $reject) use ($promise) {
                $promise->then($resolve, $reject);
            },
            function ($_, $reject) use (&$promise, $uri, &$connected) {
                if ($connected) {
                    $reject(new \RuntimeException('Connection to ' . $uri . ' cancelled during TLS handshake'));
                }

                $promise->cancel();
                $promise = null;
            }
        );
    }

    public function enableTLS(ExtConnectionInterface $connection)
    {
        $stream = $connection->getStream();
        $context = $this->context;

        // set required SSL/TLS context options
        foreach ($context as $name => $value) {
            \stream_context_set_option($stream, 'ssl', $name, $value);
        }

        // try to enable encryption
        return $this->streamEncryption->enable($connection)->then(null, function ($error) {
            throw new \RuntimeException(
                'Error encountered during TLS handshake: ' . $error->getMessage(),
                $error->getCode()
            );
        });
    }

    public function disableTLS(ExtConnectionInterface $connection)
    {
        return $this->streamEncryption->disable($connection)->then(null, function ($error) {
            throw new \RuntimeException(
                'Error encountered during removing TLS: ' . $error->getMessage(),
                $error->getCode()
            );
        });
    }
}
