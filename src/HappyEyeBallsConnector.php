<?php

namespace React\Socket;

use React\Dns\Resolver\ResolverInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use function React\Promise\reject;

final class HappyEyeBallsConnector implements ConnectorInterface
{
    private $connector;
    private $resolver;

    public function __construct(ConnectorInterface $connector, ResolverInterface $resolver)
    {
        $this->connector = $connector;
        $this->resolver = $resolver;
    }

    public function connect($uri)
    {
        $original = $uri;
        if (\strpos($uri, '://') === false) {
            $uri = 'tcp://' . $uri;
            $parts = \parse_url($uri);
            if (isset($parts['scheme'])) {
                unset($parts['scheme']);
            }
        } else {
            $parts = \parse_url($uri);
        }

        if (!$parts || !isset($parts['host'])) {
            return reject(new \InvalidArgumentException(
                'Given URI "' . $original . '" is invalid (EINVAL)',
                \defined('SOCKET_EINVAL') ? \SOCKET_EINVAL : (\defined('PCNTL_EINVAL') ? \PCNTL_EINVAL : 22)
            ));
        }

        $host = \trim($parts['host'], '[]');

        // skip DNS lookup / URI manipulation if this URI already contains an IP
        if (@\inet_pton($host) !== false) {
            return $this->connector->connect($original);
        }

        $builder = new HappyEyeBallsConnectionBuilder(
            $this->connector,
            $this->resolver,
            $uri,
            $host,
            $parts
        );
        return $builder->connect();
    }
}
