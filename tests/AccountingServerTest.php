<?php

namespace React\Tests\Socket;

use React\Socket\AccountingServer;
use React\Socket\Server;
use React\EventLoop\Factory;
use Clue\React\Block;

class AccountingServerTest extends TestCase
{
    public function testA()
    {
        $server = $this->getMockBuilder('React\Socket\ServerInterface')->getMock();
        $server = new AccountingServer($server);
    }

    public function testGetAddressWillBePassedThroughToTcpServer()
    {
        $tcp = $this->getMockBuilder('React\Socket\ServerInterface')->getMock();
        $tcp->expects($this->once())->method('getAddress')->willReturn('127.0.0.1:1234');

        $server = new AccountingServer($tcp);

        $this->assertEquals('127.0.0.1:1234', $server->getAddress());
    }

    public function testPauseWillBePassedThroughToTcpServer()
    {
        $tcp = $this->getMockBuilder('React\Socket\ServerInterface')->getMock();
        $tcp->expects($this->once())->method('pause');

        $server = new AccountingServer($tcp);

        $server->pause();
    }

    public function testResumeWillBePassedThroughToTcpServer()
    {
        $tcp = $this->getMockBuilder('React\Socket\ServerInterface')->getMock();
        $tcp->expects($this->once())->method('resume');

        $server = new AccountingServer($tcp);

        $server->resume();
    }

    public function testCloseWillBePassedThroughToTcpServer()
    {
        $tcp = $this->getMockBuilder('React\Socket\ServerInterface')->getMock();
        $tcp->expects($this->once())->method('close');

        $server = new AccountingServer($tcp);

        $server->close();
    }

    public function testSocketErrorWillBeForwarded()
    {
        $loop = $this->getMock('React\EventLoop\LoopInterface');

        $tcp = new Server(0, $loop);

        $server = new AccountingServer($tcp);

        $server->on('error', $this->expectCallableOnce());

        $tcp->emit('error', array(new \RuntimeException('test')));
    }

    public function testSocketConnectionWillBeForwarded()
    {
        $connection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $loop = $this->getMock('React\EventLoop\LoopInterface');

        $tcp = new Server(0, $loop);

        $server = new AccountingServer($tcp);
        $server->on('connection', $this->expectCallableOnceWith($connection));
        $server->on('error', $this->expectCallableNever());

        $tcp->emit('connection', array($connection));

        $this->assertEquals(array($connection), $server->getConnections());
    }

    public function testSocketConnectionWillBeClosedOnceLimitIsReached()
    {
        $first = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $first->expects($this->never())->method('close');
        $second = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $second->expects($this->once())->method('close');

        $loop = $this->getMock('React\EventLoop\LoopInterface');

        $tcp = new Server(0, $loop);

        $server = new AccountingServer($tcp, 1);
        $server->on('connection', $this->expectCallableOnceWith($first));
        $server->on('error', $this->expectCallableOnce());

        $tcp->emit('connection', array($first));
        $tcp->emit('connection', array($second));
    }

    public function testSocketDisconnectionWillRemoveFromList()
    {
        $loop = Factory::create();

        $tcp = new Server(0, $loop);

        $socket = stream_socket_client('tcp://' . $tcp->getAddress());
        fclose($socket);

        $server = new AccountingServer($tcp);
        $server->on('connection', $this->expectCallableOnce());
        $server->on('error', $this->expectCallableNever());

        Block\sleep(0.1, $loop);

        $this->assertEquals(array(), $server->getConnections());
    }
}
