<?php

namespace React\Tests\Socket;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Socket\TimeoutConnector;

class TimeoutConnectorTest extends TestCase
{
    public function testConstructWithoutLoopAssignsLoopAutomatically()
    {
        $base = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();

        $connector = new TimeoutConnector($base, 0.01);

        $ref = new \ReflectionProperty($connector, 'loop');
        $ref->setAccessible(true);
        $loop = $ref->getValue($connector);

        $this->assertInstanceOf('React\EventLoop\LoopInterface', $loop);
    }

    public function testRejectsPromiseWithoutStartingTimerWhenWrappedConnectorReturnsRejectedPromise()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addTimer');
        $loop->expects($this->never())->method('cancelTimer');

        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('example.com:80')->willReturn(\React\Promise\reject(new \RuntimeException('Failed', 42)));

        $timeout = new TimeoutConnector($connector, 5.0, $loop);

        $promise = $timeout->connect('example.com:80');

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof \RuntimeException);
        $this->assertEquals('Failed', $exception->getMessage());
        $this->assertEquals(42, $exception->getCode());
    }

    public function testRejectsPromiseAfterCancellingTimerWhenWrappedConnectorReturnsPendingPromiseThatRejects()
    {
        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with(5.0, $this->anything())->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $deferred = new Deferred();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('example.com:80')->willReturn($deferred->promise());

        $timeout = new TimeoutConnector($connector, 5.0, $loop);

        $promise = $timeout->connect('example.com:80');

        $deferred->reject(new \RuntimeException('Failed', 42));

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof \RuntimeException);
        $this->assertEquals('Failed', $exception->getMessage());
        $this->assertEquals(42, $exception->getCode());
    }

    public function testResolvesPromiseWithoutStartingTimerWhenWrappedConnectorReturnsResolvedPromise()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addTimer');
        $loop->expects($this->never())->method('cancelTimer');

        $connection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('example.com:80')->willReturn(\React\Promise\resolve($connection));

        $timeout = new TimeoutConnector($connector, 5.0, $loop);

        $promise = $timeout->connect('example.com:80');

        $resolved = null;
        $promise->then(function ($value) use (&$resolved) {
            $resolved = $value;
        });

        $this->assertSame($connection, $resolved);
    }

    public function testResolvesPromiseAfterCancellingTimerWhenWrappedConnectorReturnsPendingPromiseThatResolves()
    {
        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with(5.0, $this->anything())->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $deferred = new Deferred();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('example.com:80')->willReturn($deferred->promise());

        $timeout = new TimeoutConnector($connector, 5.0, $loop);

        $promise = $timeout->connect('example.com:80');

        $connection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $deferred->resolve($connection);

        $resolved = null;
        $promise->then(function ($value) use (&$resolved) {
            $resolved = $value;
        });

        $this->assertSame($connection, $resolved);
    }

    public function testRejectsPromiseAndCancelsPendingConnectionWhenTimeoutTriggers()
    {
        $timerCallback = null;
        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with(0.01, $this->callback(function ($callback) use (&$timerCallback) {
            $timerCallback = $callback;
            return true;
        }))->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $cancelled = 0;
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('example.com:80')->willReturn(new Promise(function () { }, function () use (&$cancelled) {
            ++$cancelled;
            throw new \RuntimeException();
        }));

        $timeout = new TimeoutConnector($connector, 0.01, $loop);

        $promise = $timeout->connect('example.com:80');

        $this->assertEquals(0, $cancelled);

        $this->assertNotNull($timerCallback);
        $timerCallback();

        $this->assertEquals(1, $cancelled);

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof \RuntimeException);
        $this->assertEquals('Connection to example.com:80 timed out after 0.01 seconds (ETIMEDOUT)' , $exception->getMessage());
        $this->assertEquals(\defined('SOCKET_ETIMEDOUT') ? \SOCKET_ETIMEDOUT : 110, $exception->getCode());
    }

    public function testCancellingPromiseWillCancelPendingConnectionAndRejectPromise()
    {
        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->with(0.01, $this->anything())->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $cancelled = 0;
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('example.com:80')->willReturn(new Promise(function () { }, function () use (&$cancelled) {
            ++$cancelled;
            throw new \RuntimeException('Cancelled');
        }));

        $timeout = new TimeoutConnector($connector, 0.01, $loop);

        $promise = $timeout->connect('example.com:80');

        $this->assertEquals(0, $cancelled);

        assert(method_exists($promise, 'cancel'));
        $promise->cancel();

        $this->assertEquals(1, $cancelled);

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof \RuntimeException);
        $this->assertEquals('Cancelled', $exception->getMessage());
    }

    public function testRejectionDuringConnectionShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        gc_collect_cycles();

        $connection = new Deferred();
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('example.com:80')->willReturn($connection->promise());

        $timeout = new TimeoutConnector($connector, 0.01);

        $promise = $timeout->connect('example.com:80');
        $connection->reject(new \RuntimeException('Connection failed'));
        unset($promise, $connection);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testRejectionDueToTimeoutShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        gc_collect_cycles();

        $connection = new Deferred(function () {
            throw new \RuntimeException('Connection cancelled');
        });
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('example.com:80')->willReturn($connection->promise());

        $timeout = new TimeoutConnector($connector, 0);

        $promise = $timeout->connect('example.com:80');

        Loop::run();
        unset($promise, $connection);

        $this->assertEquals(0, gc_collect_cycles());
    }
}
