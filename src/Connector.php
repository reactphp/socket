<?php

namespace React\SocketClient;

use React\EventLoop\LoopInterface;
use React\Dns\Resolver\Resolver;
use React\Dns\Resolver\Factory;
use InvalidArgumentException;

/**
 * The `Connector` class implements the `ConnectorInterface` and allows you to
 * create any kind of streaming connections, such as plaintext TCP/IP, secure
 * TLS or local Unix connection streams.
 *
 * Under the hood, the `Connector` is implemented as a *higher-level facade*
 * or the lower-level connectors implemented in this package. This means it
 * also shares all of their features and implementation details.
 * If you want to typehint in your higher-level protocol implementation, you SHOULD
 * use the generic [`ConnectorInterface`](#connectorinterface) instead.
 *
 * @see ConnectorInterface for the base interface
 */
final class Connector implements ConnectorInterface
{
    private $tcp;
    private $tls;
    private $unix;

    public function __construct(LoopInterface $loop, ConnectorInterface $tcp = null)
    {
        if ($tcp === null) {
            $factory = new Factory();
            $resolver = $factory->create('8.8.8.8', $loop);

            $tcp = new DnsConnector(new TcpConnector($loop), $resolver);
        }

        $this->tcp = $tcp;
        $this->tls = new SecureConnector($tcp, $loop);
        $this->unix = new UnixConnector($loop);
    }

    public function connect($uri)
    {
        if (strpos($uri, '://') === false) {
            $uri = 'tcp://' . $uri;
        }

        $scheme = (string)substr($uri, 0, strpos($uri, '://'));

        if ($scheme === 'tcp') {
            return $this->tcp->connect($uri);
        } elseif ($scheme === 'tls') {
            return $this->tls->connect($uri);
        } elseif ($scheme === 'unix') {
            return $this->unix->connect($uri);
        } else{
            return Promise\reject(new InvalidArgumentException('Unknown URI scheme given'));
        }
    }
}

