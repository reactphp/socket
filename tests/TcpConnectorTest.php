<?php

namespace React\Tests\Socket;

use Clue\React\Block;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\TcpConnector;
use React\Socket\TcpServer;
use React\Promise\Promise;

class TcpConnectorTest extends TestCase
{
    const TIMEOUT = 5.0;

    public function testConstructWithoutLoopAssignsLoopAutomatically()
    {
        $connector = new TcpConnector();

        $ref = new \ReflectionProperty($connector, 'loop');
        $ref->setAccessible(true);
        $loop = $ref->getValue($connector);

        $this->assertInstanceOf('React\EventLoop\LoopInterface', $loop);
    }

    /** @test */
    public function connectionToEmptyPortShouldFail()
    {
        $connector = new TcpConnector();
        $promise = $connector->connect('127.0.0.1:9999');

        $this->setExpectedException(
            'RuntimeException',
            'Connection to tcp://127.0.0.1:9999 failed: Connection refused' . (function_exists('socket_import_stream') ? ' (ECONNREFUSED)' : ''),
            defined('SOCKET_ECONNREFUSED') ? SOCKET_ECONNREFUSED : 111
        );
        Block\await($promise, null, self::TIMEOUT);
    }

    /** @test */
    public function connectionToTcpServerShouldAddResourceToLoop()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connector = new TcpConnector($loop);

        $server = new TcpServer(0, $loop);

        $valid = false;
        $loop->expects($this->once())->method('addWriteStream')->with($this->callback(function ($arg) use (&$valid) {
            $valid = is_resource($arg);
            return true;
        }));
        $connector->connect($server->getAddress());

        $this->assertTrue($valid);
    }

    /** @test */
    public function connectionToTcpServerShouldSucceed()
    {
        $server = new TcpServer(9999);

        $connector = new TcpConnector();

        $connection = Block\await($connector->connect('127.0.0.1:9999'), null, self::TIMEOUT);

        $this->assertInstanceOf('React\Socket\ConnectionInterface', $connection);

        $connection->close();
        $server->close();
    }

    /** @test */
    public function connectionToTcpServerShouldFailIfFileDescriptorsAreExceeded()
    {
        $connector = new TcpConnector();

        /** @var string[] $_ */
        /** @var int $exit */
        $ulimit = exec('ulimit -n 2>&1', $_, $exit);
        if ($exit !== 0 || $ulimit < 1) {
            $this->markTestSkipped('Unable to determine limit of open files (ulimit not available?)');
        }

        $memory = ini_get('memory_limit');
        if ($memory === '-1') {
            $memory = PHP_INT_MAX;
        } elseif (preg_match('/^\d+G$/i', $memory)) {
            $memory = ((int) $memory) * 1024 * 1024 * 1024;
        } elseif (preg_match('/^\d+M$/i', $memory)) {
            $memory = ((int) $memory) * 1024 * 1024;
        } elseif (preg_match('/^\d+K$/i', $memory)) {
            $memory = ((int) $memory) * 1024;
        }

        // each file descriptor takes ~600 bytes of memory, so skip test if this would exceed memory_limit
        if ($ulimit * 600 > $memory) {
            $this->markTestSkipped('Test requires ~' . round($ulimit * 600 / 1024 / 1024) . '/' . round($memory / 1024 / 1024) . ' MiB memory with ' . $ulimit . ' file descriptors');
        }

        // dummy rejected promise to make sure autoloader has initialized all classes
        class_exists('React\Socket\SocketServer', true);
        class_exists('PHPUnit\Framework\Error\Warning', true);
        new Promise(function () { throw new \RuntimeException('dummy'); });

        // keep creating dummy file handles until all file descriptors are exhausted
        $fds = array();
        for ($i = 0; $i < $ulimit; ++$i) {
            $fd = @fopen('/dev/null', 'r');
            if ($fd === false) {
                break;
            }
            $fds[] = $fd;
        }

        $this->setExpectedException('RuntimeException');
        Block\await($connector->connect('127.0.0.1:9999'), null, self::TIMEOUT);
    }

    /** @test */
    public function connectionToInvalidNetworkShouldFailWithUnreachableError()
    {
        if (PHP_OS !== 'Linux' && !function_exists('socket_import_stream')) {
            $this->markTestSkipped('Test requires either Linux or ext-sockets on PHP 5.4+');
        }

        $enetunreach = defined('SOCKET_ENETUNREACH') ? SOCKET_ENETUNREACH : 101;

        // try to find an unreachable network by trying a couple of private network addresses
        $errno = 0;
        $errstr = '';
        for ($i = 0; $i < 20 && $errno !== $enetunreach; ++$i) {
            $address = 'tcp://192.168.' . mt_rand(0, 255) . '.' . mt_rand(1, 254) . ':8123';
            $client = @stream_socket_client($address, $errno, $errstr, 0.1 * $i);
        }
        if ($client || $errno !== $enetunreach) {
            $this->markTestSkipped('Expected error ' . $enetunreach . ' but got ' . $errno . ' (' . $errstr . ') for ' . $address);
        }

        $connector = new TcpConnector();

        $promise = $connector->connect($address);

        $this->setExpectedException(
            'RuntimeException',
            'Connection to ' . $address . ' failed: ' . (function_exists('socket_strerror') ? socket_strerror($enetunreach) . ' (ENETUNREACH)' : 'Network is unreachable'),
            $enetunreach
        );

        try {
            Block\await($promise, null, self::TIMEOUT);
        } catch (\Exception $e) {
            fclose($client);

            throw $e;
        }
    }

    /** @test */
    public function connectionToTcpServerShouldSucceedWithRemoteAdressSameAsTarget()
    {
        $server = new TcpServer(9999);

        $connector = new TcpConnector();

        $connection = Block\await($connector->connect('127.0.0.1:9999'), null, self::TIMEOUT);
        /* @var $connection ConnectionInterface */

        $this->assertEquals('tcp://127.0.0.1:9999', $connection->getRemoteAddress());

        $connection->close();
        $server->close();
    }

    /** @test */
    public function connectionToTcpServerShouldSucceedWithLocalAdressOnLocalhost()
    {
        $server = new TcpServer(9999);

        $connector = new TcpConnector();

        $connection = Block\await($connector->connect('127.0.0.1:9999'), null, self::TIMEOUT);
        /* @var $connection ConnectionInterface */

        $this->assertContainsString('tcp://127.0.0.1:', $connection->getLocalAddress());
        $this->assertNotEquals('tcp://127.0.0.1:9999', $connection->getLocalAddress());

        $connection->close();
        $server->close();
    }

    /** @test */
    public function connectionToTcpServerShouldSucceedWithNullAddressesAfterConnectionClosed()
    {
        $server = new TcpServer(9999);

        $connector = new TcpConnector();

        $connection = Block\await($connector->connect('127.0.0.1:9999'), null, self::TIMEOUT);
        /* @var $connection ConnectionInterface */

        $server->close();
        $connection->close();

        $this->assertNull($connection->getRemoteAddress());
        $this->assertNull($connection->getLocalAddress());
    }

    /** @test */
    public function connectionToTcpServerWillCloseWhenOtherSideCloses()
    {
        // immediately close connection and server once connection is in
        $server = new TcpServer(0);
        $server->on('connection', function (ConnectionInterface $conn) use ($server) {
            $conn->close();
            $server->close();
        });

        $once = $this->expectCallableOnce();
        $connector = new TcpConnector();
        $connector->connect($server->getAddress())->then(function (ConnectionInterface $conn) use ($once) {
            $conn->write('hello');
            $conn->on('close', $once);
        });

        Loop::run();
    }

    /** @test
     *  @group test
     */
    public function connectionToEmptyIp6PortShouldFail()
    {
        $connector = new TcpConnector();
        $connector
            ->connect('[::1]:9999')
            ->then($this->expectCallableNever(), $this->expectCallableOnce());

        Loop::run();
    }

    /** @test */
    public function connectionToIp6TcpServerShouldSucceed()
    {
        try {
            $server = new TcpServer('[::1]:9999');
        } catch (\Exception $e) {
            $this->markTestSkipped('Unable to start IPv6 server socket (IPv6 not supported on this system?)');
        }

        $connector = new TcpConnector();

        $connection = Block\await($connector->connect('[::1]:9999'), null, self::TIMEOUT);
        /* @var $connection ConnectionInterface */

        $this->assertEquals('tcp://[::1]:9999', $connection->getRemoteAddress());

        $this->assertContainsString('tcp://[::1]:', $connection->getLocalAddress());
        $this->assertNotEquals('tcp://[::1]:9999', $connection->getLocalAddress());

        $connection->close();
        $server->close();
    }

    /** @test */
    public function connectionToHostnameShouldFailImmediately()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connector = new TcpConnector($loop);
        $promise = $connector->connect('www.google.com:80');

        $promise->then(null, $this->expectCallableOnceWithException(
            'InvalidArgumentException',
            'Given URI "tcp://www.google.com:80" does not contain a valid host IP (EINVAL)',
            defined('SOCKET_EINVAL') ? SOCKET_EINVAL : 22
        ));
    }

    /** @test */
    public function connectionToInvalidPortShouldFailImmediately()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connector = new TcpConnector($loop);
        $promise = $connector->connect('255.255.255.255:12345678');

        $promise->then(null, $this->expectCallableOnceWithException(
            'InvalidArgumentException',
            'Given URI "tcp://255.255.255.255:12345678" is invalid (EINVAL)',
            defined('SOCKET_EINVAL') ? SOCKET_EINVAL : 22
        ));
    }

    /** @test */
    public function connectionToInvalidSchemeShouldFailImmediately()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connector = new TcpConnector($loop);
        $connector->connect('tls://google.com:443')->then(
            $this->expectCallableNever(),
            $this->expectCallableOnce()
        );
    }

    /** @test */
    public function cancellingConnectionShouldRemoveResourceFromLoopAndCloseResource()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connector = new TcpConnector($loop);

        $server = new TcpServer(0, $loop);
        $server->on('connection', $this->expectCallableNever());

        $loop->expects($this->once())->method('addWriteStream');
        $promise = $connector->connect($server->getAddress());

        $resource = null;
        $valid = false;
        $loop->expects($this->once())->method('removeWriteStream')->with($this->callback(function ($arg) use (&$resource, &$valid) {
            $resource = $arg;
            $valid = is_resource($arg);
            return true;
        }));
        $promise->cancel();

        // ensure that this was a valid resource during the removeWriteStream() call
        $this->assertTrue($valid);

        // ensure that this resource should now be closed after the cancel() call
        $this->assertFalse(is_resource($resource));
    }

    /** @test */
    public function cancellingConnectionShouldRejectPromise()
    {
        $connector = new TcpConnector();

        $server = new TcpServer(0);

        $promise = $connector->connect($server->getAddress());
        $promise->cancel();

        $this->setExpectedException(
            'RuntimeException',
            'Connection to ' . $server->getAddress() . ' cancelled during TCP/IP handshake (ECONNABORTED)',
            defined('SOCKET_ECONNABORTED') ? SOCKET_ECONNABORTED : 103
        );

        try {
            Block\await($promise);
        } catch (\Exception $e) {
            $server->close();
            throw $e;
        }
    }

    public function testCancelDuringConnectionShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        gc_collect_cycles();
        gc_collect_cycles(); // clear twice to avoid leftovers in PHP 7.4 with ext-xdebug and code coverage turned on

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connector = new TcpConnector($loop);
        $promise = $connector->connect('127.0.0.1:9999');

        $promise->cancel();
        unset($promise);

        $this->assertEquals(0, gc_collect_cycles());
    }
}
