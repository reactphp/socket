<?php

namespace React\Tests\Socket;

use Clue\React\Block;
use React\EventLoop\Factory;
use React\Socket\Connector;
use React\Socket\TcpServer;

class FunctionalConnectorTest extends TestCase
{
    const TIMEOUT = 1.0;

    /** @test */
    public function connectionToTcpServerShouldSucceedWithLocalhost()
    {
        $loop = Factory::create();

        $server = new TcpServer(9998, $loop);
        $server->on('connection', $this->expectCallableOnce());
        $server->on('connection', array($server, 'close'));

        $connector = new Connector($loop);

        $connection = Block\await($connector->connect('localhost:9998'), $loop, self::TIMEOUT);

        $this->assertInstanceOf('React\Socket\ConnectionInterface', $connection);

        $connection->close();
        $server->close();
    }
}
