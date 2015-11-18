<?php

namespace React\SocketClient;

use React\EventLoop\LoopInterface;
use React\Dns\Resolver\Resolver;
use React\Stream\Stream;
use React\Promise;
use React\Promise\Deferred;

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
        $connector = $this->connector;

        return $this
            ->resolveHostname($host)
            ->then(function ($address) use ($connector, $port) {
                return $connector->create($address, $port);
            });
    }

    private function resolveHostname($host)
    {
        if (false !== filter_var($host, FILTER_VALIDATE_IP)) {
            return Promise\resolve($host);
        }

        return $this->resolver->resolve($host);
    }
}
