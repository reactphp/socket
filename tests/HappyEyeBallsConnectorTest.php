<?php

namespace React\Tests\Socket;

use React\Dns\Model\Message;
use React\EventLoop\StreamSelectLoop;
use React\Promise;
use React\Promise\Deferred;
use React\Socket\HappyEyeBallsConnector;
use Clue\React\Block;

class HappyEyeBallsConnectorTest extends TestCase
{
    private $loop;
    private $tcp;
    private $resolver;
    private $connector;
    private $connection;

    public function setUp()
    {
        $this->loop = new TimerSpeedUpEventLoop(new StreamSelectLoop());
        $this->tcp = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $this->resolver = $this->getMockBuilder('React\Dns\Resolver\ResolverInterface')->disableOriginalConstructor()->getMock();
        $this->connection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $this->connector = new HappyEyeBallsConnector($this->loop, $this->tcp, $this->resolver);
    }

    public function testHappyFlow()
    {
        $first = new Deferred();
        $this->resolver->expects($this->exactly(2))->method('resolveAll')->with($this->equalTo('example.com'), $this->anything())->willReturn($first->promise());
        $connection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $this->tcp->expects($this->exactly(1))->method('connect')->with($this->equalTo('1.2.3.4:80?hostname=example.com'))->willReturn(Promise\resolve($connection));

        $promise = $this->connector->connect('example.com:80');
        $first->resolve(array('1.2.3.4'));

        $resolvedConnection = Block\await($promise, $this->loop);

        self::assertSame($connection, $resolvedConnection);
    }

    public function testThatAnyOtherPendingConnectionAttemptsWillBeCanceledOnceAConnectionHasBeenEstablished()
    {
        $connection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $lookupAttempts = array(
            Promise\reject(new \Exception('error')),
            Promise\resolve(array('1.2.3.4', '5.6.7.8', '9.10.11.12')),
        );
        $connectionAttempts = array(
            new Promise\Promise(function () {}, $this->expectCallableOnce()),
            Promise\resolve($connection),
            new Promise\Promise(function () {}, $this->expectCallableNever()),
        );
        $this->resolver->expects($this->exactly(2))->method('resolveAll')->with($this->equalTo('example.com'), $this->anything())->will($this->returnCallback(function () use (&$lookupAttempts) {
            return array_shift($lookupAttempts);
        }));
        $this->tcp->expects($this->exactly(2))->method('connect')->with($this->isType('string'))->will($this->returnCallback(function () use (&$connectionAttempts) {
            return array_shift($connectionAttempts);
        }));

        $promise = $this->connector->connect('example.com:80');

        $resolvedConnection = Block\await($promise, $this->loop);

        self::assertSame($connection, $resolvedConnection);
    }

    public function testPassByResolverIfGivenIp()
    {
        $this->resolver->expects($this->never())->method('resolveAll');
        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('127.0.0.1:80'))->will($this->returnValue(Promise\resolve()));

        $this->connector->connect('127.0.0.1:80');

        $this->loop->run();
    }

    public function testPassByResolverIfGivenIpv6()
    {
        $this->resolver->expects($this->never())->method('resolveAll');
        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('[::1]:80'))->will($this->returnValue(Promise\reject()));

        $this->connector->connect('[::1]:80');

        $this->loop->run();
    }

    public function testPassThroughResolverIfGivenHost()
    {
        $this->resolver->expects($this->exactly(2))->method('resolveAll')->with($this->equalTo('google.com'), $this->anything())->will($this->returnValue(Promise\resolve(array('1.2.3.4'))));
        $this->tcp->expects($this->exactly(2))->method('connect')->with($this->equalTo('1.2.3.4:80?hostname=google.com'))->will($this->returnValue(Promise\reject()));

        $this->connector->connect('google.com:80');

        $this->loop->run();
    }

    public function testPassThroughResolverIfGivenHostWhichResolvesToIpv6()
    {
        $this->resolver->expects($this->exactly(2))->method('resolveAll')->with($this->equalTo('google.com'), $this->anything())->will($this->returnValue(Promise\resolve(array('::1'))));
        $this->tcp->expects($this->exactly(2))->method('connect')->with($this->equalTo('[::1]:80?hostname=google.com'))->will($this->returnValue(Promise\reject()));

        $this->connector->connect('google.com:80');

        $this->loop->run();
    }

    public function testPassByResolverIfGivenCompleteUri()
    {
        $this->resolver->expects($this->never())->method('resolveAll');
        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('scheme://127.0.0.1:80/path?query#fragment'))->will($this->returnValue(Promise\reject()));

        $this->connector->connect('scheme://127.0.0.1:80/path?query#fragment');

        $this->loop->run();
    }

    public function testPassThroughResolverIfGivenCompleteUri()
    {
        $this->resolver->expects($this->exactly(2))->method('resolveAll')->with($this->equalTo('google.com'), $this->anything())->will($this->returnValue(Promise\resolve(array('1.2.3.4'))));
        $this->tcp->expects($this->exactly(2))->method('connect')->with($this->equalTo('scheme://1.2.3.4:80/path?query&hostname=google.com#fragment'))->will($this->returnValue(Promise\reject()));

        $this->connector->connect('scheme://google.com:80/path?query#fragment');

        $this->loop->run();
    }

    public function testPassThroughResolverIfGivenExplicitHost()
    {
        $this->resolver->expects($this->exactly(2))->method('resolveAll')->with($this->equalTo('google.com'), $this->anything())->will($this->returnValue(Promise\resolve(array('1.2.3.4'))));
        $this->tcp->expects($this->exactly(2))->method('connect')->with($this->equalTo('scheme://1.2.3.4:80/?hostname=google.de'))->will($this->returnValue(Promise\reject()));

        $this->connector->connect('scheme://google.com:80/?hostname=google.de');

        $this->loop->run();
    }

    /**
     * @dataProvider provideIpvAddresses
     */
    public function testIpv6ResolvesFirstSoIsTheFirstToConnect(array $ipv6, array $ipv4)
    {
        $deferred = new Deferred();

        $this->resolver->expects($this->at(0))->method('resolveAll')->with('google.com', Message::TYPE_AAAA)->will($this->returnValue(Promise\resolve($ipv6)));
        $this->resolver->expects($this->at(1))->method('resolveAll')->with('google.com', Message::TYPE_A)->will($this->returnValue($deferred->promise()));
        $this->tcp->expects($this->any())->method('connect')->with($this->stringContains(']:80/?hostname=google.com'))->will($this->returnValue(Promise\reject()));

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

        $this->resolver->expects($this->at(0))->method('resolveAll')->with('google.com', Message::TYPE_AAAA)->will($this->returnValue($deferred->promise()));
        $this->resolver->expects($this->at(1))->method('resolveAll')->with('google.com', Message::TYPE_A)->will($this->returnValue(Promise\resolve($ipv4)));
        $this->tcp->expects($this->any())->method('connect')->with($this->stringContains(':80/?hostname=google.com'))->will($this->returnValue(Promise\reject()));

        $this->connector->connect('scheme://google.com:80/?hostname=google.com');

        $this->loop->addTimer(0.07, function () use ($deferred) {
            $deferred->reject(new \RuntimeException());
        });

        $this->loop->run();
    }

    /**
     * @dataProvider provideIpvAddresses
     */
    public function testAttemptsToConnectBothIpv6AndIpv4AddressesAlternatingIpv6AndIpv4AddressesWhenMoreThenOneIsResolvedPerFamily(array $ipv6, array $ipv4)
    {
        $this->resolver->expects($this->at(0))->method('resolveAll')->with('google.com', Message::TYPE_AAAA)->will($this->returnValue(
            Promise\Timer\resolve(0.1, $this->loop)->then(function () use ($ipv6) {
                return Promise\resolve($ipv6);
            })
        ));
        $this->resolver->expects($this->at(1))->method('resolveAll')->with('google.com', Message::TYPE_A)->will($this->returnValue(
            Promise\Timer\resolve(0.1, $this->loop)->then(function () use ($ipv4) {
                return Promise\resolve($ipv4);
            })
        ));

        $i = 0;
        while (count($ipv6) > 0 || count($ipv4) > 0) {
            if (count($ipv6) > 0) {
                $this->tcp->expects($this->at($i++))->method('connect')->with($this->equalTo('scheme://[' . array_shift($ipv6) . ']:80/?hostname=google.com'))->will($this->returnValue(Promise\reject()));
            }
            if (count($ipv4) > 0) {
                $this->tcp->expects($this->at($i++))->method('connect')->with($this->equalTo('scheme://' . array_shift($ipv4) . ':80/?hostname=google.com'))->will($this->returnValue(Promise\reject()));
            }
        }


        $this->connector->connect('scheme://google.com:80/?hostname=google.com');

        $this->loop->run();
    }

    public function testRejectsImmediatelyIfUriIsInvalid()
    {
        $this->resolver->expects($this->never())->method('resolveAll');
        $this->tcp->expects($this->never())->method('connect');

        $promise = $this->connector->connect('////');

        $promise->then($this->expectCallableNever(), $this->expectCallableOnce());

        $this->loop->run();
    }

    /**
     * @expectedException RuntimeException
     * @expectedExceptionMessage Connection failed
     */
    public function testRejectsWithTcpConnectorRejectionIfGivenIp()
    {
        $that = $this;
        $promise = Promise\reject(new \RuntimeException('Connection failed'));
        $this->resolver->expects($this->never())->method('resolveAll');
        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('1.2.3.4:80'))->willReturn($promise);

        $promise = $this->connector->connect('1.2.3.4:80');
        $this->loop->addTimer(0.5, function () use ($that, $promise) {
            $promise->cancel();

            $that->throwRejection($promise);
        });

        $this->loop->run();
    }

    /**
     * @expectedException RuntimeException
     * @expectedExceptionMessage Connection to example.invalid:80 failed during DNS lookup: DNS error
     */
    public function testSkipConnectionIfDnsFails()
    {
        $that = $this;
        $this->resolver->expects($this->exactly(2))->method('resolveAll')->with($this->equalTo('example.invalid'), $this->anything())->willReturn(Promise\reject(new \RuntimeException('DNS error')));
        $this->tcp->expects($this->never())->method('connect');

        $promise = $this->connector->connect('example.invalid:80');

        $this->loop->addTimer(0.5, function () use ($that, $promise) {
            $that->throwRejection($promise);
        });

        $this->loop->run();
    }

    /**
     * @expectedException RuntimeException
     * @expectedExceptionMessage Connection to example.com:80 cancelled during DNS lookup
     */
    public function testCancelDuringDnsCancelsDnsAndDoesNotStartTcpConnection()
    {
        $that = $this;
        $this->resolver->expects($this->exactly(2))->method('resolveAll')->with('example.com', $this->anything())->will($this->returnCallback(function () use ($that) {
            return new Promise\Promise(function () { }, $that->expectCallableExactly(1));
        }));
        $this->tcp->expects($this->never())->method('connect');

        $promise = $this->connector->connect('example.com:80');
        $this->loop->addTimer(0.05, function () use ($that, $promise) {
            $promise->cancel();

            $that->throwRejection($promise);
        });

        $this->loop->run();
    }

    public function testCancelDuringTcpConnectionCancelsTcpConnectionIfGivenIp()
    {
        $pending = new Promise\Promise(function () { }, $this->expectCallableOnce());
        $this->resolver->expects($this->never())->method('resolveAll');
        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('1.2.3.4:80'))->willReturn($pending);

        $promise = $this->connector->connect('1.2.3.4:80');
        $this->loop->addTimer(0.1, function () use ($promise) {
            $promise->cancel();
        });

        $this->loop->run();
    }

    /**
     * @dataProvider provideIpvAddresses
     */
    public function testShouldConnectOverIpv4WhenIpv6LookupFails(array $ipv6, array $ipv4)
    {
        $this->resolver->expects($this->at(0))->method('resolveAll')->with($this->equalTo('example.com'), Message::TYPE_AAAA)->willReturn(Promise\reject(new \Exception('failure')));
        $this->resolver->expects($this->at(1))->method('resolveAll')->with($this->equalTo('example.com'), Message::TYPE_A)->willReturn(Promise\resolve($ipv4));
        $this->tcp->expects($this->exactly(1))->method('connect')->with($this->equalTo('1.2.3.4:80?hostname=example.com'))->willReturn(Promise\resolve($this->connection));

        $promise = $this->connector->connect('example.com:80');;
        $resolvedConnection = Block\await($promise, $this->loop);

        self::assertSame($this->connection, $resolvedConnection);
    }

    /**
     * @dataProvider provideIpvAddresses
     */
    public function testShouldConnectOverIpv6WhenIpv4LookupFails(array $ipv6, array $ipv4)
    {
        if (count($ipv6) === 0) {
            $ipv6[] = '1:2:3:4';
        }

        $this->resolver->expects($this->at(0))->method('resolveAll')->with($this->equalTo('example.com'), Message::TYPE_AAAA)->willReturn(Promise\resolve($ipv6));
        $this->resolver->expects($this->at(1))->method('resolveAll')->with($this->equalTo('example.com'), Message::TYPE_A)->willReturn(Promise\reject(new \Exception('failure')));
        $this->tcp->expects($this->exactly(1))->method('connect')->with($this->equalTo('[1:2:3:4]:80?hostname=example.com'))->willReturn(Promise\resolve($this->connection));

        $promise = $this->connector->connect('example.com:80');;
        $resolvedConnection = Block\await($promise, $this->loop);

        self::assertSame($this->connection, $resolvedConnection);
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

    public function provideIpvAddresses()
    {
        $ipv6 = array(
            array(),
            array('1:2:3:4'),
            array('1:2:3:4', '5:6:7:8'),
            array('1:2:3:4', '5:6:7:8', '9:10:11:12'),
        );
        $ipv4 = array(
            array('1.2.3.4'),
            array('1.2.3.4', '5.6.7.8'),
            array('1.2.3.4', '5.6.7.8', '9.10.11.12'),
        );

        $ips = array();

        foreach ($ipv6 as $v6) {
            foreach ($ipv4 as $v4) {
                $ips[] = array(
                    $v6,
                    $v4
                );
            }
        }

        return $ips;
    }
}
