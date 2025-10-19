<?php

namespace React\Tests\Socket;

use React\Dns\Resolver\ResolverInterface;
use React\EventLoop\LoopInterface;
use React\Promise\Promise;
use React\Socket\Connector;
use React\Socket\ConnectorInterface;

class ConnectorTest extends TestCase
{
    public function testConstructWithoutLoopAssignsLoopAutomatically()
    {
        $connector = new Connector();

        $ref = new \ReflectionProperty($connector, 'connectors');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $connectors = $ref->getValue($connector);

        $ref = new \ReflectionProperty($connectors['tcp'], 'loop');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $loop = $ref->getValue($connectors['tcp']);

        $this->assertInstanceOf(LoopInterface::class, $loop);
    }

    public function testConstructWithLoopAssignsGivenLoop()
    {
        $loop = $this->createMock(LoopInterface::class);

        $connector = new Connector([], $loop);

        $ref = new \ReflectionProperty($connector, 'connectors');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $connectors = $ref->getValue($connector);

        $ref = new \ReflectionProperty($connectors['tcp'], 'loop');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $loop = $ref->getValue($connectors['tcp']);

        $this->assertInstanceOf(LoopInterface::class, $loop);
    }

    public function testConstructWithContextAssignsGivenContext()
    {
        $tcp = $this->createMock(ConnectorInterface::class);

        $connector = new Connector([
            'tcp' => $tcp,
            'dns' => false,
            'timeout' => false
        ]);

        $ref = new \ReflectionProperty($connector, 'connectors');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $connectors = $ref->getValue($connector);

        $this->assertSame($tcp, $connectors['tcp']);
    }

    public function testConnectorUsesTcpAsDefaultScheme()
    {
        $loop = $this->createMock(LoopInterface::class);

        $promise = new Promise(function () { });
        $tcp = $this->createMock(ConnectorInterface::class);
        $tcp->expects($this->once())->method('connect')->with('127.0.0.1:80')->willReturn($promise);

        $connector = new Connector([
            'tcp' => $tcp
        ], $loop);

        $connector->connect('127.0.0.1:80');
    }

    public function testConnectorPassedThroughHostnameIfDnsIsDisabled()
    {
        $loop = $this->createMock(LoopInterface::class);

        $promise = new Promise(function () { });
        $tcp = $this->createMock(ConnectorInterface::class);
        $tcp->expects($this->once())->method('connect')->with('tcp://google.com:80')->willReturn($promise);

        $connector = new Connector([
            'tcp' => $tcp,
            'dns' => false
        ], $loop);

        $connector->connect('tcp://google.com:80');
    }

    public function testConnectorWithUnknownSchemeAlwaysFails()
    {
        $loop = $this->createMock(LoopInterface::class);
        $connector = new Connector([], $loop);

        $promise = $connector->connect('unknown://google.com:80');

        $promise->then(null, $this->expectCallableOnceWithException(
            \RuntimeException::class,
            'No connector available for URI scheme "unknown" (EINVAL)',
            defined('SOCKET_EINVAL') ? SOCKET_EINVAL : (defined('PCNTL_EINVAL') ? PCNTL_EINVAL : 22)
        ));
    }

    public function testConnectorWithDisabledTcpDefaultSchemeAlwaysFails()
    {
        $loop = $this->createMock(LoopInterface::class);
        $connector = new Connector([
            'tcp' => false
        ], $loop);

        $promise = $connector->connect('google.com:80');

        $promise->then(null, $this->expectCallableOnceWithException(
            \RuntimeException::class,
            'No connector available for URI scheme "tcp" (EINVAL)',
            defined('SOCKET_EINVAL') ? SOCKET_EINVAL : (defined('PCNTL_EINVAL') ? PCNTL_EINVAL : 22)
        ));
    }

    public function testConnectorWithDisabledTcpSchemeAlwaysFails()
    {
        $loop = $this->createMock(LoopInterface::class);
        $connector = new Connector([
            'tcp' => false
        ], $loop);

        $promise = $connector->connect('tcp://google.com:80');

        $promise->then(null, $this->expectCallableOnceWithException(
            \RuntimeException::class,
            'No connector available for URI scheme "tcp" (EINVAL)',
            defined('SOCKET_EINVAL') ? SOCKET_EINVAL : (defined('PCNTL_EINVAL') ? PCNTL_EINVAL : 22)
        ));
    }

    public function testConnectorWithDisabledTlsSchemeAlwaysFails()
    {
        $loop = $this->createMock(LoopInterface::class);
        $connector = new Connector([
            'tls' => false
        ], $loop);

        $promise = $connector->connect('tls://google.com:443');

        $promise->then(null, $this->expectCallableOnceWithException(
            \RuntimeException::class,
            'No connector available for URI scheme "tls" (EINVAL)',
            defined('SOCKET_EINVAL') ? SOCKET_EINVAL : (defined('PCNTL_EINVAL') ? PCNTL_EINVAL : 22)
        ));
    }

    public function testConnectorWithDisabledUnixSchemeAlwaysFails()
    {
        $loop = $this->createMock(LoopInterface::class);
        $connector = new Connector([
            'unix' => false
        ], $loop);

        $promise = $connector->connect('unix://demo.sock');

        $promise->then(null, $this->expectCallableOnceWithException(
            \RuntimeException::class,
            'No connector available for URI scheme "unix" (EINVAL)',
            defined('SOCKET_EINVAL') ? SOCKET_EINVAL : (defined('PCNTL_EINVAL') ? PCNTL_EINVAL : 22)
        ));
    }

    public function testConnectorUsesGivenResolverInstance()
    {
        $loop = $this->createMock(LoopInterface::class);

        $promise = new Promise(function () { });
        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->once())->method('resolve')->with('google.com')->willReturn($promise);

        $connector = new Connector([
            'dns' => $resolver,
            'happy_eyeballs' => false
        ], $loop);

        $connector->connect('google.com:80');
    }

    public function testConnectorUsesResolvedHostnameIfDnsIsUsed()
    {
        $loop = $this->createMock(LoopInterface::class);

        $promise = new Promise(function ($resolve) { $resolve('127.0.0.1'); });
        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->once())->method('resolve')->with('google.com')->willReturn($promise);

        $promise = new Promise(function () { });
        $tcp = $this->createMock(ConnectorInterface::class);
        $tcp->expects($this->once())->method('connect')->with('tcp://127.0.0.1:80?hostname=google.com')->willReturn($promise);

        $connector = new Connector([
            'tcp' => $tcp,
            'dns' => $resolver,
            'happy_eyeballs' => false
        ], $loop);

        $connector->connect('tcp://google.com:80');
    }
}
