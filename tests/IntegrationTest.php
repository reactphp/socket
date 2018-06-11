<?php

namespace React\Tests\Socket;

use Clue\React\Block;
use React\Dns\Resolver\Factory as ResolverFactory;
use React\EventLoop\Factory;
use React\Socket\Connector;
use React\Socket\DnsConnector;
use React\Socket\SecureConnector;
use React\Socket\TcpConnector;

/** @group internet */
class IntegrationTest extends TestCase
{
    const TIMEOUT = 5.0;

    /** @test */
    public function gettingStuffFromGoogleShouldWork()
    {
        $loop = Factory::create();
        $connector = new Connector($loop);

        $conn = Block\await($connector->connect('google.com:80'), $loop);

        $this->assertContains(':80', $conn->getRemoteAddress());
        $this->assertNotEquals('google.com:80', $conn->getRemoteAddress());

        $conn->write("GET / HTTP/1.0\r\n\r\n");

        $response = $this->buffer($conn, $loop, self::TIMEOUT);

        $this->assertRegExp('#^HTTP/1\.0#', $response);
    }

    /** @test */
    public function gettingEncryptedStuffFromGoogleShouldWork()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('Not supported on your platform (outdated HHVM?)');
        }

        $loop = Factory::create();
        $secureConnector = new Connector($loop);

        $conn = Block\await($secureConnector->connect('tls://google.com:443'), $loop);

        $conn->write("GET / HTTP/1.0\r\n\r\n");

        $response = $this->buffer($conn, $loop, self::TIMEOUT);

        $this->assertRegExp('#^HTTP/1\.0#', $response);
    }

    /** @test */
    public function gettingEncryptedStuffFromGoogleShouldWorkIfHostIsResolvedFirst()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('Not supported on your platform (outdated HHVM?)');
        }

        $loop = Factory::create();

        $factory = new ResolverFactory();
        $dns = $factory->create('8.8.8.8', $loop);

        $connector = new DnsConnector(
            new SecureConnector(
                new TcpConnector($loop),
                $loop
            ),
            $dns
        );

        $conn = Block\await($connector->connect('google.com:443'), $loop);

        $conn->write("GET / HTTP/1.0\r\n\r\n");

        $response = $this->buffer($conn, $loop, self::TIMEOUT);

        $this->assertRegExp('#^HTTP/1\.0#', $response);
    }

    /** @test */
    public function gettingPlaintextStuffFromEncryptedGoogleShouldNotWork()
    {
        $loop = Factory::create();
        $connector = new Connector($loop);

        $conn = Block\await($connector->connect('google.com:443'), $loop);

        $this->assertContains(':443', $conn->getRemoteAddress());
        $this->assertNotEquals('google.com:443', $conn->getRemoteAddress());

        $conn->write("GET / HTTP/1.0\r\n\r\n");

        $response = $this->buffer($conn, $loop, self::TIMEOUT);

        $this->assertNotRegExp('#^HTTP/1\.0#', $response);
    }

    public function testConnectingFailsIfDnsUsesInvalidResolver()
    {
        $loop = Factory::create();

        $factory = new ResolverFactory();
        $dns = $factory->create('demo.invalid', $loop);

        $connector = new Connector($loop, array(
            'dns' => $dns
        ));

        $this->setExpectedException('RuntimeException');
        Block\await($connector->connect('google.com:80'), $loop, self::TIMEOUT);
    }

    public function testCancellingPendingConnectionWithoutTimeoutShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $loop = Factory::create();
        $connector = new Connector($loop, array('timeout' => false));

        gc_collect_cycles();
        $promise = $connector->connect('8.8.8.8:80');
        $promise->cancel();
        unset($promise);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testCancellingPendingConnectionShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $loop = Factory::create();
        $connector = new Connector($loop);

        gc_collect_cycles();
        $promise = $connector->connect('8.8.8.8:80');
        $promise->cancel();
        unset($promise);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testWaitingForRejectedConnectionShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $loop = Factory::create();
        $connector = new Connector($loop, array('timeout' => false));

        gc_collect_cycles();

        $wait = true;
        $promise = $connector->connect('127.0.0.1:1')->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
                throw $e;
            }
        );

        // run loop for short period to ensure we detect connection refused error
        Block\sleep(0.01, $loop);
        if ($wait) {
            Block\sleep(0.2, $loop);
            if ($wait) {
                $this->fail('Connection attempt did not fail');
            }
        }
        unset($promise);

        $this->assertEquals(0, gc_collect_cycles());
    }

    /**
     * @requires PHP 7
     */
    public function testWaitingForConnectionTimeoutShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $loop = Factory::create();
        $connector = new Connector($loop, array('timeout' => 0.001));

        gc_collect_cycles();

        $wait = true;
        $promise = $connector->connect('google.com:80')->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
                throw $e;
            }
        );

        // run loop for short period to ensure we detect connection timeout error
        Block\sleep(0.01, $loop);
        if ($wait) {
            Block\sleep(0.2, $loop);
            if ($wait) {
                $this->fail('Connection attempt did not fail');
            }
        }
        unset($promise);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testWaitingForInvalidDnsConnectionShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $loop = Factory::create();
        $connector = new Connector($loop, array('timeout' => false));

        gc_collect_cycles();

        $wait = true;
        $promise = $connector->connect('example.invalid:80')->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
                throw $e;
            }
        );

        // run loop for short period to ensure we detect DNS error
        Block\sleep(0.01, $loop);
        if ($wait) {
            Block\sleep(0.2, $loop);
            if ($wait) {
                $this->fail('Connection attempt did not fail');
            }
        }
        unset($promise);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testWaitingForSuccessfullyClosedConnectionShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $loop = Factory::create();
        $connector = new Connector($loop, array('timeout' => false));

        gc_collect_cycles();
        $promise = $connector->connect('google.com:80')->then(
            function ($conn) {
                $conn->close();
            }
        );
        Block\await($promise, $loop, self::TIMEOUT);
        unset($promise);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testConnectingFailsIfTimeoutIsTooSmall()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('Not supported on your platform (outdated HHVM?)');
        }

        $loop = Factory::create();

        $connector = new Connector($loop, array(
            'timeout' => 0.001
        ));

        $this->setExpectedException('RuntimeException');
        Block\await($connector->connect('google.com:80'), $loop, self::TIMEOUT);
    }

    public function testSelfSignedRejectsIfVerificationIsEnabled()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('Not supported on your platform (outdated HHVM?)');
        }

        $loop = Factory::create();

        $connector = new Connector($loop, array(
            'tls' => array(
                'verify_peer' => true
            )
        ));

        $this->setExpectedException('RuntimeException');
        Block\await($connector->connect('tls://self-signed.badssl.com:443'), $loop, self::TIMEOUT);
    }

    public function testSelfSignedResolvesIfVerificationIsDisabled()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('Not supported on your platform (outdated HHVM?)');
        }

        $loop = Factory::create();

        $connector = new Connector($loop, array(
            'tls' => array(
                'verify_peer' => false
            )
        ));

        $conn = Block\await($connector->connect('tls://self-signed.badssl.com:443'), $loop, self::TIMEOUT);
        $conn->close();

        // if we reach this, then everything is good
        $this->assertNull(null);
    }
}
