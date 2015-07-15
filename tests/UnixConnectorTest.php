<?php

namespace React\Tests\SocketClient;

use React\SocketClient\UnixConnector;

class UnixConnectorTest extends TestCase
{
    private $loop;
    private $connector;

    public function setUp()
    {
        $this->loop = $this->getMock('React\EventLoop\LoopInterface');
        $this->connector = new UnixConnector($this->loop);
    }

    public function testInvalid()
    {
        $promise = $this->connector->create('google.com', 80);
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
            $this->markTestSkipped('Unable to create socket "' . $path . '": ' . $errstr . '(' . $errno .')');
            return;
        }

        // tests succeeds if we get notified of successful connection
        $promise = $this->connector->create($path, 0);
        $promise->then($this->expectCallableOnce());

        // clean up server
        fclose($server);
        unlink($path);
    }
}
