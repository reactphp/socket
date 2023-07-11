<?php

namespace React\Tests\Socket;

use React\Dns\Resolver\Factory as ResolverFactory;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
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
        $connector = new Connector(array());

        $conn = \React\Async\await($connector->connect('google.com:80'));
        assert($conn instanceof ConnectionInterface);

        $this->assertContainsString(':80', $conn->getRemoteAddress());
        $this->assertNotEquals('google.com:80', $conn->getRemoteAddress());

        $conn->write("GET / HTTP/1.0\r\n\r\n");

        $response = $this->buffer($conn, self::TIMEOUT);
        assert(!$conn->isReadable());

        $this->assertMatchesRegExp('#^HTTP/1\.0#', $response);
    }

    /** @test */
    public function gettingEncryptedStuffFromGoogleShouldWork()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on legacy HHVM');
        }

        $secureConnector = new Connector(array());

        $conn = \React\Async\await($secureConnector->connect('tls://google.com:443'));
        assert($conn instanceof ConnectionInterface);

        $conn->write("GET / HTTP/1.0\r\n\r\n");

        $response = $this->buffer($conn, self::TIMEOUT);
        assert(!$conn->isReadable());

        $this->assertMatchesRegExp('#^HTTP/1\.0#', $response);
    }

    /** @test */
    public function gettingEncryptedStuffFromGoogleShouldWorkIfHostIsResolvedFirst()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on legacy HHVM');
        }

        $factory = new ResolverFactory();
        $dns = $factory->create('8.8.8.8');

        $connector = new DnsConnector(
            new SecureConnector(
                new TcpConnector()
            ),
            $dns
        );

        $conn = \React\Async\await($connector->connect('google.com:443'));
        assert($conn instanceof ConnectionInterface);

        $conn->write("GET / HTTP/1.0\r\n\r\n");

        $response = $this->buffer($conn, self::TIMEOUT);
        assert(!$conn->isReadable());

        $this->assertMatchesRegExp('#^HTTP/1\.0#', $response);
    }

    /** @test */
    public function gettingPlaintextStuffFromEncryptedGoogleShouldNotWork()
    {
        $connector = new Connector(array());

        $conn = \React\Async\await($connector->connect('google.com:443'));
        assert($conn instanceof ConnectionInterface);

        $this->assertContainsString(':443', $conn->getRemoteAddress());
        $this->assertNotEquals('google.com:443', $conn->getRemoteAddress());

        $conn->write("GET / HTTP/1.0\r\n\r\n");

        $response = $this->buffer($conn, self::TIMEOUT);
        assert(!$conn->isReadable());

        $this->assertDoesNotMatchRegExp('#^HTTP/1\.0#', $response);
    }

    public function testConnectingFailsIfConnectorUsesInvalidDnsResolverAddress()
    {
        if (PHP_OS === 'Darwin') {
            $this->markTestSkipped('Skipped on macOS due to a bug in reactphp/dns (solved in reactphp/dns#171)');
        }

        $factory = new ResolverFactory();
        $dns = $factory->create('255.255.255.255');

        $connector = new Connector(array(
            'dns' => $dns
        ));

        $this->setExpectedException('RuntimeException');
        \React\Async\await(\React\Promise\Timer\timeout($connector->connect('google.com:80'), self::TIMEOUT));
    }

    public function testCancellingPendingConnectionWithoutTimeoutShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $connector = new Connector(array('timeout' => false));

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

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

        $connector = new Connector(array());

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

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

        // let loop tick for reactphp/async v4 to clean up any remaining stream resources
        // @link https://github.com/reactphp/async/pull/65 reported upstream // TODO remove me once merged
        if (function_exists('React\Async\async')) {
            \React\Async\await(\React\Promise\Timer\sleep(0));
            Loop::run();
        }

        $connector = new Connector(array('timeout' => false));

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $wait = true;
        $promise = $connector->connect('127.0.0.1:1')->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
            }
        );

        // run loop for short period to ensure we detect connection refused error
        \React\Async\await(\React\Promise\Timer\sleep(0.01));
        if ($wait) {
            \React\Async\await(\React\Promise\Timer\sleep(0.2));
            if ($wait) {
                \React\Async\await(\React\Promise\Timer\sleep(2.0));
                if ($wait) {
                    $this->fail('Connection attempt did not fail');
                }
            }
        }
        unset($promise);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testWaitingForConnectionTimeoutDuringDnsLookupShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $connector = new Connector(array('timeout' => 0.001));

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $wait = true;
        $promise = $connector->connect('google.com:80')->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
            }
        );

        // run loop for short period to ensure we detect a connection timeout error
        \React\Async\await(\React\Promise\Timer\sleep(0.01));
        if ($wait) {
            \React\Async\await(\React\Promise\Timer\sleep(0.2));
            if ($wait) {
                $this->fail('Connection attempt did not fail');
            }
        }
        unset($promise);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testWaitingForConnectionTimeoutDuringTcpConnectionShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $connector = new Connector(array('timeout' => 0.000001));

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $wait = true;
        $promise = $connector->connect('8.8.8.8:53')->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
            }
        );

        // run loop for short period to ensure we detect a connection timeout error
        \React\Async\await(\React\Promise\Timer\sleep(0.01));
        if ($wait) {
            \React\Async\await(\React\Promise\Timer\sleep(0.2));
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

        $connector = new Connector(array('timeout' => false));

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $wait = true;
        $promise = $connector->connect('example.invalid:80')->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
            }
        );

        // run loop for short period to ensure we detect a DNS error
        \React\Async\await(\React\Promise\Timer\sleep(0.01));
        if ($wait) {
            \React\Async\await(\React\Promise\Timer\sleep(0.2));
            if ($wait) {
                \React\Async\await(\React\Promise\Timer\sleep(2.0));
                if ($wait) {
                    $this->fail('Connection attempt did not fail');
                }
            }
        }
        unset($promise);

        $this->assertEquals(0, gc_collect_cycles());
    }

    /**
     * @requires PHP 7
     */
    public function testWaitingForInvalidTlsConnectionShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $connector = new Connector(array(
            'tls' => array(
                'verify_peer' => true
            )
        ));

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $wait = true;
        $promise = $connector->connect('tls://self-signed.badssl.com:443')->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
            }
        );

        // run loop for short period to ensure we detect a TLS error
        \React\Async\await(\React\Promise\Timer\sleep(0.01));
        if ($wait) {
            \React\Async\await(\React\Promise\Timer\sleep(0.4));
            if ($wait) {
                \React\Async\await(\React\Promise\Timer\sleep(self::TIMEOUT - 0.5));
                if ($wait) {
                    $this->fail('Connection attempt did not fail');
                }
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

        $connector = new Connector(array('timeout' => false));

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $promise = $connector->connect('google.com:80')->then(
            function ($conn) {
                $conn->close();
            }
        );
        \React\Async\await(\React\Promise\Timer\timeout($promise, self::TIMEOUT));
        unset($promise);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testConnectingFailsIfTimeoutIsTooSmall()
    {
        $connector = new Connector(array(
            'timeout' => 0.001
        ));

        $this->setExpectedException('RuntimeException');
        \React\Async\await(\React\Promise\Timer\timeout($connector->connect('google.com:80'), self::TIMEOUT));
    }

    public function testSelfSignedRejectsIfVerificationIsEnabled()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on legacy HHVM');
        }

        $connector = new Connector(array(
            'tls' => array(
                'verify_peer' => true
            )
        ));

        $this->setExpectedException('RuntimeException');
        \React\Async\await(\React\Promise\Timer\timeout($connector->connect('tls://self-signed.badssl.com:443'), self::TIMEOUT));
    }

    public function testSelfSignedResolvesIfVerificationIsDisabled()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on legacy HHVM');
        }

        $connector = new Connector(array(
            'tls' => array(
                'verify_peer' => false
            )
        ));

        $conn = \React\Async\await(\React\Promise\Timer\timeout($connector->connect('tls://self-signed.badssl.com:443'), self::TIMEOUT));
        assert($conn instanceof ConnectionInterface);
        $conn->close();

        // if we reach this, then everything is good
        $this->assertNull(null);
    }
}
