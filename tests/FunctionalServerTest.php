<?php

namespace React\Tests\Socket;

use React\EventLoop\Factory;
use React\Socket\Server;
use React\Socket\ConnectionException;
use React\Socket\ConnectionInterface;
use React\Socket\ServerInterface;
use React\SocketClient\TcpConnector;
use Clue\React\Block;

class FunctionalServerTest extends TestCase
{
    public function testEmitsConnectionForNewConnection()
    {
        $loop = Factory::create();

        $server = new Server(0, $loop);
        $server->on('connection', $this->expectCallableOnce());
        $port = $this->getPort($server);

        $connector = new TcpConnector($loop);
        $promise = $connector->create('127.0.0.1', $port);

        $promise->then($this->expectCallableOnce());

        Block\sleep(0.1, $loop);
    }

    public function testEmitsConnectionWithRemoteIp()
    {
        $loop = Factory::create();

        $server = new Server(0, $loop);
        $peer = null;
        $server->on('connection', function (ConnectionInterface $conn) use (&$peer) {
            $peer = $conn->getRemoteAddress();
        });
        $port = $this->getPort($server);

        $connector = new TcpConnector($loop);
        $promise = $connector->create('127.0.0.1', $port);

        $promise->then($this->expectCallableOnce());

        Block\sleep(0.1, $loop);

        $this->assertContains('127.0.0.1:', $peer);
    }

    public function testEmitsConnectionWithRemoteIpAfterConnectionIsClosedByPeer()
    {
        $loop = Factory::create();

        $server = new Server(0, $loop);
        $peer = null;
        $server->on('connection', function (ConnectionInterface $conn) use (&$peer) {
            $conn->on('close', function () use ($conn, &$peer) {
                $peer = $conn->getRemoteAddress();
            });
        });
        $port = $this->getPort($server);

        $connector = new TcpConnector($loop);
        $promise = $connector->create('127.0.0.1', $port);

        $client = Block\await($promise, $loop, 0.1);
        $client->end();

        Block\sleep(0.1, $loop);

        $this->assertContains('127.0.0.1:', $peer);
    }

    public function testEmitsConnectionWithRemoteNullAddressAfterConnectionIsClosedLocally()
    {
        $loop = Factory::create();

        $server = new Server(0, $loop);
        $peer = null;
        $server->on('connection', function (ConnectionInterface $conn) use (&$peer) {
            $conn->close();
            $peer = $conn->getRemoteAddress();
        });
        $port = $this->getPort($server);

        $connector = new TcpConnector($loop);
        $promise = $connector->create('127.0.0.1', $port);

        $promise->then($this->expectCallableOnce());

        Block\sleep(0.1, $loop);

        $this->assertNull($peer);
    }

    public function testEmitsConnectionEvenIfConnectionIsCancelled()
    {
        $loop = Factory::create();

        $server = new Server(0, $loop);
        $server->on('connection', $this->expectCallableOnce());
        $port = $this->getPort($server);

        $connector = new TcpConnector($loop);
        $promise = $connector->create('127.0.0.1', $port);
        $promise->cancel();

        $promise->then(null, $this->expectCallableOnce());

        Block\sleep(0.1, $loop);
    }

    public function testEmitsConnectionForNewIpv6Connection()
    {
        $loop = Factory::create();

        try {
            $server = new Server('[::1]:0', $loop);
        } catch (ConnectionException $e) {
            $this->markTestSkipped('Unable to start IPv6 server socket (not available on your platform?)');
        }

        $server->on('connection', $this->expectCallableOnce());
        $port = $this->getPort($server);

        $connector = new TcpConnector($loop);
        $promise = $connector->create('::1', $port);

        $promise->then($this->expectCallableOnce());

        Block\sleep(0.1, $loop);
    }

    public function testEmitsConnectionWithRemoteIpv6()
    {
        $loop = Factory::create();

        try {
            $server = new Server('[::1]:0', $loop);
        } catch (ConnectionException $e) {
            $this->markTestSkipped('Unable to start IPv6 server socket (not available on your platform?)');
        }

        $peer = null;
        $server->on('connection', function (ConnectionInterface $conn) use (&$peer) {
            $peer = $conn->getRemoteAddress();
        });
        $port = $this->getPort($server);

        $connector = new TcpConnector($loop);
        $promise = $connector->create('::1', $port);

        $promise->then($this->expectCallableOnce());

        Block\sleep(0.1, $loop);

        $this->assertContains('[::1]:', $peer);
    }

    public function testAppliesContextOptionsToSocketStreamResource()
    {
        if (defined('HHVM_VERSION') && version_compare(HHVM_VERSION, '3.13', '<')) {
            // https://3v4l.org/hB4Tc
            $this->markTestSkipped('Not supported on legacy HHVM < 3.13');
        }

        $loop = Factory::create();

        $server = new Server(0, $loop, array(
            'backlog' => 4
        ));

        $all = stream_context_get_options($server->master);

        $this->assertEquals(array('socket' => array('backlog' => 4)), $all);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testFailsToListenOnInvalidUri()
    {
        $loop = Factory::create();

        new Server('///', $loop);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testFailsToListenOnUriWithoutPort()
    {
        $loop = Factory::create();

        new Server('127.0.0.1', $loop);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testFailsToListenOnUriWithWrongScheme()
    {
        $loop = Factory::create();

        new Server('udp://127.0.0.1:0', $loop);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testFailsToListenOnUriWIthHostname()
    {
        $loop = Factory::create();

        new Server('localhost:8080', $loop);
    }

    private function getPort(ServerInterface $server)
    {
        return parse_url('tcp://' . $server->getAddress(), PHP_URL_PORT);
    }
}
