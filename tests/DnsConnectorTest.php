<?php

namespace React\Tests\Socket;

use React\Promise;
use React\Promise\Deferred;
use React\Socket\DnsConnector;

class DnsConnectorTest extends TestCase
{
    private $tcp;
    private $resolver;
    private $connector;

    /**
     * @before
     */
    public function setUpMocks()
    {
        $this->tcp = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $this->resolver = $this->getMockBuilder('React\Dns\Resolver\ResolverInterface')->getMock();

        $this->connector = new DnsConnector($this->tcp, $this->resolver);
    }

    public function testPassByResolverIfGivenIp()
    {
        $this->resolver->expects($this->never())->method('resolve');
        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('127.0.0.1:80'))->will($this->returnValue(Promise\reject(new \Exception('reject'))));

        $promise = $this->connector->connect('127.0.0.1:80');

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection
    }

    public function testPassThroughResolverIfGivenHost()
    {
        $this->resolver->expects($this->once())->method('resolve')->with($this->equalTo('google.com'))->will($this->returnValue(Promise\resolve('1.2.3.4')));
        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('1.2.3.4:80?hostname=google.com'))->will($this->returnValue(Promise\reject(new \Exception('reject'))));

        $promise = $this->connector->connect('google.com:80');

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection
    }

    public function testPassThroughResolverIfGivenHostWhichResolvesToIpv6()
    {
        $this->resolver->expects($this->once())->method('resolve')->with($this->equalTo('google.com'))->will($this->returnValue(Promise\resolve('::1')));
        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('[::1]:80?hostname=google.com'))->will($this->returnValue(Promise\reject(new \Exception('reject'))));

        $promise = $this->connector->connect('google.com:80');

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection
    }

    public function testPassByResolverIfGivenCompleteUri()
    {
        $this->resolver->expects($this->never())->method('resolve');
        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('scheme://127.0.0.1:80/path?query#fragment'))->will($this->returnValue(Promise\reject(new \Exception('reject'))));

        $promise = $this->connector->connect('scheme://127.0.0.1:80/path?query#fragment');

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection
    }

    public function testPassThroughResolverIfGivenCompleteUri()
    {
        $this->resolver->expects($this->once())->method('resolve')->with($this->equalTo('google.com'))->will($this->returnValue(Promise\resolve('1.2.3.4')));
        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('scheme://1.2.3.4:80/path?query&hostname=google.com#fragment'))->will($this->returnValue(Promise\reject(new \Exception('reject'))));

        $promise = $this->connector->connect('scheme://google.com:80/path?query#fragment');

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection
    }

    public function testPassThroughResolverIfGivenExplicitHost()
    {
        $this->resolver->expects($this->once())->method('resolve')->with($this->equalTo('google.com'))->will($this->returnValue(Promise\resolve('1.2.3.4')));
        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('scheme://1.2.3.4:80/?hostname=google.de'))->will($this->returnValue(Promise\reject(new \Exception('reject'))));

        $promise = $this->connector->connect('scheme://google.com:80/?hostname=google.de');

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection
    }

    public function testRejectsImmediatelyIfUriIsInvalid()
    {
        $this->resolver->expects($this->never())->method('resolve');
        $this->tcp->expects($this->never())->method('connect');

        $promise = $this->connector->connect('////');

        $promise->then(null, $this->expectCallableOnceWithException(
            'InvalidArgumentException',
            'Given URI "////" is invalid (EINVAL)',
            defined('SOCKET_EINVAL') ? SOCKET_EINVAL : (defined('PCNTL_EINVAL') ? PCNTL_EINVAL : 22)
        ));
    }

    public function testConnectRejectsIfGivenIpAndTcpConnectorRejectsWithRuntimeException()
    {
        $promise = Promise\reject(new \RuntimeException('Connection to tcp://1.2.3.4:80 failed: Connection failed', 42));
        $this->resolver->expects($this->never())->method('resolve');
        $this->tcp->expects($this->once())->method('connect')->with('1.2.3.4:80')->willReturn($promise);

        $promise = $this->connector->connect('1.2.3.4:80');

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof \RuntimeException);
        $this->assertInstanceOf('RuntimeException', $exception);
        $this->assertEquals('Connection to tcp://1.2.3.4:80 failed: Connection failed', $exception->getMessage());
        $this->assertEquals(42, $exception->getCode());
        $this->assertNull($exception->getPrevious());
        $this->assertNotEquals('', $exception->getTraceAsString());
    }

    public function testConnectRejectsIfGivenIpAndTcpConnectorRejectsWithInvalidArgumentException()
    {
        $promise = Promise\reject(new \InvalidArgumentException('Invalid', 42));
        $this->resolver->expects($this->never())->method('resolve');
        $this->tcp->expects($this->once())->method('connect')->with('1.2.3.4:80')->willReturn($promise);

        $promise = $this->connector->connect('1.2.3.4:80');

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof \InvalidArgumentException);
        $this->assertInstanceOf('InvalidArgumentException', $exception);
        $this->assertEquals('Invalid', $exception->getMessage());
        $this->assertEquals(42, $exception->getCode());
        $this->assertNull($exception->getPrevious());
        $this->assertNotEquals('', $exception->getTraceAsString());
    }

    public function testConnectRejectsWithOriginalHostnameInMessageAfterResolvingIfTcpConnectorRejectsWithRuntimeException()
    {
        $promise = Promise\reject(new \RuntimeException('Connection to tcp://1.2.3.4:80?hostname=example.com failed: Connection failed', 42));
        $this->resolver->expects($this->once())->method('resolve')->with('example.com')->willReturn(Promise\resolve('1.2.3.4'));
        $this->tcp->expects($this->once())->method('connect')->with('1.2.3.4:80?hostname=example.com')->willReturn($promise);

        $promise = $this->connector->connect('example.com:80');

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof \RuntimeException);
        $this->assertInstanceOf('RuntimeException', $exception);
        $this->assertEquals('Connection to tcp://example.com:80 failed: Connection to tcp://1.2.3.4:80 failed: Connection failed', $exception->getMessage());
        $this->assertEquals(42, $exception->getCode());
        $this->assertInstanceOf('RuntimeException', $exception->getPrevious());
        $this->assertNotEquals('', $exception->getTraceAsString());
    }

    public function testConnectRejectsWithOriginalExceptionAfterResolvingIfTcpConnectorRejectsWithInvalidArgumentException()
    {
        $promise = Promise\reject(new \InvalidArgumentException('Invalid', 42));
        $this->resolver->expects($this->once())->method('resolve')->with('example.com')->willReturn(Promise\resolve('1.2.3.4'));
        $this->tcp->expects($this->once())->method('connect')->with('1.2.3.4:80?hostname=example.com')->willReturn($promise);

        $promise = $this->connector->connect('example.com:80');

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof \InvalidArgumentException);
        $this->assertInstanceOf('InvalidArgumentException', $exception);
        $this->assertEquals('Invalid', $exception->getMessage());
        $this->assertEquals(42, $exception->getCode());
        $this->assertNull($exception->getPrevious());
        $this->assertNotEquals('', $exception->getTraceAsString());
    }

    public function testSkipConnectionIfDnsFails()
    {
        $promise = Promise\reject(new \RuntimeException('DNS error'));
        $this->resolver->expects($this->once())->method('resolve')->with($this->equalTo('example.invalid'))->willReturn($promise);
        $this->tcp->expects($this->never())->method('connect');

        $promise = $this->connector->connect('example.invalid:80');

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof \RuntimeException);
        $this->assertInstanceOf('RuntimeException', $exception);
        $this->assertEquals('Connection to tcp://example.invalid:80 failed during DNS lookup: DNS error', $exception->getMessage());
        $this->assertEquals(0, $exception->getCode());
        $this->assertInstanceOf('RuntimeException', $exception->getPrevious());
        $this->assertNotEquals('', $exception->getTraceAsString());
    }

    public function testRejectionExceptionUsesPreviousExceptionIfDnsFails()
    {
        $exception = new \RuntimeException();

        $this->resolver->expects($this->once())->method('resolve')->with($this->equalTo('example.invalid'))->willReturn(Promise\reject($exception));

        $promise = $this->connector->connect('example.invalid:80');

        $promise->then(null, function ($e) {
            throw $e->getPrevious();
        })->then(null, $this->expectCallableOnceWith($this->identicalTo($exception)));
    }

    public function testCancelDuringDnsCancelsDnsAndDoesNotStartTcpConnection()
    {
        $pending = new Promise\Promise(function () { }, $this->expectCallableOnce());
        $this->resolver->expects($this->once())->method('resolve')->with($this->equalTo('example.com'))->will($this->returnValue($pending));
        $this->tcp->expects($this->never())->method('connect');

        $promise = $this->connector->connect('example.com:80');
        $promise->cancel();

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof \RuntimeException);
        $this->assertInstanceOf('RuntimeException', $exception);
        $this->assertEquals('Connection to tcp://example.com:80 cancelled during DNS lookup (ECONNABORTED)', $exception->getMessage());
        $this->assertEquals(defined('SOCKET_ECONNABORTED') ? SOCKET_ECONNABORTED : 103, $exception->getCode());
        $this->assertNull($exception->getPrevious());
        $this->assertNotEquals('', $exception->getTraceAsString());
    }

    public function testCancelDuringTcpConnectionCancelsTcpConnectionIfGivenIp()
    {
        $pending = new Promise\Promise(function () { }, $this->expectCallableOnce());
        $this->resolver->expects($this->never())->method('resolve');
        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('1.2.3.4:80'))->willReturn($pending);

        $promise = $this->connector->connect('1.2.3.4:80');
        $promise->cancel();
    }

    public function testCancelDuringTcpConnectionCancelsTcpConnectionAfterDnsIsResolved()
    {
        $pending = new Promise\Promise(function () { }, $this->expectCallableOnce());
        $this->resolver->expects($this->once())->method('resolve')->with($this->equalTo('example.com'))->willReturn(Promise\resolve('1.2.3.4'));
        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('1.2.3.4:80?hostname=example.com'))->willReturn($pending);

        $promise = $this->connector->connect('example.com:80');
        $promise->cancel();
    }

    public function testCancelDuringTcpConnectionCancelsTcpConnectionWithTcpRejectionAfterDnsIsResolved()
    {
        $first = new Deferred();
        $this->resolver->expects($this->once())->method('resolve')->with($this->equalTo('example.com'))->willReturn($first->promise());
        $pending = new Promise\Promise(function () { }, function () {
            throw new \RuntimeException(
                'Connection cancelled',
                defined('SOCKET_ECONNABORTED') ? SOCKET_ECONNABORTED : 103
            );
        });
        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('1.2.3.4:80?hostname=example.com'))->willReturn($pending);

        $promise = $this->connector->connect('example.com:80');
        $first->resolve('1.2.3.4');

        $promise->cancel();

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof \RuntimeException);
        $this->assertInstanceOf('RuntimeException', $exception);
        $this->assertEquals('Connection to tcp://example.com:80 failed: Connection cancelled', $exception->getMessage());
        $this->assertEquals(defined('SOCKET_ECONNABORTED') ? SOCKET_ECONNABORTED : 103, $exception->getCode());
        $this->assertInstanceOf('RuntimeException', $exception->getPrevious());
        $this->assertNotEquals('', $exception->getTraceAsString());
    }

    public function testRejectionDuringDnsLookupShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $dns = new Deferred();
        $this->resolver->expects($this->once())->method('resolve')->with($this->equalTo('example.com'))->willReturn($dns->promise());
        $this->tcp->expects($this->never())->method('connect');

        $promise = $this->connector->connect('example.com:80');

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection

        $dns->reject(new \RuntimeException('DNS failed'));
        unset($promise, $dns);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testRejectionAfterDnsLookupShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $dns = new Deferred();
        $this->resolver->expects($this->once())->method('resolve')->with($this->equalTo('example.com'))->willReturn($dns->promise());

        $tcp = new Deferred();
        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('1.2.3.4:80?hostname=example.com'))->willReturn($tcp->promise());

        $promise = $this->connector->connect('example.com:80');

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection

        $dns->resolve('1.2.3.4');
        $tcp->reject(new \RuntimeException('Connection failed'));
        unset($promise, $dns, $tcp);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testRejectionAfterDnsLookupShouldNotCreateAnyGarbageReferencesAgain()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $dns = new Deferred();
        $this->resolver->expects($this->once())->method('resolve')->with($this->equalTo('example.com'))->willReturn($dns->promise());

        $tcp = new Deferred();
        $dns->promise()->then(function () use ($tcp) {
            $tcp->reject(new \RuntimeException('Connection failed'));
        });
        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('1.2.3.4:80?hostname=example.com'))->willReturn($tcp->promise());

        $promise = $this->connector->connect('example.com:80');

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection

        $dns->resolve('1.2.3.4');

        unset($promise, $dns, $tcp);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testCancelDuringDnsLookupShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $dns = new Deferred(function () {
            throw new \RuntimeException();
        });
        $this->resolver->expects($this->once())->method('resolve')->with($this->equalTo('example.com'))->willReturn($dns->promise());
        $this->tcp->expects($this->never())->method('connect');

        $promise = $this->connector->connect('example.com:80');

        $promise->cancel();
        unset($promise, $dns);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testCancelDuringTcpConnectionShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $dns = new Deferred();
        $this->resolver->expects($this->once())->method('resolve')->with($this->equalTo('example.com'))->willReturn($dns->promise());
        $tcp = new Promise\Promise(function () { }, function () {
            throw new \RuntimeException('Connection cancelled');
        });
        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('1.2.3.4:80?hostname=example.com'))->willReturn($tcp);

        $promise = $this->connector->connect('example.com:80');
        $dns->resolve('1.2.3.4');

        $promise->cancel();
        unset($promise, $dns, $tcp);

        $this->assertEquals(0, gc_collect_cycles());
    }
}
