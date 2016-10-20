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

    public function testGetPortWillBePassedThroughToTcpServer()
    {
        $tcp = $this->getMockBuilder('React\Socket\Server')->disableOriginalConstructor()->getMock();
        $tcp->expects($this->once())->method('getPort')->willReturn(1234);

        $loop = $this->getMock('React\EventLoop\LoopInterface');

        $server = new SecureServer($tcp, $loop, array());

        $this->assertEquals(1234, $server->getPort());
    }

    public function testShutdownWillBePassedThroughToTcpServer()
    {
        $tcp = $this->getMockBuilder('React\Socket\Server')->disableOriginalConstructor()->getMock();
        $tcp->expects($this->once())->method('shutdown');

        $loop = $this->getMock('React\EventLoop\LoopInterface');

        $server = new SecureServer($tcp, $loop, array());

        $server->shutdown();
    }
}
