<?php

namespace React\Tests\Socket;

use React\Promise\Promise;
use React\Socket\ConnectionInterface;
use React\Socket\Server;
use React\Socket\TcpConnector;
use React\Socket\UnixConnector;

class ServerTest extends TestCase
{
    const TIMEOUT = 0.1;

    public function testConstructWithoutLoopAssignsLoopAutomatically()
    {
        $server = new Server(0);

        $ref = new \ReflectionProperty($server, 'server');
        $ref->setAccessible(true);
        $tcp = $ref->getValue($server);

        $ref = new \ReflectionProperty($tcp, 'loop');
        $ref->setAccessible(true);
        $loop = $ref->getValue($tcp);

        $this->assertInstanceOf('React\EventLoop\LoopInterface', $loop);

        $server->close();
    }

    public function testCreateServerWithZeroPortAssignsRandomPort()
    {
        $server = new Server(0);
        $this->assertNotEquals(0, $server->getAddress());
        $server->close();
    }

    public function testConstructorThrowsForInvalidUri()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $this->setExpectedException('InvalidArgumentException');
        $server = new Server('invalid URI', $loop);
    }

    public function testConstructorCreatesExpectedTcpServer()
    {
        $server = new Server(0);

        $connector = new TcpConnector();
        $promise = $connector->connect($server->getAddress());
        $promise->then($this->expectCallableOnce(), $this->expectCallableNever());

        $connection = \React\Async\await(\React\Promise\Timer\timeout($connector->connect($server->getAddress()), self::TIMEOUT));

        $server->close();
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

        $server = new Server($this->getRandomSocketUri());

        $connector = new UnixConnector();
        $connector->connect($server->getAddress())
            ->then($this->expectCallableOnce(), $this->expectCallableNever());

        $connection = \React\Async\await(\React\Promise\Timer\timeout($connector->connect($server->getAddress()), self::TIMEOUT));
        assert($connection instanceof ConnectionInterface);

        unlink(str_replace('unix://', '', $connection->getRemoteAddress()));

        $connection->close();
        $server->close();
    }

    public function testConstructorThrowsForExistingUnixPath()
    {
        if (!in_array('unix', stream_get_transports())) {
            $this->markTestSkipped('Unix domain sockets (UDS) not supported on your platform (Windows?)');
        }

        try {
            $server = new Server('unix://' . __FILE__);
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

    public function testEmitsErrorWhenUnderlyingTcpServerEmitsError()
    {
        $server = new Server(0);

        $ref = new \ReflectionProperty($server, 'server');
        $ref->setAccessible(true);
        $tcp = $ref->getvalue($server);

        $error = new \RuntimeException();
        $server->on('error', $this->expectCallableOnceWith($error));
        $tcp->emit('error', array($error));

        $server->close();
    }

    public function testEmitsConnectionForNewConnection()
    {
        $server = new Server(0);
        $server->on('connection', $this->expectCallableOnce());

        $peer = new Promise(function ($resolve, $reject) use ($server) {
            $server->on('connection', function () use ($resolve) {
                $resolve(null);
            });
        });

        $client = stream_socket_client($server->getAddress());

        \React\Async\await(\React\Promise\Timer\timeout($peer, self::TIMEOUT));

        $server->close();
    }

    public function testDoesNotEmitConnectionForNewConnectionToPausedServer()
    {
        $server = new Server(0);
        $server->pause();
        $server->on('connection', $this->expectCallableNever());

        $client = stream_socket_client($server->getAddress());

        \React\Async\await(\React\Promise\Timer\sleep(0.1));
    }

    public function testDoesEmitConnectionForNewConnectionToResumedServer()
    {
        $server = new Server(0);
        $server->pause();
        $server->on('connection', $this->expectCallableOnce());

        $peer = new Promise(function ($resolve, $reject) use ($server) {
            $server->on('connection', function () use ($resolve) {
                $resolve(null);
            });
        });

        $client = stream_socket_client($server->getAddress());

        $server->resume();

        \React\Async\await(\React\Promise\Timer\timeout($peer, self::TIMEOUT));

        $server->close();
    }

    public function testDoesNotAllowConnectionToClosedServer()
    {
        $server = new Server(0);
        $server->on('connection', $this->expectCallableNever());
        $address = $server->getAddress();
        $server->close();

        $client = @stream_socket_client($address);

        $this->assertFalse($client);
    }

    public function testEmitsConnectionWithInheritedContextOptions()
    {
        if (defined('HHVM_VERSION') && version_compare(HHVM_VERSION, '3.13', '<')) {
            // https://3v4l.org/hB4Tc
            $this->markTestSkipped('Not supported on legacy HHVM < 3.13');
        }

        $server = new Server(0, null, array(
            'backlog' => 4
        ));

        $peer = new Promise(function ($resolve, $reject) use ($server) {
            $server->on('connection', function (ConnectionInterface $connection) use ($resolve) {
                $resolve(stream_context_get_options($connection->stream));
            });
        });


        $client = stream_socket_client($server->getAddress());

        $all = \React\Async\await(\React\Promise\Timer\timeout($peer, self::TIMEOUT));

        $this->assertEquals(array('socket' => array('backlog' => 4)), $all);

        $server->close();
    }

    public function testDoesNotEmitSecureConnectionForNewPlaintextConnectionThatIsIdle()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on legacy HHVM');
        }

        $server = new Server('tls://127.0.0.1:0', null, array(
            'tls' => array(
                'local_cert' => __DIR__ . '/../examples/localhost.pem'
            )
        ));
        $server->on('connection', $this->expectCallableNever());

        $client = stream_socket_client(str_replace('tls://', '', $server->getAddress()));

        \React\Async\await(\React\Promise\Timer\sleep(0.1));

        $server->close();
    }

    private function getRandomSocketUri()
    {
        return "unix://" . sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid(rand(), true) . '.sock';
    }
}
