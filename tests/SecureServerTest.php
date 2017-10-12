<?php

namespace React\Tests\Socket;

use React\Socket\SecureServer;
use React\Socket\TcpServer;

class SecureServerTest extends TestCase
{
    public function setUp()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('Not supported on your platform (outdated HHVM?)');
        }
    }

    public function testGetAddressWillBePassedThroughToTcpServer()
    {
        $tcp = $this->getMockBuilder('React\Socket\ServerInterface')->getMock();
        $tcp->expects($this->once())->method('getAddress')->willReturn('tcp://127.0.0.1:1234');

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $server = new SecureServer($tcp, $loop, array());

        $this->assertEquals('tls://127.0.0.1:1234', $server->getAddress());
    }

    public function testGetAddressWillReturnNullIfTcpServerReturnsNull()
    {
        $tcp = $this->getMockBuilder('React\Socket\ServerInterface')->getMock();
        $tcp->expects($this->once())->method('getAddress')->willReturn(null);

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $server = new SecureServer($tcp, $loop, array());

        $this->assertNull($server->getAddress());
    }

    public function testPauseWillBePassedThroughToTcpServer()
    {
        $tcp = $this->getMockBuilder('React\Socket\ServerInterface')->getMock();
        $tcp->expects($this->once())->method('pause');

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $server = new SecureServer($tcp, $loop, array());

        $server->pause();
    }

    public function testResumeWillBePassedThroughToTcpServer()
    {
        $tcp = $this->getMockBuilder('React\Socket\ServerInterface')->getMock();
        $tcp->expects($this->once())->method('resume');

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $server = new SecureServer($tcp, $loop, array());

        $server->resume();
    }

    public function testCloseWillBePassedThroughToTcpServer()
    {
        $tcp = $this->getMockBuilder('React\Socket\ServerInterface')->getMock();
        $tcp->expects($this->once())->method('close');

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $server = new SecureServer($tcp, $loop, array());

        $server->close();
    }

    public function testConnectionWillBeEndedWithErrorIfItIsNotAStream()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $tcp = new TcpServer(0, $loop);

        $connection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $connection->expects($this->once())->method('end');

        $server = new SecureServer($tcp, $loop, array());

        $server->on('error', $this->expectCallableOnce());

        $tcp->emit('connection', array($connection));
    }

    public function testSocketErrorWillBeForwarded()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $tcp = new TcpServer(0, $loop);

        $server = new SecureServer($tcp, $loop, array());

        $server->on('error', $this->expectCallableOnce());

        $tcp->emit('error', array(new \RuntimeException('test')));
    }
}
