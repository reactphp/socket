<?php

namespace React\SocketClient;

use React\EventLoop\LoopInterface;
use React\Dns\Resolver\Resolver;

/**
 * Legacy Connector
 *
 * This class is not to be confused with the ConnectorInterface and should not
 * be used as a typehint.
 *
 * @deprecated Exists for BC only, consider using the newer DnsConnector instead
 * @see DnsConnector for the newer replacement
 * @see ConnectorInterface for the base interface
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
