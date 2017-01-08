<?php

namespace React\Tests\Socket;

use React\EventLoop\Factory;
use React\SocketClient\TcpConnector;
use React\Socket\Server;
use Clue\React\Block;

class FunctionalServerTest extends TestCase
{
    public function testEmitsConnectionForNewConnection()
    {
        $loop = Factory::create();

        $server = new Server(0, $loop);
        $server->on('connection', $this->expectCallableOnce());
        $port = $server->getPort();

        $connector = new TcpConnector($loop);
        $promise = $connector->create('127.0.0.1', $port);

        $promise->then($this->expectCallableOnce());

        Block\sleep(0.1, $loop);
    }

    public function testEmitsConnectionEvenIfConnectionIsCancelled()
    {
        $loop = Factory::create();

        $server = new Server(0, $loop);
        $server->on('connection', $this->expectCallableOnce());
        $port = $server->getPort();

        $connector = new TcpConnector($loop);
        $promise = $connector->create('127.0.0.1', $port);
        $promise->cancel();

        $promise->then(null, $this->expectCallableOnce());

        Block\sleep(0.1, $loop);
    }
}
