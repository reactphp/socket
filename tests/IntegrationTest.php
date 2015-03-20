<?php

namespace React\Tests\SocketClient;

use React\Dns\Resolver\Factory;
use React\EventLoop\StreamSelectLoop;
use React\Socket\Server;
use React\SocketClient\Connector;
use React\SocketClient\SecureConnector;
use React\Stream\BufferedSink;

class IntegrationTest extends TestCase
{
    /** @test */
    public function gettingStuffFromGoogleShouldWork()
    {
        $loop = new StreamSelectLoop();

        $factory = new Factory();
        $dns = $factory->create('8.8.8.8', $loop);

        $connected = false;
        $response = null;

        $connector = new Connector($loop, $dns);
        $connector->create('google.com', 80)
            ->then(function ($conn) use (&$connected) {
                $connected = true;
                $conn->write("GET / HTTP/1.0\r\n\r\n");
                return BufferedSink::createPromise($conn);
            })
            ->then(function ($data) use (&$response) {
                $response = $data;
            });

        $loop->run();

        $this->assertTrue($connected);
        $this->assertRegExp('#^HTTP/1\.0#', $response);
    }

    /** @test */
    public function gettingEncryptedStuffFromGoogleShouldWork()
    {
        $loop = new StreamSelectLoop();

        $factory = new Factory();
        $dns = $factory->create('8.8.8.8', $loop);

        $connected = false;
        $response = null;

        $secureConnector = new SecureConnector(
            new Connector($loop, $dns),
            $loop
        );
        $secureConnector->create('google.com', 443)
            ->then(function ($conn) use (&$connected) {
                $connected = true;
                $conn->write("GET / HTTP/1.0\r\n\r\n");
                return BufferedSink::createPromise($conn);
            })
            ->then(function ($data) use (&$response) {
                $response = $data;
            });

        $loop->run();

        $this->assertTrue($connected);
        $this->assertRegExp('#^HTTP/1\.0#', $response);
    }
}
