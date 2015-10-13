<?php

namespace React\SocketClient;

use React\EventLoop\LoopInterface;
use React\Stream\Stream;
use React\Promise;

class SecureConnector implements ConnectorInterface
{
    private $connector;
    private $streamEncryption;
    private $context;

    public function __construct(ConnectorInterface $connector, LoopInterface $loop, array $context = array())
    {
        $this->connector = $connector;
        $this->streamEncryption = new StreamEncryption($loop);
        $this->context = $context;
    }

    public function create($host, $port)
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            return Promise\reject(new \BadMethodCallException('Encryption not supported on your platform (HHVM < 3.8?)'));
        }

        $context = $this->context + array(
            'SNI_enabled' => true,
            'peer_name' => $host
        );

        // legacy PHP < 5.6 ignores peer_name and requires legacy context options instead
        if (PHP_VERSION_ID < 50600) {
            $context += array(
                'SNI_server_name' => $host,
                'CN_match' => $host
            );
        }

        $encryption = $this->streamEncryption;
        return $this->connector->create($host, $port)->then(function (Stream $stream) use ($context, $encryption) {
            // (unencrypted) TCP/IP connection succeeded

            // set required SSL/TLS context options
            foreach ($context as $name => $value) {
                stream_context_set_option($stream->stream, 'ssl', $name, $value);
            }

            // try to enable encryption
            return $encryption->enable($stream)->then(null, function ($error) use ($stream) {
                // establishing encryption failed => close invalid connection and return error
                $stream->close();
                throw $error;
            });
        });
    }
}
