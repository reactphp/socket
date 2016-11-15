<?php

namespace React\SocketClient;

use React\SocketClient\ConnectorInterface;
use React\EventLoop\LoopInterface;
use React\Promise\Timer;
use React\Stream\Stream;
use React\Promise\Promise;
use React\Promise\CancellablePromiseInterface;

class TimeoutConnector implements ConnectorInterface
{
    private $connector;
    private $timeout;
    private $loop;

    public function __construct(ConnectorInterface $connector, $timeout, LoopInterface $loop)
    {
        $this->connector = $connector;
        $this->timeout = $timeout;
        $this->loop = $loop;
    }

    public function create($host, $port)
    {
        $promise = $this->connector->create($host, $port);

        return Timer\timeout(new Promise(
            function ($resolve, $reject) use ($promise) {
                // resolve/reject with result of TCP/IP connection
                $promise->then($resolve, $reject);
            },
            function ($_, $reject) use ($promise) {
                // cancellation should reject connection attempt
                $reject(new \RuntimeException('Connection attempt cancelled during connection'));

                // forefully close TCP/IP connection if it completes despite cancellation
                $promise->then(function (Stream $stream) {
                    $stream->close();
                });

                // (try to) cancel pending TCP/IP connection
                if ($promise instanceof CancellablePromiseInterface) {
                    $promise->cancel();
                }
            }
        ), $this->timeout, $this->loop);
    }
}
