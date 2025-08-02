<?php

namespace React\Tests\Socket;

use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Socket\Connection;
use React\Socket\ConnectionInterface;
use React\Socket\ConnectorInterface;
use React\Socket\SecureConnector;
use React\Socket\StreamEncryption;
use function React\Promise\reject;
use function React\Promise\resolve;

class SecureConnectorTest extends TestCase
{
    private $loop;
    private $tcp;
    private $connector;

    /**
     * @before
     */
    public function setUpConnector()
    {
        $this->loop = $this->createMock(LoopInterface::class);
        $this->tcp = $this->createMock(ConnectorInterface::class);
        $this->connector = new SecureConnector($this->tcp, $this->loop);
    }

    public function testConstructWithoutLoopAssignsLoopAutomatically()
    {
        $connector = new SecureConnector($this->tcp);

        $ref = new \ReflectionProperty($connector, 'streamEncryption');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $streamEncryption = $ref->getValue($connector);

        $ref = new \ReflectionProperty($streamEncryption, 'loop');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $loop = $ref->getValue($streamEncryption);

        $this->assertInstanceOf(LoopInterface::class, $loop);
    }

    public function testConnectionWillWaitForTcpConnection()
    {
        $pending = new Promise(function () { });
        $this->tcp->expects($this->once())->method('connect')->with('example.com:80')->willReturn($pending);

        $promise = $this->connector->connect('example.com:80');

        $this->assertInstanceOf(PromiseInterface::class, $promise);
    }

    public function testConnectionWithCompleteUriWillBePassedThroughExpectForScheme()
    {
        $pending = new Promise(function () { });
        $this->tcp->expects($this->once())->method('connect')->with('example.com:80/path?query#fragment')->willReturn($pending);

        $this->connector->connect('tls://example.com:80/path?query#fragment');
    }

    public function testConnectionToInvalidSchemeWillReject()
    {
        $this->tcp->expects($this->never())->method('connect');

        $promise = $this->connector->connect('tcp://example.com:80');

        $promise->then(null, $this->expectCallableOnceWithException(
            \InvalidArgumentException::class,
            'Given URI "tcp://example.com:80" is invalid (EINVAL)',
            defined('SOCKET_EINVAL') ? SOCKET_EINVAL : (defined('PCNTL_EINVAL') ? PCNTL_EINVAL : 22)
        ));
    }

    public function testConnectWillRejectWithTlsUriWhenUnderlyingConnectorRejects()
    {
        $this->tcp->expects($this->once())->method('connect')->with('example.com:80')->willReturn(reject(new \RuntimeException(
            'Connection to tcp://example.com:80 failed: Connection refused (ECONNREFUSED)',
            defined('SOCKET_ECONNREFUSED') ? SOCKET_ECONNREFUSED : 111
        )));

        $promise = $this->connector->connect('example.com:80');

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof \RuntimeException);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('Connection to tls://example.com:80 failed: Connection refused (ECONNREFUSED)', $exception->getMessage());
        $this->assertEquals(defined('SOCKET_ECONNREFUSED') ? SOCKET_ECONNREFUSED : 111, $exception->getCode());
        $this->assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
        $this->assertNotEquals('', $exception->getTraceAsString());
    }

    public function testConnectWillRejectWithOriginalMessageWhenUnderlyingConnectorRejectsWithInvalidArgumentException()
    {
        $this->tcp->expects($this->once())->method('connect')->with('example.com:80')->willReturn(reject(new \InvalidArgumentException(
            'Invalid',
            42
        )));

        $promise = $this->connector->connect('example.com:80');

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof \InvalidArgumentException);
        $this->assertInstanceOf(\InvalidArgumentException::class, $exception);
        $this->assertEquals('Invalid', $exception->getMessage());
        $this->assertEquals(42, $exception->getCode());
        $this->assertNull($exception->getPrevious());
        $this->assertNotEquals('', $exception->getTraceAsString());
    }

    public function testCancelDuringTcpConnectionCancelsTcpConnection()
    {
        $pending = new Promise(function () { }, $this->expectCallableOnce());
        $this->tcp->expects($this->once())->method('connect')->with('example.com:80')->willReturn($pending);

        $promise = $this->connector->connect('example.com:80');
        $promise->cancel();
    }

    public function testCancelDuringTcpConnectionCancelsTcpConnectionAndRejectsWithTcpRejection()
    {
        $pending = new Promise(function () { }, function () { throw new \RuntimeException(
            'Connection to tcp://example.com:80 cancelled (ECONNABORTED)',
            defined('SOCKET_ECONNABORTED') ? SOCKET_ECONNABORTED : 103
        ); });
        $this->tcp->expects($this->once())->method('connect')->with('example.com:80')->willReturn($pending);

        $promise = $this->connector->connect('example.com:80');
        $promise->cancel();

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof \RuntimeException);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('Connection to tls://example.com:80 cancelled (ECONNABORTED)', $exception->getMessage());
        $this->assertEquals(defined('SOCKET_ECONNABORTED') ? SOCKET_ECONNABORTED : 103, $exception->getCode());
        $this->assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
        $this->assertNotEquals('', $exception->getTraceAsString());
    }

    public function testConnectionWillBeClosedAndRejectedIfConnectionIsNoStream()
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())->method('close');

        $this->tcp->expects($this->once())->method('connect')->with('example.com:80')->willReturn(resolve($connection));

        $promise = $this->connector->connect('example.com:80');

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof \UnexpectedValueException);
        $this->assertInstanceOf(\UnexpectedValueException::class, $exception);
        $this->assertEquals('Base connector does not use internal Connection class exposing stream resource', $exception->getMessage());
        $this->assertEquals(0, $exception->getCode());
        $this->assertNull($exception->getPrevious());
        $this->assertNotEquals('', $exception->getTraceAsString());
    }

    public function testStreamEncryptionWillBeEnabledAfterConnecting()
    {
        $connection = $this->createMock(Connection::class);

        $encryption = $this->createMock(StreamEncryption::class);
        $encryption->expects($this->once())->method('enable')->with($connection)->willReturn(new Promise(function () { }));

        $ref = new \ReflectionProperty($this->connector, 'streamEncryption');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $ref->setValue($this->connector, $encryption);

        $this->tcp->expects($this->once())->method('connect')->with('example.com:80')->willReturn(resolve($connection));

        $this->connector->connect('example.com:80');
    }

    public function testConnectionWillBeRejectedIfStreamEncryptionFailsAndClosesConnection()
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('close');

        $encryption = $this->createMock(StreamEncryption::class);
        $encryption->expects($this->once())->method('enable')->willReturn(reject(new \RuntimeException('TLS error', 123)));

        $ref = new \ReflectionProperty($this->connector, 'streamEncryption');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $ref->setValue($this->connector, $encryption);

        $this->tcp->expects($this->once())->method('connect')->with('example.com:80')->willReturn(resolve($connection));

        $promise = $this->connector->connect('example.com:80');

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof \RuntimeException);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('Connection to tls://example.com:80 failed during TLS handshake: TLS error', $exception->getMessage());
        $this->assertEquals(123, $exception->getCode());
        $this->assertNull($exception->getPrevious());
        $this->assertNotEquals('', $exception->getTraceAsString());
    }

    public function testCancelDuringStreamEncryptionCancelsEncryptionAndClosesConnection()
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('close');

        $pending = new Promise(function () { }, function () {
            throw new \Exception('Ignored');
        });
        $encryption = $this->createMock(StreamEncryption::class);
        $encryption->expects($this->once())->method('enable')->willReturn($pending);

        $ref = new \ReflectionProperty($this->connector, 'streamEncryption');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $ref->setValue($this->connector, $encryption);

        $deferred = new Deferred();
        $this->tcp->expects($this->once())->method('connect')->with('example.com:80')->willReturn($deferred->promise());

        $promise = $this->connector->connect('example.com:80');
        $deferred->resolve($connection);

        $promise->cancel();

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof \RuntimeException);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('Connection to tls://example.com:80 cancelled during TLS handshake (ECONNABORTED)', $exception->getMessage());
        $this->assertEquals(defined('SOCKET_ECONNABORTED') ? SOCKET_ECONNABORTED : 103, $exception->getCode());
        $this->assertNull($exception->getPrevious());
        $this->assertNotEquals('', $exception->getTraceAsString());
    }

    public function testRejectionDuringConnectionShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $tcp = new Deferred();
        $this->tcp->expects($this->once())->method('connect')->willReturn($tcp->promise());

        $promise = $this->connector->connect('example.com:80');

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection

        $tcp->reject(new \RuntimeException());
        unset($promise, $tcp);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testRejectionDuringTlsHandshakeShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $connection = $this->createMock(Connection::class);

        $tcp = new Deferred();
        $this->tcp->expects($this->once())->method('connect')->willReturn($tcp->promise());

        $tls = new Deferred();
        $encryption = $this->createMock(StreamEncryption::class);
        $encryption->expects($this->once())->method('enable')->willReturn($tls->promise());

        $ref = new \ReflectionProperty($this->connector, 'streamEncryption');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $ref->setValue($this->connector, $encryption);

        $promise = $this->connector->connect('example.com:80');

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection

        $tcp->resolve($connection);
        $tls->reject(new \RuntimeException());
        unset($promise, $tcp, $tls);

        $this->assertEquals(0, gc_collect_cycles());
    }
}
