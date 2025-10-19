<?php

namespace React\Tests\Socket;

use React\EventLoop\LoopInterface;
use React\Promise\Promise;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use React\Socket\TcpConnector;
use React\Socket\UnixConnector;
use function React\Async\await;
use function React\Promise\Timer\sleep;
use function React\Promise\Timer\timeout;

class SocketServerTest extends TestCase
{
    const TIMEOUT = 0.1;

    public function testConstructWithoutLoopAssignsLoopAutomatically()
    {
        $socket = new SocketServer('127.0.0.1:0');
        $socket->close();

        $ref = new \ReflectionProperty($socket, 'server');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $tcp = $ref->getValue($socket);

        $ref = new \ReflectionProperty($tcp, 'loop');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $loop = $ref->getValue($tcp);

        $this->assertInstanceOf(LoopInterface::class, $loop);
    }

    public function testCreateServerWithZeroPortAssignsRandomPort()
    {
        $socket = new SocketServer('127.0.0.1:0', []);
        $this->assertNotEquals(0, $socket->getAddress());
        $socket->close();
    }

    public function testConstructorWithInvalidUriThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid URI "tcp://invalid URI" given (EINVAL)');
        $this->expectExceptionCode(defined('SOCKET_EINVAL') ? SOCKET_EINVAL : (defined('PCNTL_EINVAL') ? PCNTL_EINVAL : 22));
        new SocketServer('invalid URI');
    }

    public function testConstructorWithInvalidUriWithPortOnlyThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid URI given (EINVAL)');
        $this->expectExceptionCode(defined('SOCKET_EINVAL') ? SOCKET_EINVAL : (defined('PCNTL_EINVAL') ? PCNTL_EINVAL : 22));
        new SocketServer('0');
    }

    public function testConstructorWithInvalidUriWithSchemaAndPortOnlyThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid URI given (EINVAL)');
        $this->expectExceptionCode(defined('SOCKET_EINVAL') ? SOCKET_EINVAL : (defined('PCNTL_EINVAL') ? PCNTL_EINVAL : 22));
        new SocketServer('tcp://0');
    }

    public function testConstructorCreatesExpectedTcpServer()
    {
        $socket = new SocketServer('127.0.0.1:0', []);

        $connector = new TcpConnector();
        $promise = $connector->connect($socket->getAddress());
        $promise->then($this->expectCallableOnce(), $this->expectCallableNever());

        $connection = await(timeout($connector->connect($socket->getAddress()), self::TIMEOUT));

        $socket->close();
        $promise->then(function (ConnectionInterface $connection) {
            $connection->close();
        });
    }

    public function testConstructorCreatesExpectedUnixServer()
    {
        if (!in_array('unix', stream_get_transports())) {
            $this->markTestSkipped('Unix domain sockets (UDS) not supported on your platform (Windows?)');
        }

        $socket = new SocketServer($this->getRandomSocketUri(), []);

        $connector = new UnixConnector();
        $connector->connect($socket->getAddress())
            ->then($this->expectCallableOnce(), $this->expectCallableNever());

        $connection = await(timeout($connector->connect($socket->getAddress()), self::TIMEOUT));
        assert($connection instanceof ConnectionInterface);

        unlink(str_replace('unix://', '', $connection->getRemoteAddress()));

        $connection->close();
        $socket->close();
    }

    public function testConstructorThrowsForExistingUnixPath()
    {
        if (!in_array('unix', stream_get_transports())) {
            $this->markTestSkipped('Unix domain sockets (UDS) not supported on your platform (Windows?)');
        }

        try {
            new SocketServer('unix://' . __FILE__, []);
            $this->fail();
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 0) {
                // Zend PHP does not currently report a sane error
                $this->assertStringEndsWith('Unknown error', $e->getMessage());
            } else {
                $this->assertEquals(SOCKET_EADDRINUSE, $e->getCode());
                $this->assertStringEndsWith('Address already in use (EADDRINUSE)', $e->getMessage());
            }
        }
    }

    public function testConstructWithExistingFileDescriptorReturnsSameAddressAsOriginalSocketForIpv4Socket()
    {
        if (!is_dir('/dev/fd')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $fd = FdServerTest::getNextFreeFd();
        $socket = stream_socket_server('127.0.0.1:0');

        $server = new SocketServer('php://fd/' . $fd);
        $server->pause();

        $this->assertEquals('tcp://' . stream_socket_get_name($socket, false), $server->getAddress());
    }

    public function testEmitsErrorWhenUnderlyingTcpServerEmitsError()
    {
        $socket = new SocketServer('127.0.0.1:0', []);

        $ref = new \ReflectionProperty($socket, 'server');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        $tcp = $ref->getvalue($socket);

        $error = new \RuntimeException();
        $socket->on('error', $this->expectCallableOnceWith($error));
        $tcp->emit('error', [$error]);

        $socket->close();
    }

    public function testEmitsConnectionForNewConnection()
    {
        $socket = new SocketServer('127.0.0.1:0', []);
        $socket->on('connection', $this->expectCallableOnce());

        $peer = new Promise(function ($resolve, $reject) use ($socket) {
            $socket->on('connection', function () use ($resolve) {
                $resolve(null);
            });
        });

        $client = stream_socket_client($socket->getAddress());

        await(timeout($peer, self::TIMEOUT));

        $socket->close();
    }

    public function testDoesNotEmitConnectionForNewConnectionToPausedServer()
    {
        $socket = new SocketServer('127.0.0.1:0', []);
        $socket->pause();
        $socket->on('connection', $this->expectCallableNever());

        $client = stream_socket_client($socket->getAddress());

        await(sleep(0.1));
    }

    public function testDoesEmitConnectionForNewConnectionToResumedServer()
    {
        $socket = new SocketServer('127.0.0.1:0', []);
        $socket->pause();
        $socket->on('connection', $this->expectCallableOnce());

        $peer = new Promise(function ($resolve, $reject) use ($socket) {
            $socket->on('connection', function () use ($resolve) {
                $resolve(null);
            });
        });

        $client = stream_socket_client($socket->getAddress());

        $socket->resume();

        await(timeout($peer, self::TIMEOUT));

        $socket->close();
    }

    public function testDoesNotAllowConnectionToClosedServer()
    {
        $socket = new SocketServer('127.0.0.1:0', []);
        $socket->on('connection', $this->expectCallableNever());
        $address = $socket->getAddress();
        $socket->close();

        $client = @stream_socket_client($address);

        $this->assertFalse($client);
    }

    public function testEmitsConnectionWithInheritedContextOptions()
    {
        $socket = new SocketServer('127.0.0.1:0', [
            'tcp' => [
                'backlog' => 4
            ]
        ]);

        $peer = new Promise(function ($resolve, $reject) use ($socket) {
            $socket->on('connection', function (ConnectionInterface $connection) use ($resolve) {
                $resolve(stream_context_get_options($connection->stream));
            });
        });


        $client = stream_socket_client($socket->getAddress());

        $all = await(timeout($peer, self::TIMEOUT));

        $this->assertEquals(['socket' => ['backlog' => 4]], $all);

        $socket->close();
    }

    public function testDoesNotEmitSecureConnectionForNewPlaintextConnectionThatIsIdle()
    {
        $socket = new SocketServer('tls://127.0.0.1:0', [
            'tls' => [
                'local_cert' => __DIR__ . '/../examples/localhost.pem'
            ]
        ]);
        $socket->on('connection', $this->expectCallableNever());

        $client = stream_socket_client(str_replace('tls://', '', $socket->getAddress()));

        await(sleep(0.1));

        $socket->close();
    }

    private function getRandomSocketUri()
    {
        return "unix://" . sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid(rand(), true) . '.sock';
    }
}
