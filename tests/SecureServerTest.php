<?php

namespace React\Tests\Socket;

use React\EventLoop\LoopInterface;
use React\Promise\Promise;
use React\Socket\Connection;
use React\Socket\ConnectionInterface;
use React\Socket\SecureServer;
use React\Socket\ServerInterface;
use React\Socket\StreamEncryption;
use React\Socket\TcpServer;
use function React\Promise\reject;

class SecureServerTest extends TestCase
{
    public function testConstructWithoutLoopAssignsLoopAutomatically()
    {
        $tcp = $this->createMock(ServerInterface::class);

        $server = new SecureServer($tcp);

        $ref = new \ReflectionProperty($server, 'encryption');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $encryption = $ref->getValue($server);

        $ref = new \ReflectionProperty($encryption, 'loop');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $loop = $ref->getValue($encryption);

        $this->assertInstanceOf(LoopInterface::class, $loop);
    }

    public function testGetAddressWillBePassedThroughToTcpServer()
    {
        $tcp = $this->createMock(ServerInterface::class);
        $tcp->expects($this->once())->method('getAddress')->willReturn('tcp://127.0.0.1:1234');

        $loop = $this->createMock(LoopInterface::class);

        $server = new SecureServer($tcp, $loop, []);

        $this->assertEquals('tls://127.0.0.1:1234', $server->getAddress());
    }

    public function testGetAddressWillReturnNullIfTcpServerReturnsNull()
    {
        $tcp = $this->createMock(ServerInterface::class);
        $tcp->expects($this->once())->method('getAddress')->willReturn(null);

        $loop = $this->createMock(LoopInterface::class);

        $server = new SecureServer($tcp, $loop, []);

        $this->assertNull($server->getAddress());
    }

    public function testPauseWillBePassedThroughToTcpServer()
    {
        $tcp = $this->createMock(ServerInterface::class);
        $tcp->expects($this->once())->method('pause');

        $loop = $this->createMock(LoopInterface::class);

        $server = new SecureServer($tcp, $loop, []);

        $server->pause();
    }

    public function testResumeWillBePassedThroughToTcpServer()
    {
        $tcp = $this->createMock(ServerInterface::class);
        $tcp->expects($this->once())->method('resume');

        $loop = $this->createMock(LoopInterface::class);

        $server = new SecureServer($tcp, $loop, []);

        $server->resume();
    }

    public function testCloseWillBePassedThroughToTcpServer()
    {
        $tcp = $this->createMock(ServerInterface::class);
        $tcp->expects($this->once())->method('close');

        $loop = $this->createMock(LoopInterface::class);

        $server = new SecureServer($tcp, $loop, []);

        $server->close();
    }

    public function testConnectionWillBeClosedWithErrorIfItIsNotAStream()
    {
        $loop = $this->createMock(LoopInterface::class);

        $tcp = new TcpServer(0, $loop);

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())->method('close');

        $server = new SecureServer($tcp, $loop, []);

        $server->on('error', $this->expectCallableOnce());

        $tcp->emit('connection', [$connection]);
    }

    public function testConnectionWillTryToEnableEncryptionAndWaitForHandshake()
    {
        $loop = $this->createMock(LoopInterface::class);

        $tcp = new TcpServer(0, $loop);

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('getRemoteAddress')->willReturn('tcp://127.0.0.1:1234');
        $connection->expects($this->never())->method('close');

        $server = new SecureServer($tcp, $loop, []);

        $pending = new Promise(function () { });

        $encryption = $this->createMock(StreamEncryption::class);
        $encryption->expects($this->once())->method('enable')->willReturn($pending);

        $ref = new \ReflectionProperty($server, 'encryption');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $ref->setValue($server, $encryption);

        $ref = new \ReflectionProperty($server, 'context');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $ref->setValue($server, []);

        $server->on('error', $this->expectCallableNever());
        $server->on('connection', $this->expectCallableNever());

        $tcp->emit('connection', [$connection]);
    }

    public function testConnectionWillBeClosedWithErrorIfEnablingEncryptionFails()
    {
        $loop = $this->createMock(LoopInterface::class);

        $tcp = new TcpServer(0, $loop);

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('getRemoteAddress')->willReturn('tcp://127.0.0.1:1234');
        $connection->expects($this->once())->method('close');

        $server = new SecureServer($tcp, $loop, []);

        $error = new \RuntimeException('Original');

        $encryption = $this->createMock(StreamEncryption::class);
        $encryption->expects($this->once())->method('enable')->willReturn(reject($error));

        $ref = new \ReflectionProperty($server, 'encryption');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $ref->setValue($server, $encryption);

        $ref = new \ReflectionProperty($server, 'context');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $ref->setValue($server, []);

        $error = null;
        $server->on('error', $this->expectCallableOnce());
        $server->on('error', function ($e) use (&$error) {
            $error = $e;
        });

        $tcp->emit('connection', [$connection]);

        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertEquals('Connection from tcp://127.0.0.1:1234 failed during TLS handshake: Original', $error->getMessage());
    }

    public function testSocketErrorWillBeForwarded()
    {
        $loop = $this->createMock(LoopInterface::class);

        $tcp = new TcpServer(0, $loop);

        $server = new SecureServer($tcp, $loop, []);

        $server->on('error', $this->expectCallableOnce());

        $tcp->emit('error', [new \RuntimeException('test')]);
    }
}
