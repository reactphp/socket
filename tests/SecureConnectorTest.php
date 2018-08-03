<?php

namespace React\Tests\Socket;

use React\Promise;
use React\Socket\SecureConnector;

class SecureConnectorTest extends TestCase
{
    private $loop;
    private $tcp;
    private $connector;

    public function setUp()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('Not supported on your platform (outdated HHVM?)');
        }

        $this->loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $this->tcp = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $this->connector = new SecureConnector($this->tcp, $this->loop);
    }

    public function testConnectionWillWaitForTcpConnection()
    {
        $pending = new Promise\Promise(function () { });
        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('example.com:80'))->will($this->returnValue($pending));

        $promise = $this->connector->connect('example.com:80');

        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);
    }

    public function testConnectionWithCompleteUriWillBePassedThroughExpectForScheme()
    {
        $pending = new Promise\Promise(function () { });
        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('example.com:80/path?query#fragment'))->will($this->returnValue($pending));

        $this->connector->connect('tls://example.com:80/path?query#fragment');
    }

    public function testConnectionToInvalidSchemeWillReject()
    {
        $this->tcp->expects($this->never())->method('connect');

        $promise = $this->connector->connect('tcp://example.com:80');

        $promise->then(null, $this->expectCallableOnce());
    }

    /**
     * @expectedException RuntimeException
     * @expectedExceptionMessage Connection cancelled
     */
    public function testCancelDuringTcpConnectionCancelsTcpConnectionAndRejectsWithTcpRejection()
    {
        $pending = new Promise\Promise(function () { }, function () { throw new \RuntimeException('Connection cancelled'); });
        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('example.com:80'))->will($this->returnValue($pending));

        $promise = $this->connector->connect('example.com:80');
        $promise->cancel();

        $this->throwRejection($promise);
    }

    /**
     * @expectedException UnexpectedValueException
     * @expectedExceptionMessage Base connector does not use internal Connection class exposing stream resource
     */
    public function testConnectionWillBeClosedAndRejectedIfConnectionIsNoStream()
    {
        $connection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $connection->expects($this->once())->method('close');

        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('example.com:80'))->willReturn(Promise\resolve($connection));

        $promise = $this->connector->connect('example.com:80');

        $this->throwRejection($promise);
    }

    public function testStreamEncryptionWillBeEnabledAfterConnecting()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->getMock();

        $encryption = $this->getMockBuilder('React\Socket\StreamEncryption')->disableOriginalConstructor()->getMock();
        $encryption->expects($this->once())->method('enable')->with($connection)->willReturn(new \React\Promise\Promise(function () { }));

        $ref = new \ReflectionProperty($this->connector, 'streamEncryption');
        $ref->setAccessible(true);
        $ref->setValue($this->connector, $encryption);

        $pending = new Promise\Promise(function () { }, function () { throw new \RuntimeException('Connection cancelled'); });
        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('example.com:80'))->willReturn(Promise\resolve($connection));

        $promise = $this->connector->connect('example.com:80');
    }

    /**
     * @expectedException RuntimeException
     * @expectedExceptionMessage Connection to example.com:80 failed during TLS handshake: TLS error
     */
    public function testConnectionWillBeRejectedIfStreamEncryptionFailsAndClosesConnection()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->getMock();
        $connection->expects($this->once())->method('close');

        $encryption = $this->getMockBuilder('React\Socket\StreamEncryption')->disableOriginalConstructor()->getMock();
        $encryption->expects($this->once())->method('enable')->willReturn(Promise\reject(new \RuntimeException('TLS error')));

        $ref = new \ReflectionProperty($this->connector, 'streamEncryption');
        $ref->setAccessible(true);
        $ref->setValue($this->connector, $encryption);

        $pending = new Promise\Promise(function () { }, function () { throw new \RuntimeException('Connection cancelled'); });
        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('example.com:80'))->willReturn(Promise\resolve($connection));

        $promise = $this->connector->connect('example.com:80');

        $this->throwRejection($promise);
    }

    private function throwRejection($promise)
    {
        $ex = null;
        $promise->then(null, function ($e) use (&$ex) {
            $ex = $e;
        });

        throw $ex;
    }
}
