<?php

namespace React\Socket;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Promise;

final class TimeoutConnector implements ConnectorInterface
{
    private $connector;
    private $timeout;

    public function __construct(ConnectorInterface $connector, $timeout)
    {
        $this->connector = $connector;
        $this->timeout = $timeout;
    }

    public function connect($uri)
    {
        $promise = $this->connector->connect($uri);

        return new Promise(function ($resolve, $reject) use ($promise, $uri) {
            $timer = null;
            $promise = $promise->then(function ($v) use (&$timer, $resolve) {
                if ($timer) {
                    Loop::cancelTimer($timer);
                }
                $timer = false;
                $resolve($v);
            }, function ($v) use (&$timer, $reject) {
                if ($timer) {
                    Loop::cancelTimer($timer);
                }
                $timer = false;
                $reject($v);
            });

            // promise already resolved => no need to start timer
            if ($timer === false) {
                return;
            }

            // start timeout timer which will cancel the pending promise
            $timer = Loop::addTimer($this->timeout, function () use (&$promise, $reject, $uri) {
                $reject(new \RuntimeException(
                    'Connection to ' . $uri . ' timed out after ' . $this->timeout . ' seconds (ETIMEDOUT)',
                    \defined('SOCKET_ETIMEDOUT') ? \SOCKET_ETIMEDOUT : 110
                ));

                // Cancel pending connection to clean up any underlying resources and references.
                // Avoid garbage references in call stack by passing pending promise by reference.
                assert(\method_exists($promise, 'cancel'));
                $promise->cancel();
                $promise = null;
            });
        }, function () use (&$promise) {
            // Cancelling this promise will cancel the pending connection, thus triggering the rejection logic above.
            // Avoid garbage references in call stack by passing pending promise by reference.
            assert(\method_exists($promise, 'cancel'));
            $promise->cancel();
            $promise = null;
        });
    }
}
