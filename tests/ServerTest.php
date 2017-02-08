<?php

namespace React\Tests\Socket;

use React\EventLoop\StreamSelectLoop;
use React\Stream\Stream;
use React\Socket\Server;

class ServerTest extends TestCase
{
    private $loop;
    private $server;
    private $port;

    private function createLoop()
    {
        return new StreamSelectLoop();
    }

    /**
     * @covers React\Socket\Server::__construct
     * @covers React\Socket\Server::getAddress
     */
    public function setUp()
    {
        $this->loop = $this->createLoop();
        $this->server = new Server(0, $this->loop);

        $this->port = parse_url($this->server->getAddress(), PHP_URL_PORT);
    }

    /**
     * @covers React\Socket\Server::handleConnection
     */
    public function testConnection()
    {
        $client = stream_socket_client('tcp://localhost:'.$this->port);

        $this->server->on('connection', $this->expectCallableOnce());
        $this->loop->tick();
    }

    /**
     * @covers React\Socket\Server::handleConnection
     */
    public function testConnectionWithManyClients()
    {
        $client1 = stream_socket_client('tcp://localhost:'.$this->port);
        $client2 = stream_socket_client('tcp://localhost:'.$this->port);
        $client3 = stream_socket_client('tcp://localhost:'.$this->port);

        $this->server->on('connection', $this->expectCallableExactly(3));
        $this->loop->tick();
        $this->loop->tick();
        $this->loop->tick();
    }

    /**
     * @covers React\EventLoop\StreamSelectLoop::tick
     * @covers React\Socket\Connection::handleData
     */
    public function testDataEventWillNotBeEmittedWhenClientSendsNoData()
    {
        $client = stream_socket_client('tcp://localhost:'.$this->port);

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
     */
    public function testDataWillBeEmittedWithDataClientSends()
    {
        $client = stream_socket_client('tcp://localhost:'.$this->port);

        fwrite($client, "foo\n");

        $mock = $this->expectCallableOnceWith("foo\n");

        $this->server->on('connection', function ($conn) use ($mock) {
            $conn->on('data', $mock);
        });
        $this->loop->tick();
        $this->loop->tick();
    }

    /**
     * @covers React\EventLoop\StreamSelectLoop::tick
     * @covers React\Socket\Connection::handleData
     */
    public function testDataWillBeEmittedEvenWhenClientShutsDownAfterSending()
    {
        $client = stream_socket_client('tcp://localhost:' . $this->port);
        fwrite($client, "foo\n");
        stream_socket_shutdown($client, STREAM_SHUT_WR);

        $mock = $this->expectCallableOnceWith("foo\n");

        $this->server->on('connection', function ($conn) use ($mock) {
            $conn->on('data', $mock);
        });
        $this->loop->tick();
        $this->loop->tick();
    }

    public function testDataWillBeFragmentedToBufferSize()
    {
        $client = stream_socket_client('tcp://localhost:' . $this->port);

        fwrite($client, "Hello World!\n");

        $mock = $this->expectCallableOnceWith("He");

        $this->server->on('connection', function ($conn) use ($mock) {
            $conn->bufferSize = 2;
            $conn->on('data', $mock);
        });
        $this->loop->tick();
        $this->loop->tick();
    }

    public function testLoopWillEndWhenServerIsClosed()
    {
        // explicitly unset server because we already call close()
        $this->server->close();
        $this->server = null;

        $this->loop->run();
    }

    public function testCloseTwiceIsNoOp()
    {
        $this->server->close();
        $this->server->close();
    }

    public function testGetAddressAfterCloseReturnsNull()
    {
        $this->server->close();
        $this->assertNull($this->server->getAddress());
    }

    public function testLoopWillEndWhenServerIsClosedAfterSingleConnection()
    {
        $client = stream_socket_client('tcp://localhost:' . $this->port);

        // explicitly unset server because we only accept a single connection
        // and then already call close()
        $server = $this->server;
        $this->server = null;

        $server->on('connection', function ($conn) use ($server) {
            $conn->close();
            $server->close();
        });

        $this->loop->run();
    }

    public function testDataWillBeEmittedInMultipleChunksWhenClientSendsExcessiveAmounts()
    {
        $client = stream_socket_client('tcp://localhost:' . $this->port);
        $stream = new Stream($client, $this->loop);

        $bytes = 1024 * 1024;
        $stream->end(str_repeat('*', $bytes));

        $mock = $this->expectCallableOnce();

        // explicitly unset server because we only accept a single connection
        // and then already call close()
        $server = $this->server;
        $this->server = null;

        $received = 0;
        $server->on('connection', function ($conn) use ($mock, &$received, $server) {
            // count number of bytes received
            $conn->on('data', function ($data) use (&$received) {
                $received += strlen($data);
            });

            $conn->on('end', $mock);

            // do not await any further connections in order to let the loop terminate
            $server->close();
        });

        $this->loop->run();

        $this->assertEquals($bytes, $received);
    }

    /**
     * @covers React\EventLoop\StreamSelectLoop::tick
     */
    public function testConnectionDoesNotEndWhenClientDoesNotClose()
    {
        $client = stream_socket_client('tcp://localhost:'.$this->port);

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
     */
    public function testConnectionDoesEndWhenClientCloses()
    {
        $client = stream_socket_client('tcp://localhost:'.$this->port);

        fclose($client);

        $mock = $this->expectCallableOnce();

        $this->server->on('connection', function ($conn) use ($mock) {
            $conn->on('end', $mock);
        });
        $this->loop->tick();
        $this->loop->tick();
    }

    /**
     * @expectedException RuntimeException
     */
    public function testListenOnBusyPortThrows()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Windows supports listening on same port multiple times');
        }

        $another = new Server($this->port, $this->loop);
    }

    /**
     * @covers React\Socket\Server::close
     */
    public function tearDown()
    {
        if ($this->server) {
            $this->server->close();
        }
    }
}
