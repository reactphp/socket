<?php

namespace React\Tests\Socket;

use React\Promise\Promise;
use React\Socket\HappyEyeBallsConnectionBuilder;
use React\Dns\Model\Message;
use React\Promise\Deferred;

class HappyEyeBallsConnectionBuilderTest extends TestCase
{
    public function testConnectWillResolveTwiceViaResolver()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addTimer');

        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->never())->method('connect');

        $resolver = $this->getMockBuilder('React\Dns\Resolver\ResolverInterface')->getMock();
        $resolver->expects($this->exactly(2))->method('resolveAll')->withConsecutive(
            array('reactphp.org', Message::TYPE_AAAA),
            array('reactphp.org', Message::TYPE_A)
        )->willReturn(new Promise(function () { }));

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $builder->connect();
    }

    public function testConnectWillStartTimerWhenIpv4ResolvesAndIpv6IsPending()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer');
        $loop->expects($this->never())->method('cancelTimer');

        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->never())->method('connect');

        $resolver = $this->getMockBuilder('React\Dns\Resolver\ResolverInterface')->getMock();
        $resolver->expects($this->exactly(2))->method('resolveAll')->withConsecutive(
            array('reactphp.org', Message::TYPE_AAAA),
            array('reactphp.org', Message::TYPE_A)
        )->willReturnOnConsecutiveCalls(
            new Promise(function () { }),
            \React\Promise\resolve(array('127.0.0.1'))
        );

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $builder->connect();
    }

    public function testConnectWillStartConnectingWithoutTimerWhenIpv6ResolvesAndIpv4IsPending()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addTimer');

        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('tcp://[::1]:80?hostname=reactphp.org')->willReturn(new Promise(function () { }));

        $resolver = $this->getMockBuilder('React\Dns\Resolver\ResolverInterface')->getMock();
        $resolver->expects($this->exactly(2))->method('resolveAll')->withConsecutive(
            array('reactphp.org', Message::TYPE_AAAA),
            array('reactphp.org', Message::TYPE_A)
        )->willReturnOnConsecutiveCalls(
            \React\Promise\resolve(array('::1')),
            new Promise(function () { })
        );

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $builder->connect();
    }

    public function testConnectWillStartTimerAndCancelTimerWhenIpv4ResolvesAndIpv6ResolvesAfterwardsAndStartConnectingToIpv6()
    {
        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);
        $loop->expects($this->once())->method('addPeriodicTimer')->willReturn($this->getMockBuilder('React\EventLoop\TimerInterface')->getMock());

        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('tcp://[::1]:80?hostname=reactphp.org')->willReturn(new Promise(function () { }));

        $deferred = new Deferred();
        $resolver = $this->getMockBuilder('React\Dns\Resolver\ResolverInterface')->getMock();
        $resolver->expects($this->exactly(2))->method('resolveAll')->withConsecutive(
            array('reactphp.org', Message::TYPE_AAAA),
            array('reactphp.org', Message::TYPE_A)
        )->willReturnOnConsecutiveCalls(
            $deferred->promise(),
            \React\Promise\resolve(array('127.0.0.1'))
        );

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $builder->connect();
        $deferred->resolve(array('::1'));
    }

    public function testCancelConnectWillRejectPromiseAndCancelBothDnsLookups()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addTimer');

        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->never())->method('connect');

        $cancelled = 0;
        $resolver = $this->getMockBuilder('React\Dns\Resolver\ResolverInterface')->getMock();
        $resolver->expects($this->exactly(2))->method('resolveAll')->withConsecutive(
            array('reactphp.org', Message::TYPE_AAAA),
            array('reactphp.org', Message::TYPE_A)
        )->willReturnOnConsecutiveCalls(
            new Promise(function () { }, function () use (&$cancelled) {
                ++$cancelled;
                throw new \RuntimeException();
            }),
            new Promise(function () { }, function () use (&$cancelled) {
                ++$cancelled;
                throw new \RuntimeException();
            })
        );

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $promise = $builder->connect();
        $promise->cancel();

        $this->assertEquals(2, $cancelled);

        $exception = null;
        $promise->then(null, function ($e) use (&$exception) {
            $exception = $e;
        });

        $this->assertInstanceOf('RuntimeException', $exception);
        $this->assertEquals('Connection to tcp://reactphp.org:80 cancelled during DNS lookup', $exception->getMessage());
    }

    public function testCancelConnectWillRejectPromiseAndCancelPendingIpv6LookupAndCancelTimer()
    {
        $timer = $this->getMockBuilder('React\EventLoop\TimerInterface')->getMock();
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addTimer')->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->never())->method('connect');

        $resolver = $this->getMockBuilder('React\Dns\Resolver\ResolverInterface')->getMock();
        $resolver->expects($this->exactly(2))->method('resolveAll')->withConsecutive(
            array('reactphp.org', Message::TYPE_AAAA),
            array('reactphp.org', Message::TYPE_A)
        )->willReturnOnConsecutiveCalls(
            new Promise(function () { }, $this->expectCallableOnce()),
            \React\Promise\resolve(array('127.0.0.1'))
        );

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $promise = $builder->connect();
        $promise->cancel();

        $exception = null;
        $promise->then(null, function ($e) use (&$exception) {
            $exception = $e;
        });

        $this->assertInstanceOf('RuntimeException', $exception);
        $this->assertEquals('Connection to tcp://reactphp.org:80 cancelled during DNS lookup', $exception->getMessage());
    }

    public function testCancelConnectWillRejectPromiseAndCancelPendingIpv6ConnectionAttemptAndPendingIpv4Lookup()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addTimer');

        $cancelled = 0;
        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('tcp://[::1]:80?hostname=reactphp.org')->willReturn(new Promise(function () { }, function () use (&$cancelled) {
            ++$cancelled;
            throw new \RuntimeException('Ignored message');
        }));

        $resolver = $this->getMockBuilder('React\Dns\Resolver\ResolverInterface')->getMock();
        $resolver->expects($this->exactly(2))->method('resolveAll')->withConsecutive(
            array('reactphp.org', Message::TYPE_AAAA),
            array('reactphp.org', Message::TYPE_A)
        )->willReturnOnConsecutiveCalls(
            \React\Promise\resolve(array('::1')),
            new Promise(function () { }, $this->expectCallableOnce())
        );

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $promise = $builder->connect();
        $promise->cancel();

        $this->assertEquals(1, $cancelled);

        $exception = null;
        $promise->then(null, function ($e) use (&$exception) {
            $exception = $e;
        });

        $this->assertInstanceOf('RuntimeException', $exception);
        $this->assertEquals('Connection to tcp://reactphp.org:80 cancelled', $exception->getMessage());
    }

    public function testAttemptConnectionWillConnectViaConnectorToGivenIpWithPortAndHostnameFromUriParts()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('tcp://10.1.1.1:80?hostname=reactphp.org')->willReturn(new Promise(function () { }));

        $resolver = $this->getMockBuilder('React\Dns\Resolver\ResolverInterface')->getMock();
        $resolver->expects($this->never())->method('resolveAll');

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $builder->attemptConnection('10.1.1.1');
    }

    public function testAttemptConnectionWillConnectViaConnectorToGivenIpv6WithAllUriParts()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('tcp://[::1]:80/path?test=yes&hostname=reactphp.org#start')->willReturn(new Promise(function () { }));

        $resolver = $this->getMockBuilder('React\Dns\Resolver\ResolverInterface')->getMock();
        $resolver->expects($this->never())->method('resolveAll');

        $uri = 'tcp://reactphp.org:80/path?test=yes#start';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $builder->attemptConnection('::1');
    }
}
