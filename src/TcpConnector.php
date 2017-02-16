<?php

namespace React\SocketClient;

use React\EventLoop\LoopInterface;
use React\Stream\Stream;
use React\Promise;

final class TcpConnector implements ConnectorInterface
{
    private $loop;
    private $context;

    public function __construct(LoopInterface $loop, array $context = array())
    {
        $this->loop = $loop;
        $this->context = $context;
    }

    public function connect($uri)
    {
        if (strpos($uri, '://') === false) {
            $uri = 'tcp://' . $uri;
        }

        $parts = parse_url($uri);
        if (!$parts || !isset($parts['scheme'], $parts['host'], $parts['port']) || $parts['scheme'] !== 'tcp') {
            return Promise\reject(new \InvalidArgumentException('Given URI "' . $uri . '" is invalid'));
        }

        $ip = trim($parts['host'], '[]');
        if (false === filter_var($ip, FILTER_VALIDATE_IP)) {
            return Promise\reject(new \InvalidArgumentException('Given URI "' . $ip . '" does not contain a valid host IP'));
        }

        $socket = @stream_socket_client(
            $uri,
            $errno,
            $errstr,
            0,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            stream_context_create(array('socket' => $this->context))
        );

        if (false === $socket) {
            return Promise\reject(new \RuntimeException(
                sprintf("Connection to %s failed: %s", $uri, $errstr),
                $errno
            ));
        }

        stream_set_blocking($socket, 0);

        // wait for connection

        return $this
            ->waitForStreamOnce($socket)
            ->then(array($this, 'checkConnectedSocket'))
            ->then(array($this, 'handleConnectedSocket'));
    }

    private function waitForStreamOnce($stream)
    {
        $loop = $this->loop;

        return new Promise\Promise(function ($resolve) use ($loop, $stream) {
            $loop->addWriteStream($stream, function ($stream) use ($loop, $resolve) {
                $loop->removeWriteStream($stream);

                $resolve($stream);
            });
        }, function () use ($loop, $stream) {
            $loop->removeWriteStream($stream);
            fclose($stream);

            throw new \RuntimeException('Cancelled while waiting for TCP/IP connection to be established');
        });
    }

    /** @internal */
    public function checkConnectedSocket($socket)
    {
        // The following hack looks like the only way to
        // detect connection refused errors with PHP's stream sockets.
        if (false === stream_socket_get_name($socket, true)) {
            fclose($socket);

            return Promise\reject(new ConnectionException('Connection refused'));
        }

        return Promise\resolve($socket);
    }

    /** @internal */
    public function handleConnectedSocket($socket)
    {
        return new StreamConnection($socket, $this->loop);
    }
}
