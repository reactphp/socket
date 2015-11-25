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

    public function connect($uri)
    {
        $that = $this;

        $parts = parse_url('tcp://' . $uri);
        if (!$parts || !isset($parts['host'], $parts['port'])) {
            return Promise\reject(new \InvalidArgumentException('Given URI "' . $uri . '" is invalid'));
        }

        $host = trim($parts['host'], '[]');

        return $this
            ->resolveHostname($host)
            ->then(function ($ip) use ($that, $parts) {
                if (strpos($ip, ':') !== false) {
                    // enclose IPv6 addresses in square brackets before appending port
                    $ip = '[' . $ip . ']';
                }

                return $that->connectTcp(
                    $ip . ':' . $parts['port']
                );
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
    public function connectTcp($uri)
    {
        $promise = $this->connector->connect($uri);

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
