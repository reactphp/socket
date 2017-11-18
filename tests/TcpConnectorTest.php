<?php

namespace React\Tests\Socket;

use Clue\React\Block;
use React\EventLoop\Factory;
use React\Socket\ConnectionInterface;
use React\Socket\TcpConnector;
use React\Socket\TcpServer;

class TcpConnectorTest extends TestCase
{
    const TIMEOUT = 0.1;

    /** @test */
    public function connectionToEmptyPortShouldFail()
    {
        $loop = Factory::create();

        $connector = new TcpConnector($loop);
        $connector->connect('127.0.0.1:9999')
                ->then($this->expectCallableNever(), $this->expectCallableOnce());

        $loop->run();
    }

    /** @test */
    public function connectionToTcpServerShouldSucceed()
    {
        $loop = Factory::create();

        $server = new TcpServer(9999, $loop);
        $server->on('connection', $this->expectCallableOnce());
        $server->on('connection', array($server, 'close'));

        $connector = new TcpConnector($loop);

        $connection = Block\await($connector->connect('127.0.0.1:9999'), $loop, self::TIMEOUT);

        $this->assertInstanceOf('React\Socket\ConnectionInterface', $connection);

        $connection->close();
    }

    /** @test */
    public function connectionToTcpServerShouldSucceedWithRemoteAdressSameAsTarget()
    {
        $loop = Factory::create();

        $server = new TcpServer(9999, $loop);
        $server->on('connection', array($server, 'close'));

        $connector = new TcpConnector($loop);

        $connection = Block\await($connector->connect('127.0.0.1:9999'), $loop, self::TIMEOUT);
        /* @var $connection ConnectionInterface */

        $this->assertEquals('tcp://127.0.0.1:9999', $connection->getRemoteAddress());

        $connection->close();
    }

    /** @test */
    public function connectionToTcpServerShouldSucceedWithLocalAdressOnLocalhost()
    {
        $loop = Factory::create();

        $server = new TcpServer(9999, $loop);
        $server->on('connection', array($server, 'close'));

        $connector = new TcpConnector($loop);

        $connection = Block\await($connector->connect('127.0.0.1:9999'), $loop, self::TIMEOUT);
        /* @var $connection ConnectionInterface */

        $this->assertContains('tcp://127.0.0.1:', $connection->getLocalAddress());
        $this->assertNotEquals('tcp://127.0.0.1:9999', $connection->getLocalAddress());

        $connection->close();
    }

    /** @test */
    public function connectionToTcpServerShouldSucceedWithNullAddressesAfterConnectionClosed()
    {
        $loop = Factory::create();

        $server = new TcpServer(9999, $loop);
        $server->on('connection', array($server, 'close'));

        $connector = new TcpConnector($loop);

        $connection = Block\await($connector->connect('127.0.0.1:9999'), $loop, self::TIMEOUT);
        /* @var $connection ConnectionInterface */

        $connection->close();

        $this->assertNull($connection->getRemoteAddress());
        $this->assertNull($connection->getLocalAddress());
    }

    /** @test */
    public function connectionToEmptyIp6PortShouldFail()
    {
        $loop = Factory::create();

        $connector = new TcpConnector($loop);
        $connector
            ->connect('[::1]:9999')
            ->then($this->expectCallableNever(), $this->expectCallableOnce());

        $loop->run();
    }

    /** @test */
    public function connectionToIp6TcpServerShouldSucceed()
    {
        $loop = Factory::create();

        try {
            $server = new TcpServer('[::1]:9999', $loop);
        } catch (\Exception $e) {
            $this->markTestSkipped('Unable to start IPv6 server socket (IPv6 not supported on this system?)');
        }

        $server->on('connection', $this->expectCallableOnce());
        $server->on('connection', array($server, 'close'));

        $connector = new TcpConnector($loop);

        $connection = Block\await($connector->connect('[::1]:9999'), $loop, self::TIMEOUT);
        /* @var $connection ConnectionInterface */

        $this->assertEquals('tcp://[::1]:9999', $connection->getRemoteAddress());

        $this->assertContains('tcp://[::1]:', $connection->getLocalAddress());
        $this->assertNotEquals('tcp://[::1]:9999', $connection->getLocalAddress());

        $connection->close();
    }

    /** @test */
    public function connectionToHostnameShouldFailImmediately()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connector = new TcpConnector($loop);
        $connector->connect('www.google.com:80')->then(
            $this->expectCallableNever(),
            $this->expectCallableOnce()
        );
    }

    /** @test */
    public function connectionToInvalidPortShouldFailImmediately()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connector = new TcpConnector($loop);
        $connector->connect('255.255.255.255:12345678')->then(
            $this->expectCallableNever(),
            $this->expectCallableOnce()
        );
    }

    /** @test */
    public function connectionToInvalidSchemeShouldFailImmediately()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connector = new TcpConnector($loop);
        $connector->connect('tls://google.com:443')->then(
            $this->expectCallableNever(),
            $this->expectCallableOnce()
        );
    }

    /** @test */
    public function cancellingConnectionShouldRejectPromise()
    {
        $loop = Factory::create();
        $connector = new TcpConnector($loop);

        $server = new TcpServer(0, $loop);

        $promise = $connector->connect($server->getAddress());
        $promise->cancel();

        $this->setExpectedException('RuntimeException', 'Cancelled');
        Block\await($promise, $loop);
    }
}
