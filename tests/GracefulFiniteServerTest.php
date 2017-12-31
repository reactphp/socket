<?php

namespace React\Tests\Socket;

use React\Socket\GracefulFiniteServer;
use React\Socket\TcpServer;

class GracefulFiniteServerTest extends TestCase
{
    public function testSocketConnectionWillBeForwarded()
    {
        $connection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $connection
            ->expects($this->never())
            ->method('on')
            ->with($this->equalTo('close'), $this->anything());


        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $fileName = tempnam('/tmp', 'test');
        unlink($fileName);
        $this->assertFalse(file_exists($fileName));

        $tcp = new TcpServer(0, $loop);
        new GracefulFiniteServer($tcp, $fileName);
        $tcp->emit('connection', array($connection));
    }

    public function testSocketConnectionWillBeClosed()
    {
        $connection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $connection
            ->expects($this->once())
            ->method('on')
            ->with($this->equalTo('close'), $this->anything());

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $fileName = tempnam('/tmp', 'test');
        $this->assertTrue(file_exists($fileName));

        $tcp = new TcpServer(0, $loop);
        new GracefulFiniteServer($tcp, $fileName);
        $tcp->emit('connection', array($connection));
        $this->assertFalse(file_exists($fileName));
    }
}
