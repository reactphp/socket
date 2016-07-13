<?php

namespace React\Tests\Socket;

use React\Socket\Connection;
use React\Socket\ConnectionFactory;
use React\Socket\ConnectionInterface;
use React\Socket\Server;
use React\EventLoop\StreamSelectLoop;
use React\Tests\Socket\Stub\ConnectionStub;

class ConnectionFactoryTest extends TestCase
{

    /**
     * @covers React\Socket\ConnectionFactory::createConnection
     */
    public function testCreateConnection()
    {
        $loop   = new StreamSelectLoop();

        $factory = new ConnectionFactory([
            'MEMORY' => ConnectionStub::class,
            'STDIO' => Connection::class,
        ]);

        $this->assertInstanceOf(ConnectionInterface::class, $factory->createConnection(fopen('php://memory', 'w+'), $loop));
        $this->assertInstanceOf(ConnectionStub::class, $factory->createConnection(fopen('php://memory', 'w+'), $loop));

        $this->assertInstanceOf(ConnectionInterface::class, $factory->createConnection(fopen(__FILE__, 'r'), $loop));

        $this->assertInstanceOf(Connection::class, $factory->createConnection(fopen(__FILE__, 'r'), $loop));

    }
}
