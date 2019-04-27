<?php

namespace React\Tests\Socket;

use React\Socket\Connection;

class ConnectionTest extends TestCase
{
    public function testCloseConnectionWillCloseSocketResource()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestSkipped('HHVM does not support socket operation on test memory stream');
        }

        $resource = fopen('php://memory', 'r+');
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connection = new Connection($resource, $loop);
        $connection->close();

        $this->assertFalse(is_resource($resource));
    }

    public function testCloseConnectionWillRemoveResourceFromLoopBeforeClosingResource()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestSkipped('HHVM does not support socket operation on test memory stream');
        }

        $resource = fopen('php://memory', 'r+');
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addWriteStream')->with($resource);

        $onRemove = null;
        $loop->expects($this->once())->method('removeWriteStream')->with($this->callback(function ($param) use (&$onRemove) {
            $onRemove = is_resource($param);
            return true;
        }));

        $connection = new Connection($resource, $loop);
        $connection->write('test');
        $connection->close();

        $this->assertTrue($onRemove);
        $this->assertFalse(is_resource($resource));
    }

    public function testGetStream()
    {
        $stream = fopen('php://memory', 'r+');
        $conn = new Connection($stream, $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock());
        $this->assertSame($stream, $conn->getStream());
    }

    public function testSetTLSEnabledFlag()
    {
        $stream = fopen('php://memory', 'r+');
        $conn = new Connection($stream, $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock());

        $reflection = new \ReflectionProperty($conn, 'encryptionEnabled');
        $reflection->setAccessible(true);

        $this->assertFalse($reflection->getValue($conn));
        $conn->setTLSEnabledFlag(true);
        $this->assertTrue($reflection->getValue($conn));
    }
}
