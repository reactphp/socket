<?php

namespace React\SocketClient;

use React\SocketClient\ConnectorInterface;
use React\EventLoop\LoopInterface;
use React\Promise\Timer;

class TimeoutConnector implements ConnectorInterface
{
    private $connector;
    private $timeout;
    private $loop;

    public function __construct(ConnectorInterface $connector, $timeout, LoopInterface $loop)
    {
        $this->connector = $connector;
        $this->timeout = $timeout;
        $this->loop = $loop;
    }

    public function create($host, $port)
    {
        return Timer\timeout($this->connector->create($host, $port), $this->timeout, $this->loop);
    }
}
