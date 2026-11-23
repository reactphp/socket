<?php

namespace React\Socket;

use React\Dns\Resolver\ResolverInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use function React\Promise\reject;

final class HappyEyeBallsConnector implements ConnectorInterface
{
    /** @var LoopInterface */
    private $loop;
    /** @var ConnectorInterface */
    private $connector;
    /** @var ResolverInterface */
    private $resolver;

    public function __construct(?LoopInterface $loop, ConnectorInterface $connector, ResolverInterface $resolver)
    {
        $this->loop = $loop ?? Loop::get();
        $this->connector = $connector;
        $this->resolver = $resolver;
    }

    public function connect(string $uri): PromiseInterface
    {
        $original = $uri;
        if (\strpos($uri, '://') === false) {
            $uri = 'tcp://' . $uri;
            $parts = \parse_url($uri);
            if ($parts !== false && isset($parts['scheme'])) {
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
            $this->loop,
            $this->connector,
            $this->resolver,
            $uri,
            $host,
            $parts
        );
        return $builder->connect();
    }
}
