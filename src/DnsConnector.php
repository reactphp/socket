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
        if (strpos($uri, '://') === false) {
            $parts = parse_url('tcp://' . $uri);
            unset($parts['scheme']);
        } else {
            $parts = parse_url($uri);
        }

        if (!$parts || !isset($parts['host'])) {
            return Promise\reject(new \InvalidArgumentException('Given URI "' . $uri . '" is invalid'));
        }

        $that = $this;
        $host = trim($parts['host'], '[]');

        return $this
            ->resolveHostname($host)
            ->then(function ($ip) use ($that, $parts) {
                $uri = '';

                // prepend original scheme if known
                if (isset($parts['scheme'])) {
                    $uri .= $parts['scheme'] . '://';
                }

                if (strpos($ip, ':') !== false) {
                    // enclose IPv6 addresses in square brackets before appending port
                    $uri .= '[' . $ip . ']';
                } else {
                    $uri .= $ip;
                }

                // append original port if known
                if (isset($parts['port'])) {
                    $uri .= ':' . $parts['port'];
                }

                // append orignal path if known
                if (isset($parts['path'])) {
                    $uri .= $parts['path'];
                }

                // append original query if known
                if (isset($parts['query'])) {
                    $uri .= '?' . $parts['query'];
                }

                // append original fragment if known
                if (isset($parts['fragment'])) {
                    $uri .= '#' . $parts['fragment'];
                }

                return $that->connectTcp($uri);
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
