<?php

namespace React\Tests\Socket;

use React\Socket\Server;
use React\EventLoop\StreamSelectLoop;

class ServerTest extends TestCase
{
    private $loop;
    private $server;

    private function createLoop()
    {
        return new StreamSelectLoop();
    }

    /**
     * @covers React\Socket\Server::__construct
     * @covers React\Socket\Server::listen
     */
    public function setUp()
    {
        $this->loop = $this->createLoop();
        $this->server = new Server($this->loop);
        $this->server->listen('tcp://127.0.0.1:4321');
    }

    /**
     * @covers React\EventLoop\StreamSelectLoop::tick
     * @covers React\Socket\Server::handleConnection
     * @covers React\Socket\Server::createConnection
     * @covers React\Socket\Server::getAddress
     */
    public function testConnection()
    {
        $client = stream_socket_client($this->server->getAddress());

        $this->server->on('connection', $this->expectCallableOnce());
        $this->loop->tick();
    }

    /**
     * @covers React\EventLoop\StreamSelectLoop::tick
     * @covers React\Socket\Server::handleConnection
     * @covers React\Socket\Server::createConnection
     * @covers React\Socket\Server::getAddress
     */
    public function testConnectionWithManyClients()
    {
        $client1 = stream_socket_client($this->server->getAddress());
        $client2 = stream_socket_client($this->server->getAddress());
        $client3 = stream_socket_client($this->server->getAddress());

        $this->server->on('connection', $this->expectCallableExactly(3));
        $this->loop->tick();
        $this->loop->tick();
        $this->loop->tick();
    }

    /**
     * @covers React\EventLoop\StreamSelectLoop::tick
     * @covers React\Socket\Connection::handleData
     * @covers React\Socket\Server::getAddress
     */
    public function testDataWithNoData()
    {
        $client = stream_socket_client($this->server->getAddress());

        $mock = $this->expectCallableNever();

        $this->server->on('connection', function ($conn) use ($mock) {
            $conn->on('data', $mock);
        });
        $this->loop->tick();
        $this->loop->tick();
    }

    /**
     * @covers React\EventLoop\StreamSelectLoop::tick
     * @covers React\Socket\Connection::handleData
     * @covers React\Socket\Server::getAddress
     */
    public function testData()
    {
        $client = stream_socket_client($this->server->getAddress());

        fwrite($client, "foo\n");

        $mock = $this->createCallableMock();
        $mock
            ->expects($this->once())
            ->method('__invoke')
            ->with("foo\n");

        $this->server->on('connection', function ($conn) use ($mock) {
            $conn->on('data', $mock);
        });
        $this->loop->tick();
        $this->loop->tick();
    }

    /**
     * Test data sent from python language
     *
     * @covers React\EventLoop\StreamSelectLoop::tick
     * @covers React\Socket\Connection::handleData
     * @covers React\Socket\Server::getAddress
     */
    public function testDataSentFromPy()
    {
        $client = stream_socket_client($this->server->getAddress());
        fwrite($client, "foo\n");
        stream_socket_shutdown($client, STREAM_SHUT_WR);

        $mock = $this->createCallableMock();
        $mock
            ->expects($this->once())
            ->method('__invoke')
            ->with("foo\n");


        $this->server->on('connection', function ($conn) use ($mock) {
            $conn->on('data', $mock);
        });
        $this->loop->tick();
        $this->loop->tick();
    }

    /**
     * @covers React\Socket\Server::getAddress
     */
    public function testFragmentedMessage()
    {
        $client = stream_socket_client($this->server->getAddress());

        fwrite($client, "Hello World!\n");

        $mock = $this->createCallableMock();
        $mock
            ->expects($this->once())
            ->method('__invoke')
            ->with("He");

        $this->server->on('connection', function ($conn) use ($mock) {
            $conn->bufferSize = 2;
            $conn->on('data', $mock);
        });
        $this->loop->tick();
        $this->loop->tick();
    }

    /**
     * @covers React\EventLoop\StreamSelectLoop::tick
     * @covers React\Socket\Server::getAddress
     */
    public function testDisconnectWithoutDisconnect()
    {
        $client = stream_socket_client($this->server->getAddress());

        $mock = $this->expectCallableNever();

        $this->server->on('connection', function ($conn) use ($mock) {
            $conn->on('end', $mock);
        });
        $this->loop->tick();
        $this->loop->tick();
    }

    /**
     * @covers React\EventLoop\StreamSelectLoop::tick
     * @covers React\Socket\Connection::end
     * @covers React\Socket\Server::getAddress
     */
    public function testDisconnect()
    {
        $client = stream_socket_client($this->server->getAddress());

        fclose($client);

        $mock = $this->expectCallableOnce();

        $this->server->on('connection', function ($conn) use ($mock) {
            $conn->on('end', $mock);
        });
        $this->loop->tick();
        $this->loop->tick();
    }

    /**
     * @covers React\Socket\Server::shutdown
     */
    public function tearDown()
    {
        if ($this->server) {
            $this->server->shutdown();
        }
    }
}
