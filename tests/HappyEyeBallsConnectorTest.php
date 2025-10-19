<?php

namespace React\Tests\Socket;

use React\Dns\Model\Message;
use React\Dns\Resolver\ResolverInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Socket\ConnectionInterface;
use React\Socket\ConnectorInterface;
use React\Socket\HappyEyeBallsConnector;
use function React\Promise\reject;
use function React\Promise\resolve;

class HappyEyeBallsConnectorTest extends TestCase
{
    private $loop;
    private $tcp;
    private $resolver;
    private $connector;
    private $connection;

    /**
     * @before
     */
    public function setUpMocks()
    {
        $this->loop = new TimerSpeedUpEventLoop(new StreamSelectLoop());
        $this->tcp = $this->createMock(ConnectorInterface::class);
        $this->resolver = $this->createMock(ResolverInterface::class);
        $this->connection = $this->createMock(ConnectionInterface::class);

        $this->connector = new HappyEyeBallsConnector($this->loop, $this->tcp, $this->resolver);
    }

    public function testConstructWithoutLoopAssignsLoopAutomatically()
    {
        $connector = new HappyEyeBallsConnector(null, $this->tcp, $this->resolver);

        $ref = new \ReflectionProperty($connector, 'loop');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $loop = $ref->getValue($connector);

        $this->assertInstanceOf(LoopInterface::class, $loop);
    }

    public function testHappyFlow()
    {
        $first = new Deferred();
        $this->resolver->expects($this->exactly(2))->method('resolveAll')->with('example.com', $this->anything())->willReturn($first->promise());
        $connection = $this->createMock(ConnectionInterface::class);
        $this->tcp->expects($this->exactly(1))->method('connect')->with('1.2.3.4:80?hostname=example.com')->willReturn(resolve($connection));

        $promise = $this->connector->connect('example.com:80');
        $first->resolve(['1.2.3.4']);

        $resolvedConnection = null;
        $promise->then(function ($value) use (&$resolvedConnection) {
            $resolvedConnection = $value;
        });

        self::assertSame($connection, $resolvedConnection);
    }

    public function testThatAnyOtherPendingConnectionAttemptsWillBeCanceledOnceAConnectionHasBeenEstablished()
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $lookupAttempts = [
            reject(new \Exception('error')),
            resolve(['1.2.3.4', '5.6.7.8', '9.10.11.12']),
        ];
        $connectionAttempts = [
            new Promise(function () {}, $this->expectCallableOnce()),
            resolve($connection),
            new Promise(function () {}, $this->expectCallableNever()),
        ];
        $this->resolver->expects($this->exactly(2))->method('resolveAll')->with('example.com', $this->anything())->willReturnCallback(function () use (&$lookupAttempts) {
            return array_shift($lookupAttempts);
        });
        $this->tcp->expects($this->exactly(2))->method('connect')->with($this->isType('string'))->willReturnCallback(function () use (&$connectionAttempts) {
            return array_shift($connectionAttempts);
        });

        $promise = $this->connector->connect('example.com:80');

        $this->loop->run();
        $resolvedConnection = null;
        $promise->then(function ($value) use (&$resolvedConnection) {
            $resolvedConnection = $value;
        });

        self::assertSame($connection, $resolvedConnection);
    }

    public function testPassByResolverIfGivenIp()
    {
        $this->resolver->expects($this->never())->method('resolveAll');
        $this->tcp->expects($this->once())->method('connect')->with('127.0.0.1:80')->willReturn(resolve(null));

        $this->connector->connect('127.0.0.1:80');

        $this->loop->run();
    }

    public function testPassByResolverIfGivenIpv6()
    {
        $this->resolver->expects($this->never())->method('resolveAll');
        $this->tcp->expects($this->once())->method('connect')->with('[::1]:80')->willReturn(reject(new \Exception('reject')));

        $promise = $this->connector->connect('[::1]:80');

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection

        $this->loop->run();
    }

    public function testPassThroughResolverIfGivenHost()
    {
        $this->resolver->expects($this->exactly(2))->method('resolveAll')->with('google.com', $this->anything())->willReturn(resolve(['1.2.3.4']));
        $this->tcp->expects($this->exactly(2))->method('connect')->with('1.2.3.4:80?hostname=google.com')->willReturn(reject(new \Exception('reject')));

        $promise = $this->connector->connect('google.com:80');

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection

        $this->loop->run();
    }

    public function testPassThroughResolverIfGivenHostWhichResolvesToIpv6()
    {
        $this->resolver->expects($this->exactly(2))->method('resolveAll')->with('google.com', $this->anything())->willReturn(resolve(['::1']));
        $this->tcp->expects($this->exactly(2))->method('connect')->with('[::1]:80?hostname=google.com')->willReturn(reject(new \Exception('reject')));

        $promise = $this->connector->connect('google.com:80');

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection

        $this->loop->run();
    }

    public function testPassByResolverIfGivenCompleteUri()
    {
        $this->resolver->expects($this->never())->method('resolveAll');
        $this->tcp->expects($this->once())->method('connect')->with('scheme://127.0.0.1:80/path?query#fragment')->willReturn(reject(new \Exception('reject')));

        $promise = $this->connector->connect('scheme://127.0.0.1:80/path?query#fragment');

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection

        $this->loop->run();
    }

    public function testPassThroughResolverIfGivenCompleteUri()
    {
        $this->resolver->expects($this->exactly(2))->method('resolveAll')->with('google.com', $this->anything())->willReturn(resolve(['1.2.3.4']));
        $this->tcp->expects($this->exactly(2))->method('connect')->with('scheme://1.2.3.4:80/path?query&hostname=google.com#fragment')->willReturn(reject(new \Exception('reject')));

        $promise = $this->connector->connect('scheme://google.com:80/path?query#fragment');

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection

        $this->loop->run();
    }

    public function testPassThroughResolverIfGivenExplicitHost()
    {
        $this->resolver->expects($this->exactly(2))->method('resolveAll')->with('google.com', $this->anything())->willReturn(resolve(['1.2.3.4']));
        $this->tcp->expects($this->exactly(2))->method('connect')->with('scheme://1.2.3.4:80/?hostname=google.de')->willReturn(reject(new \Exception('reject')));

        $promise = $this->connector->connect('scheme://google.com:80/?hostname=google.de');

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection

        $this->loop->run();
    }

    /**
     * @dataProvider provideIpvAddresses
     */
    public function testIpv6ResolvesFirstSoIsTheFirstToConnect(array $ipv6, array $ipv4)
    {
        $deferred = new Deferred();

        $this->resolver->expects($this->exactly(2))->method('resolveAll')->withConsecutive(
            ['google.com', Message::TYPE_AAAA],
            ['google.com', Message::TYPE_A]
        )->willReturnOnConsecutiveCalls(
            $this->returnValue(resolve($ipv6)),
            $this->returnValue($deferred->promise())
        );
        $this->tcp->expects($this->any())->method('connect')->with($this->stringContains(']:80/?hostname=google.com'))->willReturn(reject(new \Exception('reject')));

        $this->connector->connect('scheme://google.com:80/?hostname=google.com');

        $this->loop->addTimer(0.07, function () use ($deferred) {
            $deferred->reject(new \RuntimeException());
        });

        $this->loop->run();
    }

    /**
     * @dataProvider provideIpvAddresses
     */
    public function testIpv6DoesntResolvesWhileIpv4DoesFirstSoIpv4Connects(array $ipv6, array $ipv4)
    {
        $deferred = new Deferred();

        $this->resolver->expects($this->exactly(2))->method('resolveAll')->withConsecutive(
            ['google.com', Message::TYPE_AAAA],
            ['google.com', Message::TYPE_A]
        )->willReturnOnConsecutiveCalls(
            $this->returnValue($deferred->promise()),
            $this->returnValue(resolve($ipv4))
        );
        $this->tcp->expects($this->any())->method('connect')->with($this->stringContains(':80/?hostname=google.com'))->willReturn(reject(new \Exception('reject')));

        $this->connector->connect('scheme://google.com:80/?hostname=google.com');

        $this->loop->addTimer(0.07, function () use ($deferred) {
            $deferred->reject(new \RuntimeException());
        });

        $this->loop->run();
    }

    public function testRejectsImmediatelyIfUriIsInvalid()
    {
        $this->resolver->expects($this->never())->method('resolveAll');
        $this->tcp->expects($this->never())->method('connect');

        $promise = $this->connector->connect('////');

        $promise->then(null, $this->expectCallableOnceWithException(
            \InvalidArgumentException::class,
            'Given URI "////" is invalid (EINVAL)',
            defined('SOCKET_EINVAL') ? SOCKET_EINVAL : (defined('PCNTL_EINVAL') ? PCNTL_EINVAL : 22)
        ));
    }

    public function testRejectsWithTcpConnectorRejectionIfGivenIp()
    {
        $promise = reject(new \RuntimeException('Connection failed'));
        $this->resolver->expects($this->never())->method('resolveAll');
        $this->tcp->expects($this->once())->method('connect')->with('1.2.3.4:80')->willReturn($promise);

        $promise = $this->connector->connect('1.2.3.4:80');
        $this->loop->addTimer(0.5, function () use ($promise) {
            $promise->cancel();

            $this->throwRejection($promise);
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Connection failed');
        $this->loop->run();
    }

    public function testSkipConnectionIfDnsFails()
    {
        $this->resolver->expects($this->exactly(2))->method('resolveAll')->with('example.invalid', $this->anything())->willReturn(reject(new \RuntimeException('DNS error')));
        $this->tcp->expects($this->never())->method('connect');

        $promise = $this->connector->connect('example.invalid:80');

        $this->loop->addTimer(0.5, function () use ($promise) {
            $this->throwRejection($promise);
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Connection to tcp://example.invalid:80 failed during DNS lookup: DNS error');
        $this->loop->run();
    }

    public function testCancelDuringDnsCancelsDnsAndDoesNotStartTcpConnection()
    {
        $this->resolver->expects($this->exactly(2))->method('resolveAll')->with('example.com', $this->anything())->willReturnCallback(function () {
            return new Promise(function () { }, $this->expectCallableExactly(1));
        });
        $this->tcp->expects($this->never())->method('connect');

        $promise = $this->connector->connect('example.com:80');
        $this->loop->addTimer(0.05, function () use ($promise) {
            $promise->cancel();

            $this->throwRejection($promise);
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Connection to tcp://example.com:80 cancelled during DNS lookup (ECONNABORTED)');
        $this->expectExceptionCode(\defined('SOCKET_ECONNABORTED') ? \SOCKET_ECONNABORTED : 103);
        $this->loop->run();
    }

    public function testCancelDuringTcpConnectionCancelsTcpConnectionIfGivenIp()
    {
        $pending = new Promise(function () { }, $this->expectCallableOnce());
        $this->resolver->expects($this->never())->method('resolveAll');
        $this->tcp->expects($this->once())->method('connect')->with('1.2.3.4:80')->willReturn($pending);

        $promise = $this->connector->connect('1.2.3.4:80');
        $this->loop->addTimer(0.1, function () use ($promise) {
            $promise->cancel();
        });

        $this->loop->run();
    }

    /**
     * @internal
     */
    public function throwRejection($promise)
    {
        $ex = null;
        $promise->then(null, function ($e) use (&$ex) {
            $ex = $e;
        });

        throw $ex;
    }

    public static function provideIpvAddresses()
    {
        $ipv6 = [
            ['1:2:3:4'],
            ['1:2:3:4', '5:6:7:8'],
            ['1:2:3:4', '5:6:7:8', '9:10:11:12'],
        ];
        $ipv4 = [
            ['1.2.3.4'],
            ['1.2.3.4', '5.6.7.8'],
            ['1.2.3.4', '5.6.7.8', '9.10.11.12']
        ];

        foreach ($ipv6 as $v6) {
            foreach ($ipv4 as $v4) {
                yield [
                    $v6,
                    $v4
                ];
            }
        }
    }
}
