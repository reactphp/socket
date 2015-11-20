<?php

namespace React\Tests\SocketClient;

use React\Dns\Resolver\Factory;
use React\EventLoop\StreamSelectLoop;
use React\Socket\Server;
use React\SocketClient\Connector;
use React\SocketClient\SecureConnector;
use React\Stream\BufferedSink;
use Clue\React\Block;

class IntegrationTest extends TestCase
{
    /** @test */
    public function gettingStuffFromGoogleShouldWork()
    {
        $loop = new StreamSelectLoop();

        $factory = new Factory();
        $dns = $factory->create('8.8.8.8', $loop);
        $connector = new Connector($loop, $dns);

        $connected = false;
        $response = null;

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
        if (defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on HHVM');
        }

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

    /** @test */
    public function testSelfSignedRejectsIfVerificationIsEnabled()
    {
        $loop = new StreamSelectLoop();

        $factory = new Factory();
        $dns = $factory->create('8.8.8.8', $loop);


        $secureConnector = new SecureConnector(
            new Connector($loop, $dns),
            $loop,
            array(
                'verify_peer' => true
            )
        );

        $this->setExpectedException('RuntimeException');
        Block\await($secureConnector->create('self-signed.badssl.com', 443), $loop);
    }

    /** @test */
    public function testSelfSignedResolvesIfVerificationIsDisabled()
    {
        $loop = new StreamSelectLoop();

        $factory = new Factory();
        $dns = $factory->create('8.8.8.8', $loop);

        $secureConnector = new SecureConnector(
            new Connector($loop, $dns),
            $loop,
            array(
                'verify_peer' => false
            )
        );

        $conn = Block\await($secureConnector->create('self-signed.badssl.com', 443), $loop);
        $conn->close();
    }
}
