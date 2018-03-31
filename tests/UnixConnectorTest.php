<?php

namespace React\Tests\Socket;

use PHPUnit\Framework\MockObject\MockObject;
use React\EventLoop\LoopInterface;
use React\Socket\UnixConnection;
use React\Socket\UnixConnector;

class UnixConnectorTest extends TestCase
{
    /** @var LoopInterface|MockObject */
    private $loop;
    /** @var UnixConnector */
    private $connector;

    public function setUp()
    {
        $this->loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $this->connector = new UnixConnector($this->loop);
    }

    public function testInvalid()
    {
        $promise = $this->connector->connect('google.com:80');
        $promise->then(null, $this->expectCallableOnce());
    }

    public function testInvalidScheme()
    {
        $promise = $this->connector->connect('tcp://google.com:80');
        $promise->then(null, $this->expectCallableOnce());
    }

    public function testValid()
    {
        // random unix domain socket path
        $path = sys_get_temp_dir() . '/test' . uniqid() . '.sock';

        // temporarily create unix domain socket server to connect to
        $server = stream_socket_server('unix://' . $path, $errno, $errstr);

        // skip test if we can not create a test server (Windows etc.)
        if (!$server) {
            $this->markTestSkipped('Unable to create socket "' . $path . '": ' . $errstr . '(' . $errno . ')');
            return;
        }

        // tests succeeds if we get notified of successful connection
        $promise = $this->connector->connect($path);
        $promise->then($this->expectCallableOnce());

        // remember remote and local address and pid of this connection and close again
        $remote_address = false;
        $remote_pid = false;
        $local_address = false;
        $local_pid = false;
        $promise->then(function (UnixConnection $conn) use (&$remote_address, &$remote_pid, &$local_address, &$local_pid) {
            $remote_address = $conn->getRemoteAddress();
            $remote_pid = $conn->getRemotePid();
            $local_address = $conn->getLocalAddress();
            $local_pid = $conn->getLocalPid();
            $conn->close();
        });

        // clean up server
        fclose($server);
        unlink($path);

        $this->assertNull($local_address);
        $this->assertSame('unix://' . $path, $remote_address);

        $this->assertValidPid($local_pid);
        $this->assertValidPid($remote_pid);
    }
}
