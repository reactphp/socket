<?php

namespace React\Tests\SocketClient;

use React\EventLoop\StreamSelectLoop;
use React\Socket\Server;
use React\SocketClient\TcpConnector;

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
        $capturedStream = null;

        $loop = new StreamSelectLoop();

        $server = new Server($loop);
        $server->on('connection', $this->expectCallableOnce());
        $server->on('connection', function () use ($server, $loop) {
            $server->shutdown();
        });
        $server->listen(9999);

        $connector = new TcpConnector($loop);
        $connector->create('127.0.0.1', 9999)
                ->then(function ($stream) use (&$capturedStream) {
                    $capturedStream = $stream;
                    $stream->end();
                });

        $loop->run();

        $this->assertInstanceOf('React\Stream\Stream', $capturedStream);
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
        $capturedStream = null;

        $loop = new StreamSelectLoop();

        $server = new Server($loop);
        $server->on('connection', $this->expectCallableOnce());
        $server->on('connection', array($server, 'shutdown'));
        $server->listen(9999, '::1');

        $connector = new TcpConnector($loop);
        $connector
            ->create('::1', 9999)
            ->then(function ($stream) use (&$capturedStream) {
                $capturedStream = $stream;
                $stream->end();
            });

        $loop->run();

        $this->assertInstanceOf('React\Stream\Stream', $capturedStream);
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
}
