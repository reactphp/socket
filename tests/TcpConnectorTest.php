<?php

namespace React\Tests\SocketClient;

use React\EventLoop\StreamSelectLoop;
use React\Socket\Server;
use React\SocketClient\TcpConnector;
use Clue\React\Block;

class TcpConnectorTest extends TestCase
{
    /** @test */
    public function connectionToEmptyPortShouldFail()
    {
        $loop = new StreamSelectLoop();

        $connector = new TcpConnector($loop);
        $connector->create('127.0.0.1', 9999)
                ->then($this->expectCallableNever(), $this->expectCallableOnce());

        $loop->run();
    }

    /** @test */
    public function connectionToTcpServerShouldSucceed()
    {
        $loop = new StreamSelectLoop();

        $server = new Server($loop);
        $server->on('connection', $this->expectCallableOnce());
        $server->on('connection', function () use ($server, $loop) {
            $server->shutdown();
        });
        $server->listen(9999);

        $connector = new TcpConnector($loop);

        $stream = Block\await($connector->create('127.0.0.1', 9999), $loop);

        $this->assertInstanceOf('React\Stream\Stream', $stream);

        $stream->close();
    }

    /** @test */
    public function connectionToEmptyIp6PortShouldFail()
    {
        $loop = new StreamSelectLoop();

        $connector = new TcpConnector($loop);
        $connector
            ->create('::1', 9999)
            ->then($this->expectCallableNever(), $this->expectCallableOnce());

        $loop->run();
    }

    /** @test */
    public function connectionToIp6TcpServerShouldSucceed()
    {
        $loop = new StreamSelectLoop();

        $server = new Server($loop);
        $server->on('connection', $this->expectCallableOnce());
        $server->on('connection', array($server, 'shutdown'));
        $server->listen(9999, '::1');

        $connector = new TcpConnector($loop);

        $stream = Block\await($connector->create('::1', 9999), $loop);

        $this->assertInstanceOf('React\Stream\Stream', $stream);

        $stream->close();
    }

    /** @test */
    public function connectionToHostnameShouldFailImmediately()
    {
        $loop = $this->getMock('React\EventLoop\LoopInterface');

        $connector = new TcpConnector($loop);
        $connector->create('www.google.com', 80)->then(
            $this->expectCallableNever(),
            $this->expectCallableOnce()
        );
    }

    /** @test */
    public function connectionToInvalidAddressShouldFailImmediately()
    {
        $loop = $this->getMock('React\EventLoop\LoopInterface');

        $connector = new TcpConnector($loop);
        $connector->create('255.255.255.255', 12345678)->then(
            $this->expectCallableNever(),
            $this->expectCallableOnce()
        );
    }
}
