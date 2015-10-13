<?php

namespace React\SocketClient;

use React\Dns\Resolver\Resolver;
use React\Stream\Stream;
use React\Promise;
use React\Promise\CancellablePromiseInterface;

class DnsConnector implements ConnectorInterface
{
    private $connector;
    private $resolver;

    public function __construct(ConnectorInterface $connector, Resolver $resolver)
    {
        $this->connector = $connector;
        $this->resolver = $resolver;
    }

    public function create($host, $port)
    {
        $that = $this;

        return $this
            ->resolveHostname($host)
            ->then(function ($ip) use ($that, $port) {
                return $that->connect($ip, $port);
            });
    }

    private function resolveHostname($host)
    {
        if (false !== filter_var($host, FILTER_VALIDATE_IP)) {
            return Promise\resolve($host);
        }

        $promise = $this->resolver->resolve($host);

        return new Promise\Promise(
            function ($resolve, $reject) use ($promise) {
                // resolve/reject with result of DNS lookup
                $promise->then($resolve, $reject);
            },
            function ($_, $reject) use ($promise) {
                // cancellation should reject connection attempt
                $reject(new \RuntimeException('Connection attempt cancelled during DNS lookup'));

                // (try to) cancel pending DNS lookup
                if ($promise instanceof CancellablePromiseInterface) {
                    $promise->cancel();
                }
            }
        );
    }

    /** @internal */
    public function connect($ip, $port)
    {
        $promise = $this->connector->create($ip, $port);

        return new Promise\Promise(
            function ($resolve, $reject) use ($promise) {
                // resolve/reject with result of TCP/IP connection
                $promise->then($resolve, $reject);
            },
            function ($_, $reject) use ($promise) {
                // cancellation should reject connection attempt
                $reject(new \RuntimeException('Connection attempt cancelled during TCP/IP connection'));

                // forefully close TCP/IP connection if it completes despite cancellation
                $promise->then(function (Stream $stream) {
                    $stream->close();
                });

                // (try to) cancel pending TCP/IP connection
                if ($promise instanceof CancellablePromiseInterface) {
                    $promise->cancel();
                }
            }
        );
    }
}
