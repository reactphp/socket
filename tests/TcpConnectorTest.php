<?php

namespace React\Tests\SocketClient;

use React\EventLoop\StreamSelectLoop;
use React\Socket\Server;
use React\SocketClient\TcpConnector;
use Clue\React\Block;

class TcpConnectorTest extends TestCase
{
    const TIMEOUT = 0.1;

    /** @test */
    public function connectionToEmptyPortShouldFail()
    {
        $loop = new StreamSelectLoop();

        $connector = new TcpConnector($loop);
        $connector->connect('127.0.0.1:9999')
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

        $stream = Block\await($connector->connect('127.0.0.1:9999'), $loop, self::TIMEOUT);

        $this->assertInstanceOf('React\Stream\Stream', $stream);

        $stream->close();
    }

    /** @test */
    public function connectionToEmptyIp6PortShouldFail()
    {
        $loop = new StreamSelectLoop();

        $connector = new TcpConnector($loop);
        $connector
            ->connect('[::1]:9999')
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

        $stream = Block\await($connector->connect('[::1]:9999'), $loop, self::TIMEOUT);

        $this->assertInstanceOf('React\Stream\Stream', $stream);

        $stream->close();
    }

    /** @test */
    public function connectionToHostnameShouldFailImmediately()
    {
        $loop = $this->getMock('React\EventLoop\LoopInterface');

        $connector = new TcpConnector($loop);
        $connector->connect('www.google.com:80')->then(
            $this->expectCallableNever(),
            $this->expectCallableOnce()
        );
    }

    /** @test */
    public function connectionToInvalidAddressShouldFailImmediately()
    {
        $loop = $this->getMock('React\EventLoop\LoopInterface');

        $connector = new TcpConnector($loop);
        $connector->connect('255.255.255.255:12345678')->then(
            $this->expectCallableNever(),
            $this->expectCallableOnce()
        );
    }

    /** @test */
    public function cancellingConnectionShouldRejectPromise()
    {
        $loop = new StreamSelectLoop();
        $connector = new TcpConnector($loop);

        $server = new Server($loop);
        $server->listen(0);

        $promise = $connector->connect('127.0.0.1:' . $server->getPort());
        $promise->cancel();

        $this->setExpectedException('RuntimeException', 'Cancelled');
        Block\await($promise, $loop);
    }
}
