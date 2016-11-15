<?php

namespace React\Tests\SocketClient;

use React\SocketClient\TimeoutConnector;
use React\Promise;
use React\EventLoop\Factory;

class TimeoutConnectorTest extends TestCase
{
    public function testRejectsOnTimeout()
    {
        $promise = new Promise\Promise(function () { });

        $connector = $this->getMock('React\SocketClient\ConnectorInterface');
        $connector->expects($this->once())->method('create')->with('google.com', 80)->will($this->returnValue($promise));

        $loop = Factory::create();

        $timeout = new TimeoutConnector($connector, 0.01, $loop);

        $timeout->create('google.com', 80)->then(
            $this->expectCallableNever(),
            $this->expectCallableOnce()
        );

        $loop->run();
    }

    public function testRejectsWhenConnectorRejects()
    {
        $promise = Promise\reject(new \RuntimeException());

        $connector = $this->getMock('React\SocketClient\ConnectorInterface');
        $connector->expects($this->once())->method('create')->with('google.com', 80)->will($this->returnValue($promise));

        $loop = Factory::create();

        $timeout = new TimeoutConnector($connector, 5.0, $loop);

        $timeout->create('google.com', 80)->then(
            $this->expectCallableNever(),
            $this->expectCallableOnce()
        );

        $loop->run();
    }

    public function testResolvesWhenConnectorResolves()
    {
        $promise = Promise\resolve();

        $connector = $this->getMock('React\SocketClient\ConnectorInterface');
        $connector->expects($this->once())->method('create')->with('google.com', 80)->will($this->returnValue($promise));

        $loop = Factory::create();

        $timeout = new TimeoutConnector($connector, 5.0, $loop);

        $timeout->create('google.com', 80)->then(
            $this->expectCallableOnce(),
            $this->expectCallableNever()
        );

        $loop->run();
    }

    public function testRejectsAndCancelsPendingPromiseOnTimeout()
    {
        $promise = new Promise\Promise(function () { }, $this->expectCallableOnce());

        $connector = $this->getMock('React\SocketClient\ConnectorInterface');
        $connector->expects($this->once())->method('create')->with('google.com', 80)->will($this->returnValue($promise));

        $loop = Factory::create();

        $timeout = new TimeoutConnector($connector, 0.01, $loop);

        $timeout->create('google.com', 80)->then(
            $this->expectCallableNever(),
            $this->expectCallableOnce()
        );

        $loop->run();
    }

    public function testCancelsPendingPromiseOnCancel()
    {
        $promise = new Promise\Promise(function () { }, $this->expectCallableOnce());

        $connector = $this->getMock('React\SocketClient\ConnectorInterface');
        $connector->expects($this->once())->method('create')->with('google.com', 80)->will($this->returnValue($promise));

        $loop = Factory::create();

        $timeout = new TimeoutConnector($connector, 0.01, $loop);

        $out = $timeout->create('google.com', 80);
        $out->cancel();

        $out->then($this->expectCallableNever(), $this->expectCallableOnce());
    }

    public function testCancelClosesStreamIfTcpResolvesDespiteCancellation()
    {
        $stream = $this->getMockBuilder('React\Stream\Stream')->disableOriginalConstructor()->setMethods(array('close'))->getMock();
        $stream->expects($this->once())->method('close');

        $promise = new Promise\Promise(function () { }, function ($resolve) use ($stream) {
            $resolve($stream);
        });

        $connector = $this->getMock('React\SocketClient\ConnectorInterface');
        $connector->expects($this->once())->method('create')->with('google.com', 80)->will($this->returnValue($promise));

        $loop = Factory::create();

        $timeout = new TimeoutConnector($connector, 0.01, $loop);

        $out = $timeout->create('google.com', 80);
        $out->cancel();

        $out->then($this->expectCallableNever(), $this->expectCallableOnce());
    }
}
