<?php

namespace React\Tests\Socket;

use React\EventLoop\Loop;
use React\Socket\Connection;
use React\Socket\OpportunisticTlsConnection;
use React\Socket\StreamEncryption;

class OpportunisticTlsConnectionTest extends TestCase
{
    public function testGetRemoteAddressWillForwardCallToUnderlyingConnection()
    {
        $underlyingConnection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->getMock();
        $underlyingConnection->expects($this->once())->method('getRemoteAddress')->willReturn('[::1]:13');

        $connection = new OpportunisticTlsConnection($underlyingConnection, new StreamEncryption(Loop::get(), false), '');
        $this->assertSame('[::1]:13', $connection->getRemoteAddress());
    }

    public function testGetLocalAddressWillForwardCallToUnderlyingConnection()
    {
        $underlyingConnection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->getMock();
        $underlyingConnection->expects($this->once())->method('getLocalAddress')->willReturn('[::1]:13');

        $connection = new OpportunisticTlsConnection($underlyingConnection, new StreamEncryption(Loop::get(), false), '');
        $this->assertSame('[::1]:13', $connection->getLocalAddress());
    }

    public function testPauseWillForwardCallToUnderlyingConnection()
    {
        $underlyingConnection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->getMock();
        $underlyingConnection->expects($this->once())->method('pause');

        $connection = new OpportunisticTlsConnection($underlyingConnection, new StreamEncryption(Loop::get(), false), '');
        $connection->pause();
    }

    public function testResumeWillForwardCallToUnderlyingConnection()
    {
        $underlyingConnection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->getMock();
        $underlyingConnection->expects($this->once())->method('resume');

        $connection = new OpportunisticTlsConnection($underlyingConnection, new StreamEncryption(Loop::get(), false), '');
        $connection->resume();
    }

    public function testPipeWillForwardCallToUnderlyingConnection()
    {
        $underlyingConnection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->getMock();
        $underlyingConnection->expects($this->once())->method('pipe');

        $connection = new OpportunisticTlsConnection($underlyingConnection, new StreamEncryption(Loop::get(), false), '');
        $connection->pipe($underlyingConnection);
    }

    public function testCloseWillForwardCallToUnderlyingConnection()
    {
        $underlyingConnection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->getMock();
        $underlyingConnection->expects($this->once())->method('close');

        $connection = new OpportunisticTlsConnection($underlyingConnection, new StreamEncryption(Loop::get(), false), '');
        $connection->close();
    }

    public function testIsWritableWillForwardCallToUnderlyingConnection()
    {
        $underlyingConnection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->getMock();
        $underlyingConnection->expects($this->once())->method('isWritable')->willReturn(true);

        $connection = new OpportunisticTlsConnection($underlyingConnection, new StreamEncryption(Loop::get(), false), '');
        $this->assertTrue($connection->isWritable());
    }
}
