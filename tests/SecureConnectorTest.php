<?php

namespace React\Tests\SocketClient;

use React\Promise;
use React\SocketClient\SecureConnector;

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

        $this->loop = $this->getMock('React\EventLoop\LoopInterface');
        $this->tcp = $this->getMock('React\SocketClient\ConnectorInterface');
        $this->connector = new SecureConnector($this->tcp, $this->loop);
    }

    public function testConnectionWillWaitForTcpConnection()
    {
        $pending = new Promise\Promise(function () { });
        $this->tcp->expects($this->once())->method('create')->with($this->equalTo('example.com'), $this->equalTo(80))->will($this->returnValue($pending));

        $promise = $this->connector->create('example.com', 80);

        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);
    }

    public function testCancelDuringTcpConnectionCancelsTcpConnection()
    {
        $pending = new Promise\Promise(function () { }, $this->expectCallableOnce());
        $this->tcp->expects($this->once())->method('create')->with($this->equalTo('example.com'), $this->equalTo(80))->will($this->returnValue($pending));

        $promise = $this->connector->create('example.com', 80);
        $promise->cancel();

        $promise->then($this->expectCallableNever(), $this->expectCallableOnce());
    }

    public function testCancelClosesStreamIfTcpResolvesDespiteCancellation()
    {
        $stream = $this->getMockBuilder('React\Stream\Stream')->disableOriginalConstructor()->setMethods(array('close'))->getMock();
        $stream->expects($this->once())->method('close');

        $pending = new Promise\Promise(function () { }, function ($resolve) use ($stream) {
            $resolve($stream);
        });

        $this->tcp->expects($this->once())->method('create')->with($this->equalTo('example.com'), $this->equalTo(80))->will($this->returnValue($pending));

        $promise = $this->connector->create('example.com', 80);
        $promise->cancel();

        $promise->then($this->expectCallableNever(), $this->expectCallableOnce());
    }
}
