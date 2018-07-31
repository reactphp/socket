<?php

namespace React\Tests\Socket;

use React\EventLoop\Factory;
use React\Socket\Server;
use React\Socket\TcpConnector;
use React\Socket\UnixConnector;
use Clue\React\Block;
use React\Socket\ConnectionInterface;

class ServerTest extends TestCase
{
    const TIMEOUT = 0.1;

    public function testCreateServerWithZeroPortAssignsRandomPort()
    {
        $loop = Factory::create();

        $server = new Server(0, $loop);
        $this->assertNotEquals(0, $server->getAddress());
        $server->close();
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testConstructorThrowsForInvalidUri()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $server = new Server('invalid URI', $loop);
    }

    public function testConstructorCreatesExpectedTcpServer()
    {
        $loop = Factory::create();

        $server = new Server(0, $loop);

        $connector = new TcpConnector($loop);
        $connector->connect($server->getAddress())
            ->then($this->expectCallableOnce(), $this->expectCallableNever());

        $connection = Block\await($connector->connect($server->getAddress()), $loop, self::TIMEOUT);

        $connection->close();
        $server->close();
    }

    public function testConstructorCreatesExpectedUnixServer()
    {
        if (!in_array('unix', stream_get_transports())) {
            $this->markTestSkipped('Unix domain sockets (UDS) not supported on your platform (Windows?)');
        }

        $loop = Factory::create();

        $server = new Server($this->getRandomSocketUri(), $loop);

        $connector = new UnixConnector($loop);
        $connector->connect($server->getAddress())
            ->then($this->expectCallableOnce(), $this->expectCallableNever());

        $connection = Block\await($connector->connect($server->getAddress()), $loop, self::TIMEOUT);

        $connection->close();
        $server->close();
    }

    public function testConstructorThrowsForExistingUnixPath()
    {
        if (!in_array('unix', stream_get_transports())) {
            $this->markTestSkipped('Unix domain sockets (UDS) not supported on your platform (Windows?)');
        }

        $loop = Factory::create();

        try {
            $server = new Server('unix://' . __FILE__, $loop);
            $this->fail();
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 0) {
                // Zend PHP does not currently report a sane error
                $this->assertStringEndsWith('Unknown error', $e->getMessage());
            } else {
                $this->assertEquals(SOCKET_EADDRINUSE, $e->getCode());
                $this->assertStringEndsWith('Address already in use', $e->getMessage());
            }
        }
    }

    public function testEmitsConnectionForNewConnection()
    {
        $loop = Factory::create();

        $server = new Server(0, $loop);
        $server->on('connection', $this->expectCallableOnce());

        $client = stream_socket_client($server->getAddress());

        Block\sleep(0.1, $loop);
    }

    public function testDoesNotEmitConnectionForNewConnectionToPausedServer()
    {
        $loop = Factory::create();

        $server = new Server(0, $loop);
        $server->pause();
        $server->on('connection', $this->expectCallableNever());

        $client = stream_socket_client($server->getAddress());

        Block\sleep(0.1, $loop);
    }

    public function testDoesEmitConnectionForNewConnectionToResumedServer()
    {
        $loop = Factory::create();

        $server = new Server(0, $loop);
        $server->pause();
        $server->on('connection', $this->expectCallableOnce());

        $client = stream_socket_client($server->getAddress());

        Block\sleep(0.1, $loop);

        $server->resume();
        Block\sleep(0.1, $loop);
    }

    public function testDoesNotAllowConnectionToClosedServer()
    {
        $loop = Factory::create();

        $server = new Server(0, $loop);
        $server->on('connection', $this->expectCallableNever());
        $address = $server->getAddress();
        $server->close();

        $client = @stream_socket_client($address);

        Block\sleep(0.1, $loop);

        $this->assertFalse($client);
    }

    public function testEmitsConnectionWithInheritedContextOptions()
    {
        if (defined('HHVM_VERSION') && version_compare(HHVM_VERSION, '3.13', '<')) {
            // https://3v4l.org/hB4Tc
            $this->markTestSkipped('Not supported on legacy HHVM < 3.13');
        }

        $loop = Factory::create();

        $server = new Server(0, $loop, array(
            'backlog' => 4
        ));

        $all = null;
        $server->on('connection', function (ConnectionInterface $conn) use (&$all) {
            $all = stream_context_get_options($conn->stream);
        });

        $client = stream_socket_client($server->getAddress());

        Block\sleep(0.1, $loop);

        $this->assertEquals(array('socket' => array('backlog' => 4)), $all);
    }

    public function testDoesNotEmitSecureConnectionForNewPlainConnection()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('Not supported on your platform (outdated HHVM?)');
        }

        $loop = Factory::create();

        $server = new Server('tls://127.0.0.1:0', $loop, array(
            'tls' => array(
                'local_cert' => __DIR__ . '/../examples/localhost.pem'
            )
        ));
        $server->on('connection', $this->expectCallableNever());

        $client = stream_socket_client(str_replace('tls://', '', $server->getAddress()));

        Block\sleep(0.1, $loop);
    }

    private function getRandomSocketUri()
    {
        return "unix://" . sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid(rand(), true) . '.sock';
    }
}
