<?php

namespace React\Tests\Socket;

use Clue\React\Block;
use Evenement\EventEmitterInterface;
use React\EventLoop\Factory;
use React\Promise\Promise;
use React\Socket\ConnectionInterface;
use React\Socket\SecureConnector;
use React\Socket\SecureServer;
use React\Socket\TcpServer;
use React\Socket\TcpConnector;
use React\Socket\ServerInterface;

class FunctionalSecureServerTest extends TestCase
{
    const TIMEOUT = 0.5;

    public function setUp()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('Not supported on your platform (outdated HHVM?)');
        }
    }

    public function testClientCanConnectToServer()
    {
        $loop = Factory::create();

        $server = new TcpServer(0, $loop);
        $server = new SecureServer($server, $loop, array(
            'local_cert' => __DIR__ . '/../examples/localhost.pem'
        ));

        $connector = new SecureConnector(new TcpConnector($loop), $loop, array(
            'verify_peer' => false
        ));
        $promise = $connector->connect($server->getAddress());

        /* @var ConnectionInterface $client */
        $client = Block\await($promise, $loop, self::TIMEOUT);

        $this->assertInstanceOf('React\Socket\ConnectionInterface', $client);
        $this->assertEquals($server->getAddress(), $client->getRemoteAddress());

        $client->close();
        $server->close();
    }

    public function testClientUsesTls13ByDefaultWhenSupportedByOpenSSL()
    {
        if (PHP_VERSION_ID < 70000 || !$this->supportsTls13()) {
            $this->markTestSkipped('Test requires PHP 7+ for crypto meta data and OpenSSL 1.1.1+ for TLS 1.3');
        }

        $loop = Factory::create();

        $server = new TcpServer(0, $loop);
        $server = new SecureServer($server, $loop, array(
            'local_cert' => __DIR__ . '/../examples/localhost.pem'
        ));

        $connector = new SecureConnector(new TcpConnector($loop), $loop, array(
            'verify_peer' => false
        ));
        $promise = $connector->connect($server->getAddress());

        /* @var ConnectionInterface $client */
        $client = Block\await($promise, $loop, self::TIMEOUT);

        $this->assertInstanceOf('React\Socket\Connection', $client);
        $this->assertTrue(isset($client->stream));

        $meta = stream_get_meta_data($client->stream);
        $this->assertTrue(isset($meta['crypto']['protocol']));

        if ($meta['crypto']['protocol'] === 'UNKNOWN') {
            // TLSv1.3 protocol will only be added via https://github.com/php/php-src/pull/3700
            // prior to merging that PR, this info is still available in the cipher version by OpenSSL
            $this->assertTrue(isset($meta['crypto']['cipher_version']));
            $this->assertEquals('TLSv1.3', $meta['crypto']['cipher_version']);
        } else {
            $this->assertEquals('TLSv1.3', $meta['crypto']['protocol']);
        }
    }

    public function testClientUsesTls12WhenCryptoMethodIsExplicitlyConfiguredByClient()
    {
        if (PHP_VERSION_ID < 70000) {
            $this->markTestSkipped('Test requires PHP 7+ for crypto meta data');
        }

        $loop = Factory::create();

        $server = new TcpServer(0, $loop);
        $server = new SecureServer($server, $loop, array(
            'local_cert' => __DIR__ . '/../examples/localhost.pem'
        ));

        $connector = new SecureConnector(new TcpConnector($loop), $loop, array(
            'verify_peer' => false,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
        ));
        $promise = $connector->connect($server->getAddress());

        /* @var ConnectionInterface $client */
        $client = Block\await($promise, $loop, self::TIMEOUT);

        $this->assertInstanceOf('React\Socket\Connection', $client);
        $this->assertTrue(isset($client->stream));

        $meta = stream_get_meta_data($client->stream);
        $this->assertTrue(isset($meta['crypto']['protocol']));
        $this->assertEquals('TLSv1.2', $meta['crypto']['protocol']);
    }

    public function testClientUsesTls12WhenCryptoMethodIsExplicitlyConfiguredByServer()
    {
        if (PHP_VERSION_ID < 70000) {
            $this->markTestSkipped('Test requires PHP 7+ for crypto meta data');
        }

        $loop = Factory::create();

        $server = new TcpServer(0, $loop);
        $server = new SecureServer($server, $loop, array(
            'local_cert' => __DIR__ . '/../examples/localhost.pem',
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER
        ));

        $connector = new SecureConnector(new TcpConnector($loop), $loop, array(
            'verify_peer' => false
        ));
        $promise = $connector->connect($server->getAddress());

        /* @var ConnectionInterface $client */
        $client = Block\await($promise, $loop, self::TIMEOUT);

        $this->assertInstanceOf('React\Socket\Connection', $client);
        $this->assertTrue(isset($client->stream));

        $meta = stream_get_meta_data($client->stream);
        $this->assertTrue(isset($meta['crypto']['protocol']));
        $this->assertEquals('TLSv1.2', $meta['crypto']['protocol']);
    }

    public function testServerEmitsConnectionForClientConnection()
    {
        $loop = Factory::create();

        $server = new TcpServer(0, $loop);
        $server = new SecureServer($server, $loop, array(
            'local_cert' => __DIR__ . '/../examples/localhost.pem'
        ));

        $peer = new Promise(function ($resolve, $reject) use ($server) {
            $server->on('connection', $resolve);
            $server->on('error', $reject);
        });

        $connector = new SecureConnector(new TcpConnector($loop), $loop, array(
            'verify_peer' => false
        ));
        $client = $connector->connect($server->getAddress());

        // await both client and server side end of connection
        /* @var ConnectionInterface[] $both */
        $both = Block\awaitAll(array($peer, $client), $loop, self::TIMEOUT);

        // both ends of the connection are represented by different instances of ConnectionInterface
        $this->assertCount(2, $both);
        $this->assertInstanceOf('React\Socket\ConnectionInterface', $both[0]);
        $this->assertInstanceOf('React\Socket\ConnectionInterface', $both[1]);
        $this->assertNotSame($both[0], $both[1]);

        // server side end has local server address and client end has remote server address
        $this->assertEquals($server->getAddress(), $both[0]->getLocalAddress());
        $this->assertEquals($server->getAddress(), $both[1]->getRemoteAddress());

        // clean up all connections and server again
        $both[0]->close();
        $both[1]->close();
        $server->close();
    }

    public function testWritesDataToConnection()
    {
        $loop = Factory::create();

        $server = new TcpServer(0, $loop);
        $server = new SecureServer($server, $loop, array(
            'local_cert' => __DIR__ . '/../examples/localhost.pem'
        ));
        $server->on('connection', $this->expectCallableOnce());

        $server->on('connection', function (ConnectionInterface $conn) {
            $conn->write('foo');
        });

        $connector = new SecureConnector(new TcpConnector($loop), $loop, array(
            'verify_peer' => false
        ));
        $promise = $connector->connect($server->getAddress());

        $local = Block\await($promise, $loop, self::TIMEOUT);
        /* @var $local ConnectionInterface */

        $local->on('data', $this->expectCallableOnceWith('foo'));

        Block\sleep(self::TIMEOUT, $loop);
    }

    public function testWritesDataInMultipleChunksToConnection()
    {
        $loop = Factory::create();

        $server = new TcpServer(0, $loop);
        $server = new SecureServer($server, $loop, array(
            'local_cert' => __DIR__ . '/../examples/localhost.pem'
        ));
        $server->on('connection', $this->expectCallableOnce());

        $server->on('connection', function (ConnectionInterface $conn) {
            $conn->write(str_repeat('*', 400000));
        });

        $connector = new SecureConnector(new TcpConnector($loop), $loop, array(
            'verify_peer' => false
        ));
        $promise = $connector->connect($server->getAddress());

        $local = Block\await($promise, $loop, self::TIMEOUT);
        /* @var $local ConnectionInterface */

        $received = 0;
        $local->on('data', function ($chunk) use (&$received) {
            $received += strlen($chunk);
        });

        Block\sleep(self::TIMEOUT, $loop);

        $this->assertEquals(400000, $received);
    }

    public function testWritesMoreDataInMultipleChunksToConnection()
    {
        $loop = Factory::create();

        $server = new TcpServer(0, $loop);
        $server = new SecureServer($server, $loop, array(
            'local_cert' => __DIR__ . '/../examples/localhost.pem'
        ));
        $server->on('connection', $this->expectCallableOnce());

        $server->on('connection', function (ConnectionInterface $conn) {
            $conn->write(str_repeat('*', 2000000));
        });

        $connector = new SecureConnector(new TcpConnector($loop), $loop, array(
            'verify_peer' => false
        ));
        $promise = $connector->connect($server->getAddress());

        $local = Block\await($promise, $loop, self::TIMEOUT);
        /* @var $local ConnectionInterface */

        $received = 0;
        $local->on('data', function ($chunk) use (&$received) {
            $received += strlen($chunk);
        });

        Block\sleep(self::TIMEOUT, $loop);

        $this->assertEquals(2000000, $received);
    }

    public function testEmitsDataFromConnection()
    {
        $loop = Factory::create();

        $server = new TcpServer(0, $loop);
        $server = new SecureServer($server, $loop, array(
            'local_cert' => __DIR__ . '/../examples/localhost.pem'
        ));
        $server->on('connection', $this->expectCallableOnce());

        $once = $this->expectCallableOnceWith('foo');
        $server->on('connection', function (ConnectionInterface $conn) use ($once) {
            $conn->on('data', $once);
        });

        $connector = new SecureConnector(new TcpConnector($loop), $loop, array(
            'verify_peer' => false
        ));
        $promise = $connector->connect($server->getAddress());

        $local = Block\await($promise, $loop, self::TIMEOUT);
        /* @var $local ConnectionInterface */

        $local->write("foo");

        Block\sleep(self::TIMEOUT, $loop);
    }

    public function testEmitsDataInMultipleChunksFromConnection()
    {
        $loop = Factory::create();

        $server = new TcpServer(0, $loop);
        $server = new SecureServer($server, $loop, array(
            'local_cert' => __DIR__ . '/../examples/localhost.pem'
        ));
        $server->on('connection', $this->expectCallableOnce());

        $received = 0;
        $server->on('connection', function (ConnectionInterface $conn) use (&$received) {
            $conn->on('data', function ($chunk) use (&$received) {
                $received += strlen($chunk);
            });
        });

        $connector = new SecureConnector(new TcpConnector($loop), $loop, array(
            'verify_peer' => false
        ));
        $promise = $connector->connect($server->getAddress());

        $local = Block\await($promise, $loop, self::TIMEOUT);
        /* @var $local ConnectionInterface */

        $local->write(str_repeat('*', 400000));

        Block\sleep(self::TIMEOUT, $loop);

        $this->assertEquals(400000, $received);
    }

    public function testPipesDataBackInMultipleChunksFromConnection()
    {
        $loop = Factory::create();

        $server = new TcpServer(0, $loop);
        $server = new SecureServer($server, $loop, array(
            'local_cert' => __DIR__ . '/../examples/localhost.pem'
        ));
        $server->on('connection', $this->expectCallableOnce());

        $server->on('connection', function (ConnectionInterface $conn) use (&$received) {
            $conn->pipe($conn);
        });

        $connector = new SecureConnector(new TcpConnector($loop), $loop, array(
            'verify_peer' => false
        ));
        $promise = $connector->connect($server->getAddress());

        $local = Block\await($promise, $loop, self::TIMEOUT);
        /* @var $local ConnectionInterface */

        $received = 0;
        $local->on('data', function ($chunk) use (&$received) {
            $received += strlen($chunk);
        });

        $local->write(str_repeat('*', 400000));

        Block\sleep(self::TIMEOUT, $loop);

        $this->assertEquals(400000, $received);
    }

    /**
     * @requires PHP 5.6
     */
    public function testEmitsConnectionForNewTlsv11Connection()
    {
        $loop = Factory::create();

        $server = new TcpServer(0, $loop);
        $server = new SecureServer($server, $loop, array(
            'local_cert' => __DIR__ . '/../examples/localhost.pem',
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_1_SERVER
        ));
        $server->on('connection', $this->expectCallableOnce());

        $connector = new SecureConnector(new TcpConnector($loop), $loop, array(
            'verify_peer' => false,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT
        ));
        $promise = $connector->connect($server->getAddress());

        Block\await($promise, $loop, self::TIMEOUT);
    }

    /**
     * @requires PHP 5.6
     */
    public function testEmitsErrorForClientWithTlsVersionMismatch()
    {
        $loop = Factory::create();

        $server = new TcpServer(0, $loop);
        $server = new SecureServer($server, $loop, array(
            'local_cert' => __DIR__ . '/../examples/localhost.pem',
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_1_SERVER|STREAM_CRYPTO_METHOD_TLSv1_2_SERVER
        ));
        $server->on('connection', $this->expectCallableNever());
        $server->on('error', $this->expectCallableOnce());

        $connector = new SecureConnector(new TcpConnector($loop), $loop, array(
            'verify_peer' => false,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT
        ));
        $promise = $connector->connect($server->getAddress());

        $this->setExpectedException('RuntimeException', 'handshake');
        Block\await($promise, $loop, self::TIMEOUT);
    }

    public function testServerEmitsConnectionForNewConnectionWithEncryptedCertificate()
    {
        $loop = Factory::create();

        $server = new TcpServer(0, $loop);
        $server = new SecureServer($server, $loop, array(
            'local_cert' => __DIR__ . '/../examples/localhost_swordfish.pem',
            'passphrase' => 'swordfish'
        ));

        $peer = new Promise(function ($resolve, $reject) use ($server) {
            $server->on('connection', $resolve);
            $server->on('error', $reject);
        });

        $connector = new SecureConnector(new TcpConnector($loop), $loop, array(
            'verify_peer' => false
        ));
        $connector->connect($server->getAddress());

        $connection = Block\await($peer, $loop, self::TIMEOUT);

        $this->assertInstanceOf('React\Socket\ConnectionInterface', $connection);
    }

    public function testClientRejectsWithErrorForServerWithInvalidCertificate()
    {
        $loop = Factory::create();

        $server = new TcpServer(0, $loop);
        $server = new SecureServer($server, $loop, array(
            'local_cert' => 'invalid.pem'
        ));

        $connector = new SecureConnector(new TcpConnector($loop), $loop, array(
            'verify_peer' => false
        ));
        $promise = $connector->connect($server->getAddress());

        $this->setExpectedException('RuntimeException', 'handshake');
        Block\await($promise, $loop, self::TIMEOUT);
    }

    public function testServerEmitsErrorForClientWithInvalidCertificate()
    {
        $loop = Factory::create();

        $server = new TcpServer(0, $loop);
        $server = new SecureServer($server, $loop, array(
            'local_cert' => 'invalid.pem'
        ));

        $peer = new Promise(function ($resolve, $reject) use ($server) {
            $server->on('connection', function () use ($reject) {
                $reject(new \RuntimeException('Did not expect connection to succeed'));
            });
            $server->on('error', $reject);
        });

        $connector = new SecureConnector(new TcpConnector($loop), $loop, array(
            'verify_peer' => false
        ));
        $connector->connect($server->getAddress());

        $this->setExpectedException('RuntimeException', 'handshake');
        Block\await($peer, $loop, self::TIMEOUT);
    }

    public function testEmitsErrorForServerWithEncryptedCertificateMissingPassphrase()
    {
        $loop = Factory::create();

        $server = new TcpServer(0, $loop);
        $server = new SecureServer($server, $loop, array(
            'local_cert' => __DIR__ . '/../examples/localhost_swordfish.pem'
        ));
        $server->on('connection', $this->expectCallableNever());
        $server->on('error', $this->expectCallableOnce());

        $connector = new SecureConnector(new TcpConnector($loop), $loop, array(
            'verify_peer' => false
        ));
        $promise = $connector->connect($server->getAddress());

        $this->setExpectedException('RuntimeException', 'handshake');
        Block\await($promise, $loop, self::TIMEOUT);
    }

    public function testEmitsErrorForServerWithEncryptedCertificateWithInvalidPassphrase()
    {
        $loop = Factory::create();

        $server = new TcpServer(0, $loop);
        $server = new SecureServer($server, $loop, array(
            'local_cert' => __DIR__ . '/../examples/localhost_swordfish.pem',
            'passphrase' => 'nope'
        ));
        $server->on('connection', $this->expectCallableNever());
        $server->on('error', $this->expectCallableOnce());

        $connector = new SecureConnector(new TcpConnector($loop), $loop, array(
            'verify_peer' => false
        ));
        $promise = $connector->connect($server->getAddress());

        $this->setExpectedException('RuntimeException', 'handshake');
        Block\await($promise, $loop, self::TIMEOUT);
    }

    public function testEmitsErrorForConnectionWithPeerVerification()
    {
        $loop = Factory::create();

        $server = new TcpServer(0, $loop);
        $server = new SecureServer($server, $loop, array(
            'local_cert' => __DIR__ . '/../examples/localhost.pem'
        ));
        $server->on('connection', $this->expectCallableNever());
        $errorEvent = $this->createPromiseForServerError($server);

        $connector = new SecureConnector(new TcpConnector($loop), $loop, array(
            'verify_peer' => true
        ));
        $promise = $connector->connect($server->getAddress());
        $promise->then(null, $this->expectCallableOnce());

        Block\await($errorEvent, $loop, self::TIMEOUT);
    }

    public function testEmitsErrorIfConnectionIsCancelled()
    {
        if (PHP_OS !== 'Linux') {
            $this->markTestSkipped('Linux only (OS is ' . PHP_OS . ')');
        }

        $loop = Factory::create();

        $server = new TcpServer(0, $loop);
        $server = new SecureServer($server, $loop, array(
            'local_cert' => __DIR__ . '/../examples/localhost.pem'
        ));
        $server->on('connection', $this->expectCallableNever());
        $errorEvent = $this->createPromiseForServerError($server);

        $connector = new SecureConnector(new TcpConnector($loop), $loop, array(
            'verify_peer' => false
        ));
        $promise = $connector->connect($server->getAddress());
        $promise->cancel();
        $promise->then(null, $this->expectCallableOnce());

        Block\await($errorEvent, $loop, self::TIMEOUT);
    }

    public function testEmitsErrorIfConnectionIsClosedBeforeHandshake()
    {
        $loop = Factory::create();

        $server = new TcpServer(0, $loop);
        $server = new SecureServer($server, $loop, array(
            'local_cert' => __DIR__ . '/../examples/localhost.pem'
        ));
        $server->on('connection', $this->expectCallableNever());
        $errorEvent = $this->createPromiseForServerError($server);

        $connector = new TcpConnector($loop);
        $promise = $connector->connect(str_replace('tls://', '', $server->getAddress()));

        $promise->then(function (ConnectionInterface $stream) {
            $stream->close();
        });

        $error = Block\await($errorEvent, $loop, self::TIMEOUT);

        // Connection from tcp://127.0.0.1:39528 failed during TLS handshake: Connection lost during TLS handshak
        $this->assertInstanceOf('RuntimeException', $error);
        $this->assertStringStartsWith('Connection from tcp://', $error->getMessage());
        $this->assertStringEndsWith('failed during TLS handshake: Connection lost during TLS handshake', $error->getMessage());
        $this->assertEquals(defined('SOCKET_ECONNRESET') ? SOCKET_ECONNRESET : 0, $error->getCode());
        $this->assertNull($error->getPrevious());
    }

    public function testEmitsErrorIfConnectionIsClosedWithIncompleteHandshake()
    {
        $loop = Factory::create();

        $server = new TcpServer(0, $loop);
        $server = new SecureServer($server, $loop, array(
            'local_cert' => __DIR__ . '/../examples/localhost.pem'
        ));
        $server->on('connection', $this->expectCallableNever());
        $errorEvent = $this->createPromiseForServerError($server);

        $connector = new TcpConnector($loop);
        $promise = $connector->connect(str_replace('tls://', '', $server->getAddress()));

        $promise->then(function (ConnectionInterface $stream) {
            $stream->end("\x1e");
        });

        $error = Block\await($errorEvent, $loop, self::TIMEOUT);

        // Connection from tcp://127.0.0.1:39528 failed during TLS handshake: Connection lost during TLS handshak
        $this->assertInstanceOf('RuntimeException', $error);
        $this->assertStringStartsWith('Connection from tcp://', $error->getMessage());
        $this->assertStringEndsWith('failed during TLS handshake: Connection lost during TLS handshake', $error->getMessage());
        $this->assertEquals(defined('SOCKET_ECONNRESET') ? SOCKET_ECONNRESET : 0, $error->getCode());
        $this->assertNull($error->getPrevious());
    }

    public function testEmitsNothingIfConnectionIsIdle()
    {
        $loop = Factory::create();

        $server = new TcpServer(0, $loop);
        $server = new SecureServer($server, $loop, array(
            'local_cert' => __DIR__ . '/../examples/localhost.pem'
        ));
        $server->on('connection', $this->expectCallableNever());
        $server->on('error', $this->expectCallableNever());

        $connector = new TcpConnector($loop);
        $promise = $connector->connect(str_replace('tls://', '', $server->getAddress()));

        $promise->then($this->expectCallableOnce());
        Block\sleep(self::TIMEOUT, $loop);
    }

    public function testEmitsErrorIfConnectionIsHttpInsteadOfSecureHandshake()
    {
        $loop = Factory::create();

        $server = new TcpServer(0, $loop);
        $server = new SecureServer($server, $loop, array(
            'local_cert' => __DIR__ . '/../examples/localhost.pem'
        ));
        $server->on('connection', $this->expectCallableNever());
        $errorEvent = $this->createPromiseForServerError($server);

        $connector = new TcpConnector($loop);
        $promise = $connector->connect(str_replace('tls://', '', $server->getAddress()));

        $promise->then(function (ConnectionInterface $stream) {
            $stream->write("GET / HTTP/1.0\r\n\r\n");
        });

        $error = Block\await($errorEvent, $loop, self::TIMEOUT);

        $this->assertInstanceOf('RuntimeException', $error);

        // OpenSSL error messages are version/platform specific
        // Unable to complete TLS handshake: SSL operation failed with code 1. OpenSSL Error messages: error:1408F10B:SSL routines:SSL3_GET_RECORD:http request
        // Unable to complete TLS handshake: SSL operation failed with code 1. OpenSSL Error messages: error:1408F10B:SSL routines:ssl3_get_record:wrong version number
        // Unable to complete TLS handshake: SSL operation failed with code 1. OpenSSL Error messages: error:1408F10B:SSL routines:func(143):reason(267)
        // Unable to complete TLS handshake: Failed setting RSA key
    }

    public function testEmitsErrorIfConnectionIsUnknownProtocolInsteadOfSecureHandshake()
    {
        $loop = Factory::create();

        $server = new TcpServer(0, $loop);
        $server = new SecureServer($server, $loop, array(
            'local_cert' => __DIR__ . '/../examples/localhost.pem'
        ));
        $server->on('connection', $this->expectCallableNever());
        $errorEvent = $this->createPromiseForServerError($server);

        $connector = new TcpConnector($loop);
        $promise = $connector->connect(str_replace('tls://', '', $server->getAddress()));

        $promise->then(function (ConnectionInterface $stream) {
            $stream->write("Hello world!\n");
        });

        $error = Block\await($errorEvent, $loop, self::TIMEOUT);

        $this->assertInstanceOf('RuntimeException', $error);

        // OpenSSL error messages are version/platform specific
        // Unable to complete TLS handshake: SSL operation failed with code 1. OpenSSL Error messages: error:1408F10B:SSL routines:SSL3_GET_RECORD:unknown protocol
        // Unable to complete TLS handshake: SSL operation failed with code 1. OpenSSL Error messages: error:1408F10B:SSL routines:ssl3_get_record:wrong version number
        // Unable to complete TLS handshake: SSL operation failed with code 1. OpenSSL Error messages: error:1408F10B:SSL routines:func(143):reason(267)
        // Unable to complete TLS handshake: Failed setting RSA key
    }

    private function createPromiseForServerError(ServerInterface $server)
    {
        return $this->createPromiseForEvent($server, 'error', function ($error) {
            return $error;
        });
    }

    private function createPromiseForEvent(EventEmitterInterface $emitter, $event, $fn)
    {
        return new Promise(function ($resolve) use ($emitter, $event, $fn) {
            $emitter->on($event, function () use ($resolve, $fn) {
                $resolve(call_user_func_array($fn, func_get_args()));
            });
        });
    }

    private function supportsTls13()
    {
        // TLS 1.3 is supported as of OpenSSL 1.1.1 (https://www.openssl.org/blog/blog/2018/09/11/release111/)
        // The OpenSSL library version can only be obtained by parsing output from phpinfo().
        // OPENSSL_VERSION_TEXT refers to header version which does not necessarily match actual library version
        // see php -i | grep OpenSSL
        // OpenSSL Library Version => OpenSSL 1.1.1  11 Sep 2018
        ob_start();
        phpinfo(INFO_MODULES);
        $info = ob_get_clean();

        if (preg_match('/OpenSSL Library Version => OpenSSL (\S+)/', $info, $match)) {
            return version_compare($match[1], '1.1.1', '>=');
        }
        return false;
    }
}
