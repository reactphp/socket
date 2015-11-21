<?php

namespace React\SocketClient;

use React\EventLoop\LoopInterface;
use React\Dns\Resolver\Resolver;

/**
 * @deprecated Exists for BC only, consider using the newer DnsConnector instead
 */
class Connector implements ConnectorInterface
{
    private $connector;

    public function __construct(LoopInterface $loop, Resolver $resolver)
    {
        $this->connector = new DnsConnector(new TcpConnector($loop), $resolver);
    }

    public function create($host, $port)
    {
        return $this->connector->create($host, $port);
    }
}
