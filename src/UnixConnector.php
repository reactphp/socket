<?php

namespace React\SocketClient;

use React\SocketClient\ConnectorInterface;
use React\Stream\Stream;
use React\EventLoop\LoopInterface;
use React\Promise;
use RuntimeException;

/**
 * Unix domain socket connector
 *
 * Unix domain sockets use atomic operations, so we can as well emulate
 * async behavior.
 */
class UnixConnector implements ConnectorInterface
{
    private $loop;

    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;
    }

    public function create($path, $unusedPort = 0)
    {
        $resource = @stream_socket_client('unix://' . $path, $errno, $errstr, 1.0);

        if (!$resource) {
            return Promise\reject(new RuntimeException('Unable to connect to unix domain socket "' . $path . '": ' . $errstr, $errno));
        }

        return Promise\resolve(new Stream($resource, $this->loop));
    }
}
