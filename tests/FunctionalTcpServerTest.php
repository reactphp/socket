<?php

namespace React\Tests\Socket;

use React\Promise\Promise;
use React\Socket\ConnectionInterface;
use React\Socket\TcpConnector;
use React\Socket\TcpServer;

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

        \React\Async\await(\React\Promise\Timer\timeout($peer, self::TIMEOUT));
        \React\Async\await(\React\Promise\Timer\sleep(0.0));

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

        \React\Async\await(\React\Promise\Timer\timeout($promise, self::TIMEOUT));
        \React\Async\await(\React\Promise\Timer\sleep(0.0));
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

        \React\Async\await(\React\Promise\Timer\timeout($peer, self::TIMEOUT));
        \React\Async\await(\React\Promise\Timer\sleep(0.0));

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

        $peer = \React\Async\await(\React\Promise\Timer\timeout($peer, self::TIMEOUT));
        \React\Async\await(\React\Promise\Timer\sleep(0.0));

        $this->assertContainsString('127.0.0.1:', $peer);

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

        $local = \React\Async\await(\React\Promise\Timer\timeout($peer, self::TIMEOUT));
        \React\Async\await(\React\Promise\Timer\sleep(0.0));

        $this->assertContainsString('127.0.0.1:', $local);
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

        $local = \React\Async\await(\React\Promise\Timer\timeout($peer, self::TIMEOUT));
        \React\Async\await(\React\Promise\Timer\sleep(0.0));

        $this->assertContainsString('127.0.0.1:', $local);

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

        $peer = \React\Async\await(\React\Promise\Timer\timeout($peer, self::TIMEOUT));

        $this->assertContainsString('127.0.0.1:', $peer);

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

        $peer = \React\Async\await(\React\Promise\Timer\timeout($peer, self::TIMEOUT));
        \React\Async\await(\React\Promise\Timer\sleep(0.0));

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

        \React\Async\await(\React\Promise\Timer\timeout($peer, self::TIMEOUT));

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

        \React\Async\await(\React\Promise\Timer\timeout($peer, self::TIMEOUT));
        \React\Async\await(\React\Promise\Timer\sleep(0.0));

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

        $peer = \React\Async\await(\React\Promise\Timer\timeout($peer, self::TIMEOUT));
        \React\Async\await(\React\Promise\Timer\sleep(0.0));

        $this->assertContainsString('[::1]:', $peer);

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

        $local = \React\Async\await(\React\Promise\Timer\timeout($peer, self::TIMEOUT));
        \React\Async\await(\React\Promise\Timer\sleep(0.0));

        $this->assertContainsString('[::1]:', $local);
        $this->assertEquals($server->getAddress(), $local);

        $server->close();
        $promise->then(function (ConnectionInterface $connection) {
            $connection->close();
        });
    }

    public function testServerPassesContextOptionsToSocket()
    {
        $server = new TcpServer(0, null, array(
            'backlog' => 4
        ));

        $ref = new \ReflectionProperty($server, 'master');
        $ref->setAccessible(true);
        $socket = $ref->getValue($server);

        $context = stream_context_get_options($socket);

        $this->assertEquals(array('socket' => array('backlog' => 4)), $context);

        $server->close();
    }

    public function testServerPassesDefaultBacklogSizeViaContextOptionsToSocket()
    {
        $server = new TcpServer(0);

        $ref = new \ReflectionProperty($server, 'master');
        $ref->setAccessible(true);
        $socket = $ref->getValue($server);

        $context = stream_context_get_options($socket);

        $this->assertEquals(array('socket' => array('backlog' => 511)), $context);

        $server->close();
    }

    public function testEmitsConnectionWithInheritedContextOptions()
    {
        if (defined('HHVM_VERSION') && version_compare(HHVM_VERSION, '3.13', '<')) {
            // https://3v4l.org/hB4Tc
            $this->markTestSkipped('Not supported on legacy HHVM < 3.13');
        }

        $server = new TcpServer(0, null, array(
            'backlog' => 4
        ));

        $peer = new Promise(function ($resolve, $reject) use ($server) {
            $server->on('connection', function (ConnectionInterface $connection) use ($resolve) {
                $resolve(stream_context_get_options($connection->stream));
            });
        });

        $connector = new TcpConnector();
        $promise = $connector->connect($server->getAddress());

        $promise->then($this->expectCallableOnce());

        $all = \React\Async\await(\React\Promise\Timer\timeout($peer, self::TIMEOUT));
        \React\Async\await(\React\Promise\Timer\sleep(0.0));

        $this->assertEquals(array('socket' => array('backlog' => 4)), $all);

        $server->close();
        $promise->then(function (ConnectionInterface $connection) {
            $connection->close();
        });
    }

    public function testFailsToListenOnInvalidUri()
    {
        $this->setExpectedException(
            'InvalidArgumentException',
            'Invalid URI "tcp://///" given (EINVAL)',
            defined('SOCKET_EINVAL') ? SOCKET_EINVAL : (defined('PCNTL_EINVAL') ? PCNTL_EINVAL : 22)
        );
        new TcpServer('///');
    }

    public function testFailsToListenOnUriWithoutPort()
    {
        $this->setExpectedException(
            'InvalidArgumentException',
            'Invalid URI "tcp://127.0.0.1" given (EINVAL)',
            defined('SOCKET_EINVAL') ? SOCKET_EINVAL : (defined('PCNTL_EINVAL') ? PCNTL_EINVAL : 22)
        );
        new TcpServer('127.0.0.1');
    }

    public function testFailsToListenOnUriWithWrongScheme()
    {
        $this->setExpectedException(
            'InvalidArgumentException',
            'Invalid URI "udp://127.0.0.1:0" given (EINVAL)',
            defined('SOCKET_EINVAL') ? SOCKET_EINVAL : (defined('PCNTL_EINVAL') ? PCNTL_EINVAL : 22)
        );
        new TcpServer('udp://127.0.0.1:0');
    }

    public function testFailsToListenOnUriWIthHostname()
    {
        $this->setExpectedException(
            'InvalidArgumentException',
            'Given URI "tcp://localhost:8080" does not contain a valid host IP (EINVAL)',
            defined('SOCKET_EINVAL') ? SOCKET_EINVAL : (defined('PCNTL_EINVAL') ? PCNTL_EINVAL : 22)
        );
        new TcpServer('localhost:8080');
    }
}
