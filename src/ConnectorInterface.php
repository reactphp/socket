<?php

namespace React\SocketClient;

interface ConnectorInterface
{
    public function connect($host, $port);
}
