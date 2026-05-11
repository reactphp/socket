<?php

namespace React\Socket;

use React\Dns\Model\Message;
use React\Dns\Resolver\ResolverInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Promise\PromiseInterface;

/**
 * @internal
 */
final class HappyEyeBallsConnectionBuilder
{
    /**
     * As long as we haven't connected yet keep popping an IP address of the connect queue until one of them
     * succeeds or they all fail. We will wait 100ms between connection attempts as per RFC.
     *
     * @link https://tools.ietf.org/html/rfc8305#section-5
     */
    const CONNECTION_ATTEMPT_DELAY = 0.1;

    /**
     * Delay `A` lookup by 50ms sending out connection to IPv4 addresses when IPv6 records haven't
     * resolved yet as per RFC.
     *
     * @link https://tools.ietf.org/html/rfc8305#section-3
     */
    const RESOLUTION_DELAY = 0.05;

    public $connector;
    public $resolver;
    public $uri;
    public $host;
    public $resolved = [
        Message::TYPE_A    => false,
        Message::TYPE_AAAA => false,
    ];
    public $resolverPromises = [];
    public $connectionPromises = [];
    public $connectQueue = [];
    public $nextAttemptTimer;
    public $parts;
    public $ipsCount = 0;
    public $failureCount = 0;
    public $resolve;
    public $reject;

    public $lastErrorFamily;
    public $lastError6;
    public $lastError4;

    public function __construct(ConnectorInterface $connector, ResolverInterface $resolver, $uri, $host, $parts)
    {
        $this->connector = $connector;
        $this->resolver = $resolver;
        $this->uri = $uri;
        $this->host = $host;
        $this->parts = $parts;
    }

    public function connect()
    {
        return new Promise(function ($resolve, $reject) {
            $lookupResolve = function ($type) use ($resolve, $reject) {
                return function (array $ips) use ($type, $resolve, $reject) {
                    unset($this->resolverPromises[$type]);
                    $this->resolved[$type] = true;

                    $this->mixIpsIntoConnectQueue($ips);

                    // start next connection attempt if not already awaiting next
                    if ($this->nextAttemptTimer === null && $this->connectQueue) {
                        $this->check($resolve, $reject);
                    }
                };
            };

            $this->resolverPromises[Message::TYPE_AAAA] = $this->resolve(Message::TYPE_AAAA, $reject)->then($lookupResolve(Message::TYPE_AAAA));
            $this->resolverPromises[Message::TYPE_A] = $this->resolve(Message::TYPE_A, $reject)->then(function (array $ips) {
                // happy path: IPv6 has resolved already (or could not resolve), continue with IPv4 addresses
                if ($this->resolved[Message::TYPE_AAAA] === true || !$ips) {
                    return $ips;
                }

                // Otherwise delay processing IPv4 lookup until short timer passes or IPv6 resolves in the meantime
                $deferred = new Deferred(function () use (&$ips) {
                    // discard all IPv4 addresses if cancelled
                    $ips = [];
                });
                $timer = Loop::addTimer($this::RESOLUTION_DELAY, function () use ($deferred, $ips) {
                    $deferred->resolve($ips);
                });

                $this->resolverPromises[Message::TYPE_AAAA]->then(function () use ($timer, $deferred, &$ips) {
                    Loop::cancelTimer($timer);
                    $deferred->resolve($ips);
                });

                return $deferred->promise();
            })->then($lookupResolve(Message::TYPE_A));
        }, function ($_, $reject) {
            $reject(new \RuntimeException(
                'Connection to ' . $this->uri . ' cancelled' . (!$this->connectionPromises ? ' during DNS lookup' : '') . ' (ECONNABORTED)',
                \defined('SOCKET_ECONNABORTED') ? \SOCKET_ECONNABORTED : 103
            ));
            $_ = $reject = null;

            $this->cleanUp();
        });
    }

    /**
     * @internal
     * @param int      $type   DNS query type
     * @param callable $reject
     * @return \React\Promise\PromiseInterface<string[]> Returns a promise that
     *     always resolves with a list of IP addresses on success or an empty
     *     list on error.
     */
    public function resolve($type, $reject)
    {
        return $this->resolver->resolveAll($this->host, $type)->then(null, function (\Exception $e) use ($type, $reject) {
            unset($this->resolverPromises[$type]);
            $this->resolved[$type] = true;

            if ($type === Message::TYPE_A) {
                $this->lastError4 = $e->getMessage();
                $this->lastErrorFamily = 4;
            } else {
                $this->lastError6 = $e->getMessage();
                $this->lastErrorFamily = 6;
            }

            // cancel next attempt timer when there are no more IPs to connect to anymore
            if ($this->nextAttemptTimer !== null && !$this->connectQueue) {
                Loop::cancelTimer($this->nextAttemptTimer);
                $this->nextAttemptTimer = null;
            }

            if ($this->hasBeenResolved() && $this->ipsCount === 0) {
                $reject(new \RuntimeException(
                    $this->error(),
                    0,
                    $e
                ));
            }

            // Exception already handled above, so don't throw an unhandled rejection here
            return [];
        });
    }

    /**
     * @internal
     */
    public function check($resolve, $reject)
    {
        $ip = \array_shift($this->connectQueue);

        // start connection attempt and remember array position to later unset again
        $this->connectionPromises[] = $this->attemptConnection($ip);
        \end($this->connectionPromises);
        $index = \key($this->connectionPromises);

        $this->connectionPromises[$index]->then(function ($connection) use ($index, $resolve) {
            unset($this->connectionPromises[$index]);

            $this->cleanUp();

            $resolve($connection);
        }, function (\Exception $e) use ($index, $ip, $resolve, $reject) {
            unset($this->connectionPromises[$index]);

            $this->failureCount++;

            $message = \preg_replace('/^(Connection to [^ ]+)[&?]hostname=[^ &]+/', '$1', $e->getMessage());
            if (\strpos($ip, ':') === false) {
                $this->lastError4 = $message;
                $this->lastErrorFamily = 4;
            } else {
                $this->lastError6 = $message;
                $this->lastErrorFamily = 6;
            }

            // start next connection attempt immediately on error
            if ($this->connectQueue) {
                if ($this->nextAttemptTimer !== null) {
                    Loop::cancelTimer($this->nextAttemptTimer);
                    $this->nextAttemptTimer = null;
                }

                $this->check($resolve, $reject);
            }

            if ($this->hasBeenResolved() === false) {
                return;
            }

            if ($this->ipsCount === $this->failureCount) {
                $this->cleanUp();

                $reject(new \RuntimeException(
                    $this->error(),
                    $e->getCode(),
                    $e
                ));
            }
        });

        // Allow next connection attempt in 100ms: https://tools.ietf.org/html/rfc8305#section-5
        // Only start timer when more IPs are queued or when DNS query is still pending (might add more IPs)
        if ($this->nextAttemptTimer === null && (\count($this->connectQueue) > 0 || $this->resolved[Message::TYPE_A] === false || $this->resolved[Message::TYPE_AAAA] === false)) {
            $this->nextAttemptTimer = Loop::addTimer(self::CONNECTION_ATTEMPT_DELAY, function () use ($resolve, $reject) {
                $this->nextAttemptTimer = null;

                if ($this->connectQueue) {
                    $this->check($resolve, $reject);
                }
            });
        }
    }

    /**
     * @internal
     */
    public function attemptConnection($ip)
    {
        $uri = Connector::uri($this->parts, $this->host, $ip);

        return $this->connector->connect($uri);
    }

    /**
     * @internal
     */
    public function cleanUp()
    {
        // clear list of outstanding IPs to avoid creating new connections
        $this->connectQueue = [];

        // cancel pending connection attempts
        foreach ($this->connectionPromises as $connectionPromise) {
            if ($connectionPromise instanceof PromiseInterface && \method_exists($connectionPromise, 'cancel')) {
                $connectionPromise->cancel();
            }
        }

        // cancel pending DNS resolution (cancel IPv4 first in case it is awaiting IPv6 resolution delay)
        foreach (\array_reverse($this->resolverPromises) as $resolverPromise) {
            if ($resolverPromise instanceof PromiseInterface && \method_exists($resolverPromise, 'cancel')) {
                $resolverPromise->cancel();
            }
        }

        if ($this->nextAttemptTimer instanceof TimerInterface) {
            Loop::cancelTimer($this->nextAttemptTimer);
            $this->nextAttemptTimer = null;
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
        \shuffle($ips);
        $this->ipsCount += \count($ips);
        $connectQueueStash = $this->connectQueue;
        $this->connectQueue = [];
        while (\count($connectQueueStash) > 0 || \count($ips) > 0) {
            if (\count($ips) > 0) {
                $this->connectQueue[] = \array_shift($ips);
            }
            if (\count($connectQueueStash) > 0) {
                $this->connectQueue[] = \array_shift($connectQueueStash);
            }
        }
    }

    /**
     * @internal
     * @return string
     */
    public function error()
    {
        if ($this->lastError4 === $this->lastError6) {
            $message = $this->lastError6;
        } elseif ($this->lastErrorFamily === 6) {
            $message = 'Last error for IPv6: ' . $this->lastError6 . '. Previous error for IPv4: ' . $this->lastError4;
        } else {
            $message = 'Last error for IPv4: ' . $this->lastError4 . '. Previous error for IPv6: ' . $this->lastError6;
        }

        if ($this->hasBeenResolved() && $this->ipsCount === 0) {
            if ($this->lastError6 === $this->lastError4) {
                $message = ' during DNS lookup: ' . $this->lastError6;
            } else {
                $message = ' during DNS lookup. ' . $message;
            }
        } else {
            $message = ': ' . $message;
        }

        return 'Connection to ' . $this->uri . ' failed'  . $message;
    }
}
