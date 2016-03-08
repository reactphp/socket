<?php

namespace React\SocketClient;

use React\EventLoop\LoopInterface;
use React\Dns\Resolver\Resolver;
use React\Stream\Stream;
use React\Promise;
use React\Promise\Deferred;

class TcpConnector implements ConnectorInterface
{
    private $loop;
    private $context;

    public function __construct(LoopInterface $loop, array $context = array())
    {
        $this->loop = $loop;
        $this->context = $context;
    }

    public function create($ip, $port)
    {
        if (false === filter_var($ip, FILTER_VALIDATE_IP)) {
            return Promise\reject(new \InvalidArgumentException('Given parameter "' . $ip . '" is not a valid IP'));
        }

        $url = $this->getSocketUrl($ip, $port);

        $socket = @stream_socket_client(
            $url,
            $errno,
            $errstr,
            0,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            stream_context_create(array('socket' => $this->context))
        );

        if (false === $socket) {
            return Promise\reject(new \RuntimeException(
                sprintf("Connection to %s:%d failed: %s", $ip, $port, $errstr),
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
        $deferred = new Deferred();

        $loop = $this->loop;

        $this->loop->addWriteStream($stream, function ($stream) use ($loop, $deferred) {
            $loop->removeWriteStream($stream);

            $deferred->resolve($stream);
        });

        return $deferred->promise();
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
        return new Stream($socket, $this->loop);
    }

    private function getSocketUrl($ip, $port)
    {
        if (strpos($ip, ':') !== false) {
            // enclose IPv6 addresses in square brackets before appending port
            $ip = '[' . $ip . ']';
        }
        return sprintf('tcp://%s:%s', $ip, $port);
    }
}
