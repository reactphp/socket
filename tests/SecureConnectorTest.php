<?php

namespace React\Tests\Socket;

use Clue\React\Block;
use React\EventLoop\Factory;
use React\Promise;
use React\Promise\Deferred;
use React\Socket\SecureConnector;
use React\Socket\SecureServer;
use React\Socket\TcpConnector;
use React\Socket\TcpServer;
use React\Socket\ExtConnectionInterface;

class SecureConnectorTest extends TestCase
{
    const TIMEOUT = 10;

    private $loop;
    private $tcp;
    private $connector;

    public function setUp()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            return $this->markTestSkipped('Not supported on your platform (outdated HHVM?)');
        }

        $this->loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $this->tcp = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $this->connector = new SecureConnector($this->tcp, $this->loop);
    }

    public function testConnectionWillWaitForTcpConnection()
    {
        $pending = new Promise\Promise(function () { });
        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('example.com:80'))->will($this->returnValue($pending));

        $promise = $this->connector->connect('example.com:80');

        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);
    }

    public function testConnectionWithCompleteUriWillBePassedThroughExpectForScheme()
    {
        $pending = new Promise\Promise(function () { });
        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('example.com:80/path?query#fragment'))->will($this->returnValue($pending));

        $this->connector->connect('tls://example.com:80/path?query#fragment');
    }

    public function testConnectionToInvalidSchemeWillReject()
    {
        $this->tcp->expects($this->never())->method('connect');

        $promise = $this->connector->connect('tcp://example.com:80');

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testCancelDuringTcpConnectionCancelsTcpConnection()
    {
        $pending = new Promise\Promise(function () { }, $this->expectCallableOnce());
        $this->tcp->expects($this->once())->method('connect')->with('example.com:80')->willReturn($pending);

        $promise = $this->connector->connect('example.com:80');
        $promise->cancel();
    }

    /**
     * @expectedException RuntimeException
     * @expectedExceptionMessage Connection cancelled
     */
    public function testCancelDuringTcpConnectionCancelsTcpConnectionAndRejectsWithTcpRejection()
    {
        $pending = new Promise\Promise(function () { }, function () { throw new \RuntimeException('Connection cancelled'); });
        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('example.com:80'))->will($this->returnValue($pending));

        $promise = $this->connector->connect('example.com:80');
        $promise->cancel();

        $this->throwRejection($promise);
    }

    /**
     * @expectedException UnexpectedValueException
     */
    public function testConnectionWillBeClosedAndRejectedIfConnectionIsNoStream()
    {
        $connection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $connection->expects($this->once())->method('close');

        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('example.com:80'))->willReturn(Promise\resolve($connection));

        $promise = $this->connector->connect('example.com:80');

        $this->throwRejection($promise);
    }

    public function testStreamEncryptionWillBeEnabledAfterConnecting()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->getMock();

        $encryption = $this->getMockBuilder('React\Socket\StreamEncryption')->disableOriginalConstructor()->getMock();
        $encryption->expects($this->once())->method('enable')->with($connection)->willReturn(new \React\Promise\Promise(function () { }));

        $ref = new \ReflectionProperty($this->connector, 'streamEncryption');
        $ref->setAccessible(true);
        $ref->setValue($this->connector, $encryption);

        $pending = new Promise\Promise(function () { }, function () { throw new \RuntimeException('Connection cancelled'); });
        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('example.com:80'))->willReturn(Promise\resolve($connection));

        $promise = $this->connector->connect('example.com:80');
    }

    public function testConnectionWillBeRejectedIfStreamEncryptionFailsAndClosesConnection()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->getMock();
        $connection->expects($this->once())->method('close');

        $encryption = $this->getMockBuilder('React\Socket\StreamEncryption')->disableOriginalConstructor()->getMock();
        $encryption->expects($this->once())->method('enable')->willReturn(Promise\reject(new \RuntimeException('TLS error', 123)));

        $ref = new \ReflectionProperty($this->connector, 'streamEncryption');
        $ref->setAccessible(true);
        $ref->setValue($this->connector, $encryption);

        $pending = new Promise\Promise(function () { }, function () { throw new \RuntimeException('Connection cancelled'); });
        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('example.com:80'))->willReturn(Promise\resolve($connection));

        $promise = $this->connector->connect('example.com:80');

        try {
            $this->throwRejection($promise);
        } catch (\RuntimeException $e) {
            $this->assertContains('TLS error', $e->getMessage());
            $this->assertEquals(123, $e->getCode());
            $this->assertNull($e->getPrevious());
        }
    }

    /**
     * @expectedException RuntimeException
     * @expectedExceptionMessage Connection to example.com:80 cancelled during TLS handshake
     */
    public function testCancelDuringStreamEncryptionCancelsEncryptionAndClosesConnection()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->getMock();
        $connection->expects($this->once())->method('close');

        $pending = new Promise\Promise(function () { }, function () {
            throw new \Exception('Ignored');
        });
        $encryption = $this->getMockBuilder('React\Socket\StreamEncryption')->disableOriginalConstructor()->getMock();
        $encryption->expects($this->once())->method('enable')->willReturn($pending);

        $ref = new \ReflectionProperty($this->connector, 'streamEncryption');
        $ref->setAccessible(true);
        $ref->setValue($this->connector, $encryption);

        $this->tcp->expects($this->once())->method('connect')->with($this->equalTo('example.com:80'))->willReturn(Promise\resolve($connection));

        $promise = $this->connector->connect('example.com:80');
        $promise->cancel();

        $this->throwRejection($promise);
    }

    public function testRejectionDuringConnectionShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            return $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        gc_collect_cycles();

        $tcp = new Deferred();
        $this->tcp->expects($this->once())->method('connect')->willReturn($tcp->promise());

        $promise = $this->connector->connect('example.com:80');
        $tcp->reject(new \RuntimeException());
        unset($promise, $tcp);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testRejectionDuringTlsHandshakeShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            return $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        gc_collect_cycles();

        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->getMock();

        $tcp = new Deferred();
        $this->tcp->expects($this->once())->method('connect')->willReturn($tcp->promise());

        $tls = new Deferred();
        $encryption = $this->getMockBuilder('React\Socket\StreamEncryption')->disableOriginalConstructor()->getMock();
        $encryption->expects($this->once())->method('enable')->willReturn($tls->promise());

        $ref = new \ReflectionProperty($this->connector, 'streamEncryption');
        $ref->setAccessible(true);
        $ref->setValue($this->connector, $encryption);

        $promise = $this->connector->connect('example.com:80');
        $tcp->resolve($connection);
        $tls->reject(new \RuntimeException());
        unset($promise, $tcp, $tls);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testEnablingTLS()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            return $this->markTestSkipped('Not supported on your platform (outdated HHVM?)');
        }

        $loop = Factory::create();
        $tcp = new TcpServer('127.0.0.1:0', $loop);
        $server = new SecureServer($tcp, $loop, array(
            'local_cert' => __DIR__ . '/../examples/localhost.pem'
        ));
        
        $connector = new TcpConnector($loop);
        $secureConn = new SecureConnector($connector, $loop, array(
            'verify_peer' => false
        ));

        $client = Block\await($connector->connect($tcp->getAddress()), $loop, self::TIMEOUT);
        Block\await($secureConn->enableTLS($client), $loop, self::TIMEOUT);

        $this->assertContains('tls://', $client->getRemoteAddress());
    }

    /**
     * @expectedException \Exception
     */
    public function testEnablingTLSButCancelledAndThrowingAnException()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            return $this->markTestSkipped('Not supported on your platform (outdated HHVM?)');
        }

        $loop = Factory::create();
        $tcp = new TcpServer('127.0.0.1:0', $loop);
        $server = new SecureServer($tcp, $loop, array(
            'local_cert' => __DIR__ . '/../examples/localhost.pem'
        ));

        $connector = new TcpConnector($loop);
        $secureConn = new SecureConnector($connector, $loop, array(
            'verify_peer' => false
        ));

        $client = Block\await($connector->connect($tcp->getAddress()), $loop, self::TIMEOUT);
        $enablingTLS = $secureConn->enableTLS($client);

        $enablingTLS->cancel();
        Block\await($enablingTLS, $loop, self::TIMEOUT);
    }

    public function testDisablingTLS()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            return $this->markTestSkipped('Not supported on your platform (outdated HHVM?)');
        } elseif (true) {
            // Conditional must be updated to check on fixed PHP versions, for now take everything
            return $this->markTestSkipped('Disabling TLS is "broken", see tracking issue #200');
        }

        $loop = Factory::create();
        $tcp = new TcpServer('127.0.0.1:0', $loop);
        $server = new SecureServer($tcp, $loop, array(
            'local_cert' => __DIR__ . '/../examples/localhost.pem'
        ));

        $connector = new TcpConnector($loop);
        $secureConn = new SecureConnector($connector, $loop, array(
            'verify_peer' => false
        ));

        $client = Block\await($connector->connect($tcp->getAddress()), $loop, self::TIMEOUT);
        Block\await($secureConn->enableTLS($client), $loop, self::TIMEOUT);

        Block\await($secureConn->disableTLS($client), $loop, self::TIMEOUT);
    }

    /**
     * @expectedException \Exception
     */
    public function testDisablingTLSButConnectionCloseDuringHandshakeThrowsException()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('Not supported on your platform (outdated HHVM?)');
        }

        $loop = Factory::create();
        $tcp = new TcpServer('127.0.0.1:0', $loop);
        $server = new SecureServer($tcp, $loop, array(
            'local_cert' => __DIR__ . '/../examples/localhost.pem'
        ));

        $connector = new TcpConnector($loop);
        $secureConn = new SecureConnector($connector, $loop, array(
            'verify_peer' => false
        ));

        $conn = null;
        $server->once('connection', function (ExtConnectionInterface $c) use (&$conn) {
            $conn = $c;
        });

        $client = Block\await($connector->connect($tcp->getAddress()), $loop, self::TIMEOUT);
        Block\await($secureConn->enableTLS($client), $loop, self::TIMEOUT);
        Block\sleep(0.1, $loop);

        $this->assertNotNull($conn);
        $this->assertNotNull($client);

        $disableTLS = $secureConn->disableTLS($client);
        $conn->close(); // close connection and force a failure

        Block\await($disableTLS, $loop, self::TIMEOUT);
    }

    private function throwRejection($promise)
    {
        $ex = null;
        $promise->then(null, function ($e) use (&$ex) {
            $ex = $e;
        });

        throw $ex;
    }
}
