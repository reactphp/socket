<?php

namespace React\Tests\Socket;

use Clue\React\Block;
use React\EventLoop\Loop;
use React\Promise\Promise;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use React\Socket\TcpConnector;
use React\Socket\UnixConnector;

class SocketServerTest extends TestCase
{
    const TIMEOUT = 0.1;

    public function testConstructWithoutLoopAssignsLoopAutomatically()
    {
        $socket = new SocketServer('127.0.0.1:0');
        $socket->close();

        $ref = new \ReflectionProperty($socket, 'server');
        $ref->setAccessible(true);
        $tcp = $ref->getValue($socket);

        $ref = new \ReflectionProperty($tcp, 'loop');
        $ref->setAccessible(true);
        $loop = $ref->getValue($tcp);

        $this->assertInstanceOf('React\EventLoop\LoopInterface', $loop);
    }

    public function testCreateServerWithZeroPortAssignsRandomPort()
    {
        $socket = new SocketServer('127.0.0.1:0', array());
        $this->assertNotEquals(0, $socket->getAddress());
        $socket->close();
    }

    public function testConstructorWithInvalidUriThrows()
    {
        $this->setExpectedException(
            'InvalidArgumentException',
            'Invalid URI "tcp://invalid URI" given (EINVAL)',
            defined('SOCKET_EINVAL') ? SOCKET_EINVAL : 22
        );
        new SocketServer('invalid URI');
    }

    public function testConstructorWithInvalidUriWithPortOnlyThrows()
    {
        $this->setExpectedException(
            'InvalidArgumentException',
            'Invalid URI given (EINVAL)',
            defined('SOCKET_EINVAL') ? SOCKET_EINVAL : 22
        );
        new SocketServer('0');
    }

    public function testConstructorWithInvalidUriWithSchemaAndPortOnlyThrows()
    {
        $this->setExpectedException(
            'InvalidArgumentException',
            'Invalid URI given (EINVAL)',
            defined('SOCKET_EINVAL') ? SOCKET_EINVAL : 22
        );
        new SocketServer('tcp://0');
    }

    public function testConstructorCreatesExpectedTcpServer()
    {
        $loop = Loop::get();

        $socket = new SocketServer('127.0.0.1:0', array());

        $connector = new TcpConnector();
        $promise = $connector->connect($socket->getAddress());
        $promise->then($this->expectCallableOnce(), $this->expectCallableNever());

        $connection = Block\await($connector->connect($socket->getAddress()), $loop, self::TIMEOUT);

        $socket->close();
        $promise->then(function (ConnectionInterface $connection) {
            $connection->close();
        });
    }

    public function testConstructorCreatesExpectedUnixServer()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on legacy HHVM');
        }
        if (!in_array('unix', stream_get_transports())) {
            $this->markTestSkipped('Unix domain sockets (UDS) not supported on your platform (Windows?)');
        }

        $loop = Loop::get();

        $socket = new SocketServer($this->getRandomSocketUri(), array());

        $connector = new UnixConnector();
        $connector->connect($socket->getAddress())
            ->then($this->expectCallableOnce(), $this->expectCallableNever());

        $connection = Block\await($connector->connect($socket->getAddress()), $loop, self::TIMEOUT);

        $socket->close();
    }

    public function testConstructorThrowsForExistingUnixPath()
    {
        if (!in_array('unix', stream_get_transports())) {
            $this->markTestSkipped('Unix domain sockets (UDS) not supported on your platform (Windows?)');
        }

        try {
            new SocketServer('unix://' . __FILE__, array());
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
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
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
        $socket = new SocketServer('127.0.0.1:0', array());

        $ref = new \ReflectionProperty($socket, 'server');
        $ref->setAccessible(true);
        $tcp = $ref->getvalue($socket);

        $error = new \RuntimeException();
        $socket->on('error', $this->expectCallableOnceWith($error));
        $tcp->emit('error', array($error));

        $socket->close();
    }

    public function testEmitsConnectionForNewConnection()
    {
        $loop = Loop::get();

        $socket = new SocketServer('127.0.0.1:0', array());
        $socket->on('connection', $this->expectCallableOnce());

        $peer = new Promise(function ($resolve, $reject) use ($socket) {
            $socket->on('connection', $resolve);
        });

        $client = stream_socket_client($socket->getAddress());

        Block\await($peer, $loop, self::TIMEOUT);

        $socket->close();
    }

    public function testDoesNotEmitConnectionForNewConnectionToPausedServer()
    {
        $loop = Loop::get();

        $socket = new SocketServer('127.0.0.1:0', array());
        $socket->pause();
        $socket->on('connection', $this->expectCallableNever());

        $client = stream_socket_client($socket->getAddress());

        Block\sleep(0.1, $loop);
    }

    public function testDoesEmitConnectionForNewConnectionToResumedServer()
    {
        $loop = Loop::get();

        $socket = new SocketServer('127.0.0.1:0', array());
        $socket->pause();
        $socket->on('connection', $this->expectCallableOnce());

        $peer = new Promise(function ($resolve, $reject) use ($socket) {
            $socket->on('connection', $resolve);
        });

        $client = stream_socket_client($socket->getAddress());

        $socket->resume();

        Block\await($peer, $loop, self::TIMEOUT);

        $socket->close();
    }

    public function testDoesNotAllowConnectionToClosedServer()
    {
        $socket = new SocketServer('127.0.0.1:0', array());
        $socket->on('connection', $this->expectCallableNever());
        $address = $socket->getAddress();
        $socket->close();

        $client = @stream_socket_client($address);

        $this->assertFalse($client);
    }

    public function testEmitsConnectionWithInheritedContextOptions()
    {
        if (defined('HHVM_VERSION') && version_compare(HHVM_VERSION, '3.13', '<')) {
            // https://3v4l.org/hB4Tc
            $this->markTestSkipped('Not supported on legacy HHVM < 3.13');
        }

        $loop = Loop::get();

        $socket = new SocketServer('127.0.0.1:0', array(
            'tcp' => array(
                'backlog' => 4
            )
        ));

        $peer = new Promise(function ($resolve, $reject) use ($socket) {
            $socket->on('connection', function (ConnectionInterface $connection) use ($resolve) {
                $resolve(stream_context_get_options($connection->stream));
            });
        });


        $client = stream_socket_client($socket->getAddress());

        $all = Block\await($peer, $loop, self::TIMEOUT);

        $this->assertEquals(array('socket' => array('backlog' => 4)), $all);

        $socket->close();
    }

    public function testDoesNotEmitSecureConnectionForNewPlaintextConnectionThatIsIdle()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on legacy HHVM');
        }

        $loop = Loop::get();

        $socket = new SocketServer('tls://127.0.0.1:0', array(
            'tls' => array(
                'local_cert' => __DIR__ . '/../examples/localhost.pem'
            )
        ));
        $socket->on('connection', $this->expectCallableNever());

        $client = stream_socket_client(str_replace('tls://', '', $socket->getAddress()));

        Block\sleep(0.1, $loop);

        $socket->close();
    }

    private function getRandomSocketUri()
    {
        return "unix://" . sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid(rand(), true) . '.sock';
    }
}
