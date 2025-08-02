<?php

namespace React\Tests\Socket;

use React\Promise\Promise;
use React\Socket\ConnectionInterface;
use React\Socket\TcpConnector;
use React\Socket\TcpServer;
use function React\Async\await;
use function React\Promise\Timer\sleep;
use function React\Promise\Timer\timeout;

class FunctionalTcpServerTest extends TestCase
{
    const TIMEOUT = 0.1;

    public function testEmitsConnectionForNewConnection()
    {
        $server = new TcpServer(0);
        $server->on('connection', $this->expectCallableOnce());

        $peer = new Promise(function ($resolve, $reject) use ($server) {
            $server->on('connection', function () use ($resolve) {
                $resolve(null);
            });
        });

        $connector = new TcpConnector();
        $promise = $connector->connect($server->getAddress());

        $promise->then($this->expectCallableOnce());

        await(timeout($peer, self::TIMEOUT));
        await(sleep(0.0));

        $server->close();

        $promise->then(function (ConnectionInterface $connection) {
            $connection->close();
        });
    }

    public function testEmitsNoConnectionForNewConnectionWhenPaused()
    {
        $server = new TcpServer(0);
        $server->on('connection', $this->expectCallableNever());
        $server->pause();

        $connector = new TcpConnector();
        $promise = $connector->connect($server->getAddress());

        $promise->then($this->expectCallableOnce());

        await(timeout($promise, self::TIMEOUT));
        await(sleep(0.0));
    }

    public function testConnectionForNewConnectionWhenResumedAfterPause()
    {
        $server = new TcpServer(0);
        $server->on('connection', $this->expectCallableOnce());
        $server->pause();
        $server->resume();

        $peer = new Promise(function ($resolve, $reject) use ($server) {
            $server->on('connection', function () use ($resolve) {
                $resolve(null);
            });
        });

        $connector = new TcpConnector();
        $promise = $connector->connect($server->getAddress());

        $promise->then($this->expectCallableOnce());

        await(timeout($peer, self::TIMEOUT));
        await(sleep(0.0));

        $server->close();
        $promise->then(function (ConnectionInterface $connection) {
            $connection->close();
        });
    }

    public function testEmitsConnectionWithRemoteIp()
    {
        $server = new TcpServer(0);
        $peer = new Promise(function ($resolve, $reject) use ($server) {
            $server->on('connection', function (ConnectionInterface $connection) use ($resolve) {
                $resolve($connection->getRemoteAddress());
            });
        });

        $connector = new TcpConnector();
        $promise = $connector->connect($server->getAddress());

        $promise->then($this->expectCallableOnce());

        $peer = await(timeout($peer, self::TIMEOUT));
        await(sleep(0.0));

        $this->assertStringContainsString('127.0.0.1:', $peer);

        $server->close();
        $promise->then(function (ConnectionInterface $connection) {
            $connection->close();
        });
    }

    public function testEmitsConnectionWithLocalIp()
    {
        $server = new TcpServer(0);
        $peer = new Promise(function ($resolve, $reject) use ($server) {
            $server->on('connection', function (ConnectionInterface $connection) use ($resolve) {
                $resolve($connection->getLocalAddress());
            });
        });

        $connector = new TcpConnector();
        $promise = $connector->connect($server->getAddress());

        $promise->then($this->expectCallableOnce());

        $promise->then($this->expectCallableOnce());

        $local = await(timeout($peer, self::TIMEOUT));
        await(sleep(0.0));

        $this->assertStringContainsString('127.0.0.1:', $local);
        $this->assertEquals($server->getAddress(), $local);

        $server->close();
        $promise->then(function (ConnectionInterface $connection) {
            $connection->close();
        });
    }

    public function testEmitsConnectionWithLocalIpDespiteListeningOnAll()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Skipping on Windows due to default firewall rules');
        }

        $server = new TcpServer('0.0.0.0:0');
        $peer = new Promise(function ($resolve, $reject) use ($server) {
            $server->on('connection', function (ConnectionInterface $connection) use ($resolve) {
                $resolve($connection->getLocalAddress());
            });
        });

        $connector = new TcpConnector();
        $promise = $connector->connect($server->getAddress());

        $promise->then($this->expectCallableOnce());

        $local = await(timeout($peer, self::TIMEOUT));
        await(sleep(0.0));

        $this->assertStringContainsString('127.0.0.1:', $local);

        $server->close();
        $promise->then(function (ConnectionInterface $connection) {
            $connection->close();
        });
    }

    public function testEmitsConnectionWithRemoteIpAfterConnectionIsClosedByPeer()
    {
        $server = new TcpServer(0);
        $peer = new Promise(function ($resolve, $reject) use ($server) {
            $server->on('connection', function (ConnectionInterface $connection) use ($resolve) {
                $connection->on('close', function () use ($connection, $resolve) {
                    $resolve($connection->getRemoteAddress());
                });
            });
        });

        $connector = new TcpConnector();
        $connector->connect($server->getAddress())->then(function (ConnectionInterface $connection) {
            $connection->end();
        });

        $peer = await(timeout($peer, self::TIMEOUT));

        $this->assertStringContainsString('127.0.0.1:', $peer);

        $server->close();
    }

    public function testEmitsConnectionWithRemoteNullAddressAfterConnectionIsClosedByServer()
    {
        $server = new TcpServer(0);
        $peer = new Promise(function ($resolve, $reject) use ($server) {
            $server->on('connection', function (ConnectionInterface $connection) use ($resolve) {
                $connection->close();
                $resolve($connection->getRemoteAddress());
            });
        });

        $connector = new TcpConnector();
        $promise = $connector->connect($server->getAddress());

        $promise->then($this->expectCallableOnce());

        $peer = await(timeout($peer, self::TIMEOUT));
        await(sleep(0.0));

        $this->assertNull($peer);

        $server->close();
    }

    public function testEmitsConnectionEvenIfClientConnectionIsCancelled()
    {
        if (PHP_OS !== 'Linux') {
            $this->markTestSkipped('Linux only (OS is ' . PHP_OS . ')');
        }

        $server = new TcpServer(0);
        $server->on('connection', $this->expectCallableOnce());

        $peer = new Promise(function ($resolve, $reject) use ($server) {
            $server->on('connection', function () use ($resolve) {
                $resolve(null);
            });
        });

        $connector = new TcpConnector();
        $promise = $connector->connect($server->getAddress());
        $promise->cancel();

        $promise->then(null, $this->expectCallableOnce());

        await(timeout($peer, self::TIMEOUT));

        $server->close();
    }

    public function testEmitsConnectionForNewIpv6Connection()
    {
        try {
            $server = new TcpServer('[::1]:0');
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('Unable to start IPv6 server socket (not available on your platform?)');
        }

        $server->on('connection', $this->expectCallableOnce());

        $peer = new Promise(function ($resolve, $reject) use ($server) {
            $server->on('connection', function () use ($resolve) {
                $resolve(null);
            });
        });

        $connector = new TcpConnector();
        $promise = $connector->connect($server->getAddress());

        $promise->then($this->expectCallableOnce());

        await(timeout($peer, self::TIMEOUT));
        await(sleep(0.0));

        $server->close();
        $promise->then(function (ConnectionInterface $connection) {
            $connection->close();
        });
    }

    public function testEmitsConnectionWithRemoteIpv6()
    {
        try {
            $server = new TcpServer('[::1]:0');
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('Unable to start IPv6 server socket (not available on your platform?)');
        }

        $peer = new Promise(function ($resolve, $reject) use ($server) {
            $server->on('connection', function (ConnectionInterface $connection) use ($resolve) {
                $resolve($connection->getRemoteAddress());
            });
        });

        $connector = new TcpConnector();
        $promise = $connector->connect($server->getAddress());

        $promise->then($this->expectCallableOnce());

        $peer = await(timeout($peer, self::TIMEOUT));
        await(sleep(0.0));

        $this->assertStringContainsString('[::1]:', $peer);

        $server->close();
        $promise->then(function (ConnectionInterface $connection) {
            $connection->close();
        });
    }

    public function testEmitsConnectionWithLocalIpv6()
    {
        try {
            $server = new TcpServer('[::1]:0');
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('Unable to start IPv6 server socket (not available on your platform?)');
        }

        $peer = new Promise(function ($resolve, $reject) use ($server) {
            $server->on('connection', function (ConnectionInterface $connection) use ($resolve) {
                $resolve($connection->getLocalAddress());
            });
        });

        $connector = new TcpConnector();
        $promise = $connector->connect($server->getAddress());

        $promise->then($this->expectCallableOnce());

        $local = await(timeout($peer, self::TIMEOUT));
        await(sleep(0.0));

        $this->assertStringContainsString('[::1]:', $local);
        $this->assertEquals($server->getAddress(), $local);

        $server->close();
        $promise->then(function (ConnectionInterface $connection) {
            $connection->close();
        });
    }

    public function testServerPassesContextOptionsToSocket()
    {
        $server = new TcpServer(0, null, [
            'backlog' => 4
        ]);

        $ref = new \ReflectionProperty($server, 'master');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $socket = $ref->getValue($server);

        $context = stream_context_get_options($socket);

        $this->assertEquals(['socket' => ['backlog' => 4]], $context);

        $server->close();
    }

    public function testServerPassesDefaultBacklogSizeViaContextOptionsToSocket()
    {
        $server = new TcpServer(0);

        $ref = new \ReflectionProperty($server, 'master');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $socket = $ref->getValue($server);

        $context = stream_context_get_options($socket);

        $this->assertEquals(['socket' => ['backlog' => 511]], $context);

        $server->close();
    }

    public function testEmitsConnectionWithInheritedContextOptions()
    {
        $server = new TcpServer(0, null, [
            'backlog' => 4
        ]);

        $peer = new Promise(function ($resolve, $reject) use ($server) {
            $server->on('connection', function (ConnectionInterface $connection) use ($resolve) {
                $resolve(stream_context_get_options($connection->stream));
            });
        });

        $connector = new TcpConnector();
        $promise = $connector->connect($server->getAddress());

        $promise->then($this->expectCallableOnce());

        $all = await(timeout($peer, self::TIMEOUT));
        await(sleep(0.0));

        $this->assertEquals(['socket' => ['backlog' => 4]], $all);

        $server->close();
        $promise->then(function (ConnectionInterface $connection) {
            $connection->close();
        });
    }

    public function testFailsToListenOnInvalidUri()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid URI "tcp://///" given (EINVAL)');
        $this->expectExceptionCode(defined('SOCKET_EINVAL') ? SOCKET_EINVAL : (defined('PCNTL_EINVAL') ? PCNTL_EINVAL : 22));
        new TcpServer('///');
    }

    public function testFailsToListenOnUriWithoutPort()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid URI "tcp://127.0.0.1" given (EINVAL)');
        $this->expectExceptionCode(defined('SOCKET_EINVAL') ? SOCKET_EINVAL : (defined('PCNTL_EINVAL') ? PCNTL_EINVAL : 22));
        new TcpServer('127.0.0.1');
    }

    public function testFailsToListenOnUriWithWrongScheme()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid URI "udp://127.0.0.1:0" given (EINVAL)');
        $this->expectExceptionCode(defined('SOCKET_EINVAL') ? SOCKET_EINVAL : (defined('PCNTL_EINVAL') ? PCNTL_EINVAL : 22));
        new TcpServer('udp://127.0.0.1:0');
    }

    public function testFailsToListenOnUriWIthHostname()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Given URI "tcp://localhost:8080" does not contain a valid host IP (EINVAL)');
        $this->expectExceptionCode(defined('SOCKET_EINVAL') ? SOCKET_EINVAL : (defined('PCNTL_EINVAL') ? PCNTL_EINVAL : 22));
        new TcpServer('localhost:8080');
    }
}
