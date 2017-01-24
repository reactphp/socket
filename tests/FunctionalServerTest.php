<?php

namespace React\Tests\Socket;

use React\EventLoop\Factory;
use React\SocketClient\TcpConnector;
use React\Socket\Server;
use Clue\React\Block;
use React\Socket\ConnectionException;
use React\Socket\ConnectionInterface;

class FunctionalServerTest extends TestCase
{
    public function testEmitsConnectionForNewConnection()
    {
        $loop = Factory::create();

        $server = new Server($loop);
        $server->on('connection', $this->expectCallableOnce());
        $server->listen(0);
        $port = $server->getPort();

        $connector = new TcpConnector($loop);
        $promise = $connector->create('127.0.0.1', $port);

        $promise->then($this->expectCallableOnce());

        Block\sleep(0.1, $loop);
    }

    public function testEmitsConnectionWithRemoteIp()
    {
        $loop = Factory::create();

        $server = new Server($loop);
        $peer = null;
        $server->on('connection', function (ConnectionInterface $conn) use (&$peer) {
            $peer = $conn->getRemoteAddress();
        });
        $server->listen(0);
        $port = $server->getPort();

        $connector = new TcpConnector($loop);
        $promise = $connector->create('127.0.0.1', $port);

        $promise->then($this->expectCallableOnce());

        Block\sleep(0.1, $loop);

        $this->assertEquals('127.0.0.1', $peer);
    }

    public function testEmitsConnectionWithRemoteIpAfterConnectionIsClosedByPeer()
    {
        $loop = Factory::create();

        $server = new Server($loop);
        $peer = null;
        $server->on('connection', function (ConnectionInterface $conn) use (&$peer) {
            $conn->on('close', function () use ($conn, &$peer) {
                $peer = $conn->getRemoteAddress();
            });
        });
        $server->listen(0);
        $port = $server->getPort();

        $connector = new TcpConnector($loop);
        $promise = $connector->create('127.0.0.1', $port);

        $client = Block\await($promise, $loop, 0.1);
        $client->end();

        Block\sleep(0.1, $loop);

        $this->assertEquals('127.0.0.1', $peer);
    }

    public function testEmitsConnectionWithRemoteNullAddressAfterConnectionIsClosedLocally()
    {
        $loop = Factory::create();

        $server = new Server($loop);
        $peer = null;
        $server->on('connection', function (ConnectionInterface $conn) use (&$peer) {
            $conn->close();
            $peer = $conn->getRemoteAddress();
        });
        $server->listen(0);
        $port = $server->getPort();

        $connector = new TcpConnector($loop);
        $promise = $connector->create('127.0.0.1', $port);

        $promise->then($this->expectCallableOnce());

        Block\sleep(0.1, $loop);

        $this->assertNull($peer);
    }

    public function testEmitsConnectionEvenIfConnectionIsCancelled()
    {
        $loop = Factory::create();

        $server = new Server($loop);
        $server->on('connection', $this->expectCallableOnce());
        $server->listen(0);
        $port = $server->getPort();

        $connector = new TcpConnector($loop);
        $promise = $connector->create('127.0.0.1', $port);
        $promise->cancel();

        $promise->then(null, $this->expectCallableOnce());

        Block\sleep(0.1, $loop);
    }

    public function testEmitsConnectionForNewIpv6Connection()
    {
        $loop = Factory::create();

        $server = new Server($loop);
        $server->on('connection', $this->expectCallableOnce());
        try {
            $server->listen(0, '::1');
        } catch (ConnectionException $e) {
            $this->markTestSkipped('Unable to start IPv6 server socket (not available on your platform?)');
        }
        $port = $server->getPort();

        $connector = new TcpConnector($loop);
        $promise = $connector->create('::1', $port);

        $promise->then($this->expectCallableOnce());

        Block\sleep(0.1, $loop);
    }

    public function testEmitsConnectionWithRemoteIpv6()
    {
        $loop = Factory::create();

        $server = new Server($loop);
        $peer = null;
        $server->on('connection', function (ConnectionInterface $conn) use (&$peer) {
            $peer = $conn->getRemoteAddress();
        });
        try {
            $server->listen(0, '::1');
        } catch (ConnectionException $e) {
            $this->markTestSkipped('Unable to start IPv6 server socket (not available on your platform?)');
        }
        $port = $server->getPort();

        $connector = new TcpConnector($loop);
        $promise = $connector->create('::1', $port);

        $promise->then($this->expectCallableOnce());

        Block\sleep(0.1, $loop);

        $this->assertEquals('::1', $peer);
    }

    public function testAppliesContextOptionsToSocketStreamResource()
    {
        if (defined('HHVM_VERSION') && version_compare(HHVM_VERSION, '3.13', '<')) {
            // https://3v4l.org/hB4Tc
            $this->markTestSkipped('Not supported on legacy HHVM < 3.13');
        }

        $loop = Factory::create();

        $server = new Server($loop, array(
            'backlog' => 4
        ));

        $server->listen(0);

        $all = stream_context_get_options($server->master);

        $this->assertEquals(array('socket' => array('backlog' => 4)), $all);
    }
}
