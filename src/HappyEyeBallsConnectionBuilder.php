<?php

namespace React\Socket;

use React\Dns\Model\Message;
use React\Dns\Resolver\ResolverInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise;
use React\Promise\CancellablePromiseInterface;

/**
 * @internal
 */
final class HappyEyeBallsConnectionBuilder
{
    const CONNECT_INTERVAL = 0.1;
    const RESOLVE_WAIT = 0.5;

    public $loop;
    public $connector;
    public $resolver;
    public $uri;
    public $host;
    public $resolved = array(
        Message::TYPE_A    => false,
        Message::TYPE_AAAA => false,
    );
    public $resolverPromises = array();
    public $connectionPromises = array();
    public $connectQueue = array();
    public $timer;
    public $parts;
    public $ipsCount = 0;
    public $failureCount = 0;
    public $resolve;
    public $reject;

    public function __construct(LoopInterface $loop, ConnectorInterface $connector, ResolverInterface $resolver, $uri, $host, $parts)
    {
        $this->loop = $loop;
        $this->connector = $connector;
        $this->resolver = $resolver;
        $this->uri = $uri;
        $this->host = $host;
        $this->parts = $parts;
    }

    public function connect()
    {
        $that = $this;
        return new Promise\Promise(function ($resolve, $reject) use ($that) {
            $lookupResolve = function ($type) use ($that, $resolve, $reject) {
                return function (array $ips) use ($that, $type, $resolve, $reject) {
                    unset($that->resolverPromises[$type]);
                    $that->resolved[$type] = true;

                    $that->mixIpsIntoConnectQueue($ips);

                    if ($that->timer instanceof TimerInterface) {
                        return;
                    }

                    $that->check($resolve, $reject);
                };
            };

            $ipv4Deferred = null;
            $timer = null;
            $that->resolverPromises[Message::TYPE_AAAA] = $that->resolve(Message::TYPE_AAAA, $reject)->then($lookupResolve(Message::TYPE_AAAA))->then(function () use (&$ipv4Deferred) {
                if ($ipv4Deferred instanceof Promise\Deferred) {
                    $ipv4Deferred->resolve();
                }
            });
            $that->resolverPromises[Message::TYPE_A] = $that->resolve(Message::TYPE_A, $reject)->then(function ($ips) use ($that, &$ipv4Deferred, &$timer) {
                if ($that->resolved[Message::TYPE_AAAA] === true) {
                    return Promise\resolve($ips);
                }

                /**
                 * Delay A lookup by 50ms sending out connection to IPv4 addresses when IPv6 records haven't
                 * resolved yet as per RFC.
                 *
                 * @link https://tools.ietf.org/html/rfc8305#section-3
                 */
                $ipv4Deferred = new Promise\Deferred();
                $deferred = new Promise\Deferred();

                $timer = $that->loop->addTimer($that::RESOLVE_WAIT, function () use ($deferred, $ips) {
                    $ipv4Deferred = null;
                    $deferred->resolve($ips);
                });

                $ipv4Deferred->promise()->then(function () use ($that, &$timer, $deferred, $ips) {
                    $that->loop->cancelTimer($timer);
                    $deferred->resolve($ips);
                });

                return $deferred->promise();
            })->then($lookupResolve(Message::TYPE_A));
        }, function ($_, $reject) use ($that, &$timer) {
            $that->cleanUp();

            if ($timer instanceof TimerInterface) {
                $that->loop->cancelTimer($timer);
            }

            $reject(new \RuntimeException('Connection to ' . $that->uri . ' cancelled during DNS lookup'));

            $_ = $reject = null;
        });
    }

    /**
     * @internal
     */
    public function resolve($type, $reject)
    {
        $that = $this;
        return $that->resolver->resolveAll($that->host, $type)->then(null, function () use ($type, $reject, $that) {
            unset($that->resolverPromises[$type]);
            $that->resolved[$type] = true;

            if ($that->hasBeenResolved() === false) {
                return;
            }

            if ($that->ipsCount === 0) {
                $that->resolved = null;
                $that->resolverPromises = null;
                $reject(new \RuntimeException('Connection to ' . $that->uri . ' failed during DNS lookup: DNS error'));
            }
        });
    }

    /**
     * @internal
     */
    public function check($resolve, $reject)
    {
        if (\count($this->connectQueue) === 0 && $this->resolved[Message::TYPE_A] === true && $this->resolved[Message::TYPE_AAAA] === true && $this->timer instanceof TimerInterface) {
            $this->loop->cancelTimer($this->timer);
            $this->timer = null;
        }

        if (\count($this->connectQueue) === 0) {
            return;
        }

        $ip = \array_shift($this->connectQueue);

        $that = $this;
        $that->connectionPromises[$ip] = $this->attemptConnection($ip)->then(function ($connection) use ($that, $ip, $resolve) {
            unset($that->connectionPromises[$ip]);

            $that->cleanUp();

            $resolve($connection);
        }, function () use ($that, $ip, $resolve, $reject) {
            unset($that->connectionPromises[$ip]);

            $that->failureCount++;

            if ($that->hasBeenResolved() === false) {
                return;
            }

            if ($that->ipsCount === $that->failureCount) {
                $that->cleanUp();

                $reject(new \RuntimeException('All attempts to connect to "' . $that->host . '" have failed'));
            }
        });

        /**
         * As long as we haven't connected yet keep popping an IP address of the connect queue until one of them
         * succeeds or they all fail. We will wait 100ms between connection attempts as per RFC.
         *
         * @link https://tools.ietf.org/html/rfc8305#section-5
         */
        if ((\count($this->connectQueue) > 0 || ($this->resolved[Message::TYPE_A] === false || $this->resolved[Message::TYPE_AAAA] === false)) && $this->timer === null) {
            $this->timer = $this->loop->addPeriodicTimer(self::CONNECT_INTERVAL, function () use ($that, $resolve, $reject) {
                $that->check($resolve, $reject);
            });
        }
    }

    /**
     * @internal
     */
    public function attemptConnection($ip)
    {
        $promise = null;
        $that = $this;

        return new Promise\Promise(
            function ($resolve, $reject) use (&$promise, $that, $ip) {
                $uri = '';

                // prepend original scheme if known
                if (isset($that->parts['scheme'])) {
                    $uri .= $that->parts['scheme'] . '://';
                }

                if (\strpos($ip, ':') !== false) {
                    // enclose IPv6 addresses in square brackets before appending port
                    $uri .= '[' . $ip . ']';
                } else {
                    $uri .= $ip;
                }

                // append original port if known
                if (isset($that->parts['port'])) {
                    $uri .= ':' . $that->parts['port'];
                }

                // append orignal path if known
                if (isset($that->parts['path'])) {
                    $uri .= $that->parts['path'];
                }

                // append original query if known
                if (isset($that->parts['query'])) {
                    $uri .= '?' . $that->parts['query'];
                }

                // append original hostname as query if resolved via DNS and if
                // destination URI does not contain "hostname" query param already
                $args = array();
                \parse_str(isset($that->parts['query']) ? $that->parts['query'] : '', $args);
                if ($that->host !== $ip && !isset($args['hostname'])) {
                    $uri .= (isset($that->parts['query']) ? '&' : '?') . 'hostname=' . \rawurlencode($that->host);
                }

                // append original fragment if known
                if (isset($that->parts['fragment'])) {
                    $uri .= '#' . $that->parts['fragment'];
                }

                $promise = $that->connector->connect($uri);
                $promise->then($resolve, $reject);
            },
            function ($_, $reject) use (&$promise, $that) {
                // cancellation should reject connection attempt
                // (try to) cancel pending connection attempt
                $reject(new \RuntimeException('Connection to ' . $that->uri . ' cancelled during connection attempt'));

                if ($promise instanceof CancellablePromiseInterface) {
                    // overwrite callback arguments for PHP7+ only, so they do not show
                    // up in the Exception trace and do not cause a possible cyclic reference.
                    $_ = $reject = null;

                    $promise->cancel();
                    $promise = null;
                }
            }
        );
    }

    /**
     * @internal
     */
    public function cleanUp()
    {
        /** @var CancellablePromiseInterface $promise */
        foreach ($this->connectionPromises as $index => $connectionPromise) {
            if ($connectionPromise instanceof CancellablePromiseInterface) {
                $connectionPromise->cancel();
            }
        }

        /** @var CancellablePromiseInterface $promise */
        foreach ($this->resolverPromises as $index => $resolverPromise) {
            if ($resolverPromise instanceof CancellablePromiseInterface) {
                $resolverPromise->cancel();
            }
        }

        if ($this->timer instanceof TimerInterface) {
            $this->loop->cancelTimer($this->timer);
            $this->timer = null;
        }
    }

    /**
     * @internal
     */
    public function hasBeenResolved()
    {
        foreach ($this->resolved as $typeHasBeenResolved) {
            if ($typeHasBeenResolved === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Mixes an array of IP addresses into the connect queue in such a way they alternate when attempting to connect.
     * The goal behind it is first attempt to connect to IPv6, then to IPv4, then to IPv6 again until one of those
     * attempts succeeds.
     *
     * @link https://tools.ietf.org/html/rfc8305#section-4
     *
     * @internal
     */
    public function mixIpsIntoConnectQueue(array $ips)
    {
        $this->ipsCount += \count($ips);
        $connectQueueStash = $this->connectQueue;
        $this->connectQueue = array();
        while (\count($connectQueueStash) > 0 || \count($ips) > 0) {
            if (\count($ips) > 0) {
                $this->connectQueue[] = \array_shift($ips);
            }
            if (\count($connectQueueStash) > 0) {
                $this->connectQueue[] = \array_shift($connectQueueStash);
            }
        }
    }
}