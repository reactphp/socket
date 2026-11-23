<?php

declare(strict_types=1);

namespace React\Tests\Socket;

use React\Socket\Connector;
use React\Socket\ConnectorInterface;
use React\Socket\LimitingServer;
use React\Socket\ServerInterface;
use React\Socket\SocketServer;
use React\Socket\TimeoutConnector;
use React\Tests\Socket\Stub\ConnectionStub;
use React\Tests\Socket\Stub\ServerStub;

class TypeDeclarationsTest extends TestCase
{
    public function testConnectorRejectsInvalidUriTypeBeforeConnecting()
    {
        $base = $this->createMock(ConnectorInterface::class);
        $base->expects($this->never())->method('connect');
        $connector = new Connector(['tcp' => $base, 'dns' => false, 'timeout' => false]);

        $this->expectException(\TypeError::class);
        $connector->connect([]);
    }

    public function testServerRejectsInvalidUriTypeBeforeListening()
    {
        $this->expectException(\TypeError::class);
        new SocketServer(null);
    }

    public function testTimeoutRejectsNonNumericValue()
    {
        $connector = $this->createMock(ConnectorInterface::class);

        $this->expectException(\TypeError::class);
        new TimeoutConnector($connector, 'invalid');
    }

    public function testLimitingServerRejectsFractionalLimit()
    {
        $server = $this->createMock(ServerInterface::class);
        $server->expects($this->never())->method('on');

        $this->expectException(\TypeError::class);
        new LimitingServer($server, 1.5);
    }

    public function testCustomServerSupportsTypedInterfaceAndUnlimitedConnections()
    {
        $base = new ServerStub();
        $server = new LimitingServer($base, null);
        $connection = new ConnectionStub();
        $base->emit('connection', [$connection]);

        $this->assertSame([$connection], $server->getConnections());
        $this->assertSame('127.0.0.1:80', $server->getAddress());
        $this->assertSame('127.0.0.1', $connection->getRemoteAddress());
        $this->assertNull($connection->getLocalAddress());

        $server->pause();
        $server->resume();
        $server->close();
    }
}
