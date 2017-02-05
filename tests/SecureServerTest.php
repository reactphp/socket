<?php

namespace React\Tests\Socket;

use React\Socket\SecureServer;

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
        $tcp = $this->getMockBuilder('React\Socket\Server')->disableOriginalConstructor()->getMock();
        $tcp->expects($this->once())->method('getAddress')->willReturn('127.0.0.1:1234');
        $tcp->master = stream_socket_server('tcp://localhost:0');

        $loop = $this->getMock('React\EventLoop\LoopInterface');

        $server = new SecureServer($tcp, $loop, array());

        $this->assertEquals('127.0.0.1:1234', $server->getAddress());
    }

    public function testCloseWillBePassedThroughToTcpServer()
    {
        $tcp = $this->getMockBuilder('React\Socket\Server')->disableOriginalConstructor()->getMock();
        $tcp->expects($this->once())->method('close');
        $tcp->master = stream_socket_server('tcp://localhost:0');

        $loop = $this->getMock('React\EventLoop\LoopInterface');

        $server = new SecureServer($tcp, $loop, array());

        $server->close();
    }
}
