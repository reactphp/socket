<?php

namespace React\Tests\Socket;

use React\Promise\Promise;
use React\Socket\ConnectionInterface;
use React\Socket\FdServer;

class FdServerTest extends TestCase
{
    public function testCtorAddsResourceToLoop()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $fd = self::getNextFreeFd();
        $socket = stream_socket_server('127.0.0.1:0');
        assert($socket !== false);

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addReadStream');

        new FdServer($fd, $loop);
    }

    public function testCtorThrowsForInvalidFd()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addReadStream');

        $this->setExpectedException(
            'InvalidArgumentException',
            'Invalid FD number given (EINVAL)',
            defined('SOCKET_EINVAL') ? SOCKET_EINVAL : 22
        );
        new FdServer(-1, $loop);
    }

    public function testCtorThrowsForInvalidUrl()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addReadStream');

        $this->setExpectedException(
            'InvalidArgumentException',
            'Invalid FD number given (EINVAL)',
            defined('SOCKET_EINVAL') ? SOCKET_EINVAL : 22
        );
        new FdServer('tcp://127.0.0.1:8080', $loop);
    }

    public function testCtorThrowsForUnknownFdWithoutCallingCustomErrorHandler()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $fd = self::getNextFreeFd();

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addReadStream');

        $error = null;
        set_error_handler(function ($_, $errstr) use (&$error) {
            $error = $errstr;
        });

        $this->setExpectedException(
            'RuntimeException',
            'Failed to listen on FD ' . $fd . ': ' . (function_exists('socket_strerror') ? socket_strerror(SOCKET_EBADF) . ' (EBADF)' : 'Bad file descriptor'),
            defined('SOCKET_EBADF') ? SOCKET_EBADF : 9
        );

        try {
            new FdServer($fd, $loop);

            restore_error_handler();
        } catch (\Exception $e) {
            restore_error_handler();
            $this->assertNull($error);

            throw $e;
        }
    }

    public function testCtorThrowsIfFdIsAFileAndNotASocket()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $fd = self::getNextFreeFd();
        $tmpfile = tmpfile();
        assert($tmpfile !== false);

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addReadStream');

        $this->setExpectedException(
            'RuntimeException',
            'Failed to listen on FD ' . $fd . ': ' . (function_exists('socket_strerror') ? socket_strerror(SOCKET_ENOTSOCK) : 'Not a socket') . ' (ENOTSOCK)',
            defined('SOCKET_ENOTSOCK') ? SOCKET_ENOTSOCK : 88
        );
        new FdServer($fd, $loop);
    }

    public function testCtorThrowsIfFdIsAConnectedSocketInsteadOfServerSocket()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0');

        $fd = self::getNextFreeFd();
        $client = stream_socket_client('tcp://' . stream_socket_get_name($socket, false));
        assert($client !== false);

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addReadStream');

        $this->setExpectedException(
            'RuntimeException',
            'Failed to listen on FD ' . $fd . ': ' . (function_exists('socket_strerror') ? socket_strerror(SOCKET_EISCONN) : 'Socket is connected') . ' (EISCONN)',
            defined('SOCKET_EISCONN') ? SOCKET_EISCONN : 106
        );
        new FdServer($fd, $loop);
    }

    public function testGetAddressReturnsSameAddressAsOriginalSocketForIpv4Socket()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $fd = self::getNextFreeFd();
        $socket = stream_socket_server('127.0.0.1:0');

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $server = new FdServer($fd, $loop);

        $this->assertEquals('tcp://' . stream_socket_get_name($socket, false), $server->getAddress());
    }

    public function testGetAddressReturnsSameAddressAsOriginalSocketForIpv4SocketGivenAsUrlToFd()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $fd = self::getNextFreeFd();
        $socket = stream_socket_server('127.0.0.1:0');

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $server = new FdServer('php://fd/' . $fd, $loop);

        $this->assertEquals('tcp://' . stream_socket_get_name($socket, false), $server->getAddress());
    }

    public function testGetAddressReturnsSameAddressAsOriginalSocketForIpv6Socket()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $fd = self::getNextFreeFd();
        $socket = @stream_socket_server('[::1]:0');
        if ($socket === false) {
            $this->markTestSkipped('Listening on IPv6 not supported');
        }

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $server = new FdServer($fd, $loop);

        $port = preg_replace('/.*:/', '', stream_socket_get_name($socket, false));
        $this->assertEquals('tcp://[::1]:' . $port, $server->getAddress());
    }

    public function testGetAddressReturnsSameAddressAsOriginalSocketForUnixDomainSocket()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $fd = self::getNextFreeFd();
        $socket = @stream_socket_server($this->getRandomSocketUri());
        if ($socket === false) {
            $this->markTestSkipped('Listening on Unix domain socket (UDS) not supported');
        }

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $server = new FdServer($fd, $loop);

        $this->assertEquals('unix://' . stream_socket_get_name($socket, false), $server->getAddress());
    }

    public function testGetAddressReturnsNullAfterClose()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $fd = self::getNextFreeFd();
        $socket = stream_socket_server('127.0.0.1:0');
        assert($socket !== false);

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $server = new FdServer($fd, $loop);
        $server->close();

        $this->assertNull($server->getAddress());
    }

    public function testCloseRemovesResourceFromLoop()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $fd = self::getNextFreeFd();
        $socket = stream_socket_server('127.0.0.1:0');
        assert($socket !== false);

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('removeReadStream');

        $server = new FdServer($fd, $loop);
        $server->close();
    }

    public function testCloseTwiceRemovesResourceFromLoopOnce()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $fd = self::getNextFreeFd();
        $socket = stream_socket_server('127.0.0.1:0');
        assert($socket !== false);

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('removeReadStream');

        $server = new FdServer($fd, $loop);
        $server->close();
        $server->close();
    }

    public function testResumeWithoutPauseIsNoOp()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $fd = self::getNextFreeFd();
        $socket = stream_socket_server('127.0.0.1:0');
        assert($socket !== false);

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addReadStream');

        $server = new FdServer($fd, $loop);
        $server->resume();
    }

    public function testPauseRemovesResourceFromLoop()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $fd = self::getNextFreeFd();
        $socket = stream_socket_server('127.0.0.1:0');
        assert($socket !== false);

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('removeReadStream');

        $server = new FdServer($fd, $loop);
        $server->pause();
    }

    public function testPauseAfterPauseIsNoOp()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $fd = self::getNextFreeFd();
        $socket = stream_socket_server('127.0.0.1:0');
        assert($socket !== false);

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('removeReadStream');

        $server = new FdServer($fd, $loop);
        $server->pause();
        $server->pause();
    }

    public function testServerEmitsConnectionEventForNewConnection()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $fd = self::getNextFreeFd();
        $socket = stream_socket_server('127.0.0.1:0');
        assert($socket !== false);

        $client = stream_socket_client('tcp://' . stream_socket_get_name($socket, false));

        $server = new FdServer($fd);
        $promise = new Promise(function ($resolve) use ($server) {
            $server->on('connection', $resolve);
        });

        $connection = \Clue\React\Block\await(\React\Promise\Timer\timeout($promise, 1.0));

        /**
         * @var ConnectionInterface $connection
         */
        $this->assertInstanceOf('React\Socket\ConnectionInterface', $connection);

        fclose($client);
        $connection->close();
        $server->close();
    }

    public function testEmitsErrorWhenAcceptListenerFailsWithoutCallingCustomErrorHandler()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $listener = null;
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addReadStream')->with($this->anything(), $this->callback(function ($cb) use (&$listener) {
            $listener = $cb;
            return true;
        }));

        $fd = self::getNextFreeFd();
        $socket = stream_socket_server('127.0.0.1:0');
        assert($socket !== false);

        $server = new FdServer($fd, $loop);

        $exception = null;
        $server->on('error', function ($e) use (&$exception) {
            $exception = $e;
        });

        $this->assertNotNull($listener);
        $socket = stream_socket_server('tcp://127.0.0.1:0');

        $error = null;
        set_error_handler(function ($_, $errstr) use (&$error) {
            $error = $errstr;
        });

        $time = microtime(true);
        $listener($socket);
        $time = microtime(true) - $time;

        restore_error_handler();
        $this->assertNull($error);

        $this->assertLessThan(1, $time);

        $this->assertInstanceOf('RuntimeException', $exception);
        assert($exception instanceof \RuntimeException);
        $this->assertStringStartsWith('Unable to accept new connection: ', $exception->getMessage());

        return $exception;
    }

    /**
     * @param \RuntimeException $e
     * @requires extension sockets
     * @depends testEmitsErrorWhenAcceptListenerFailsWithoutCallingCustomErrorHandler
     */
    public function testEmitsTimeoutErrorWhenAcceptListenerFails(\RuntimeException $exception)
    {
        $this->assertEquals('Unable to accept new connection: ' . socket_strerror(SOCKET_ETIMEDOUT) . ' (ETIMEDOUT)', $exception->getMessage());
        $this->assertEquals(SOCKET_ETIMEDOUT, $exception->getCode());
    }

    /**
     * @return int
     * @throws \UnexpectedValueException
     * @throws \BadMethodCallException
     * @throws \UnderflowException
     * @copyright Copyright (c) 2018 Christian Lück, taken from https://github.com/clue/fd with permission
     */
    public static function getNextFreeFd()
    {
        // open tmpfile to occupy next free FD temporarily
        $tmp = tmpfile();

        $dir = @scandir('/dev/fd');
        if ($dir === false) {
            throw new \BadMethodCallException('Not supported on your platform because /dev/fd is not readable');
        }

        $stat = fstat($tmp);
        $ino = (int) $stat['ino'];

        foreach ($dir as $file) {
            $stat = @stat('/dev/fd/' . $file);
            if (isset($stat['ino']) && $stat['ino'] === $ino) {
                return (int) $file;
            }
        }

        throw new \UnderflowException('Could not locate file descriptor for this resource');
    }

    private function getRandomSocketUri()
    {
        return "unix://" . sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid(rand(), true) . '.sock';
    }
}
