<?php

namespace React\Tests\Socket;

use React\EventLoop\Factory as LoopFactory;
use React\Socket\TcpServer;
use React\Socket\SecureServer;
use React\Socket\TcpConnector;
use React\Socket\SecureConnector;
use Clue\React\Block;
use React\Promise\Promise;
use Evenement\EventEmitterInterface;
use React\Promise\Deferred;
use React\Socket\ConnectionInterface;

class SecureIntegrationTest extends TestCase
{
    const TIMEOUT = 0.5;

    private $loop;
    private $server;
    private $connector;
    private $address;

    public function setUp()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('Not supported on your platform (outdated HHVM?)');
        }

        $this->loop = LoopFactory::create();
        $this->server = new TcpServer(0, $this->loop);
        $this->server = new SecureServer($this->server, $this->loop, array(
            'local_cert' => __DIR__ . '/../examples/localhost.pem'
        ));
        $this->address = $this->server->getAddress();
        $this->connector = new SecureConnector(new TcpConnector($this->loop), $this->loop, array('verify_peer' => false));
    }

    public function tearDown()
    {
        if ($this->server !== null) {
            $this->server->close();
            $this->server = null;
        }
    }

    public function testConnectToServer()
    {
        $client = Block\await($this->connector->connect($this->address), $this->loop, self::TIMEOUT);
        /* @var $client ConnectionInterface */

        $client->close();

        // if we reach this, then everything is good
        $this->assertNull(null);
    }

    public function testConnectToServerEmitsConnection()
    {
        $promiseServer = $this->createPromiseForEvent($this->server, 'connection', $this->expectCallableOnce());

        $promiseClient = $this->connector->connect($this->address);

        list($_, $client) = Block\awaitAll(array($promiseServer, $promiseClient), $this->loop, self::TIMEOUT);
        /* @var $client ConnectionInterface */

        $client->close();
    }

    public function testSendSmallDataToServerReceivesOneChunk()
    {
        // server expects one connection which emits one data event
        $received = new Deferred();
        $this->server->on('connection', function (ConnectionInterface $peer) use ($received) {
            $peer->on('data', function ($chunk) use ($received) {
                $received->resolve($chunk);
            });
        });

        $client = Block\await($this->connector->connect($this->address), $this->loop, self::TIMEOUT);
        /* @var $client ConnectionInterface */

        $client->write('hello');

        // await server to report one "data" event
        $data = Block\await($received->promise(), $this->loop, self::TIMEOUT);

        $client->close();

        $this->assertEquals('hello', $data);
    }

    public function testSendDataWithEndToServerReceivesAllData()
    {
        // PHP can report EOF on TLS 1.3 stream before consuming all data, so
        // we explicitly use older TLS version instead. Selecting TLS version
        // requires PHP 5.6+, so skip legacy versions if TLS 1.3 is supported.
        // Continue if TLS 1.3 is not supported anyway.
        if ($this->supportsTls13()) {
            if (!defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $this->markTestSkipped('TLS 1.3 supported, but this legacy PHP version does not support explicit choice');
            }

            $this->connector = new SecureConnector(new TcpConnector($this->loop), $this->loop, array(
                'verify_peer' => false,
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
            ));
        }

        $disconnected = new Deferred();
        $this->server->on('connection', function (ConnectionInterface $peer) use ($disconnected) {
            $received = '';
            $peer->on('data', function ($chunk) use (&$received) {
                $received .= $chunk;
            });
            $peer->on('close', function () use (&$received, $disconnected) {
                $disconnected->resolve($received);
            });
        });

        $client = Block\await($this->connector->connect($this->address), $this->loop, self::TIMEOUT);
        /* @var $client ConnectionInterface */

        $data = str_repeat('a', 200000);
        $client->end($data);

        // await server to report connection "close" event
        $received = Block\await($disconnected->promise(), $this->loop, self::TIMEOUT);

        $this->assertEquals(strlen($data), strlen($received));
        $this->assertEquals($data, $received);
    }

    public function testSendDataWithoutEndingToServerReceivesAllData()
    {
        $received = '';
        $this->server->on('connection', function (ConnectionInterface $peer) use (&$received) {
            $peer->on('data', function ($chunk) use (&$received) {
                $received .= $chunk;
            });
        });

        $client = Block\await($this->connector->connect($this->address), $this->loop, self::TIMEOUT);
        /* @var $client ConnectionInterface */

        $data = str_repeat('d', 200000);
        $client->write($data);

        // buffer incoming data for 0.1s (should be plenty of time)
        Block\sleep(0.1, $this->loop);

        $client->close();

        $this->assertEquals(strlen($data), strlen($received));
        $this->assertEquals($data, $received);
    }

    public function testConnectToServerWhichSendsSmallDataReceivesOneChunk()
    {
        $this->server->on('connection', function (ConnectionInterface $peer) {
            $peer->write('hello');
        });

        $client = Block\await($this->connector->connect($this->address), $this->loop, self::TIMEOUT);
        /* @var $client ConnectionInterface */

        // await client to report one "data" event
        $receive = $this->createPromiseForEvent($client, 'data', $this->expectCallableOnceWith('hello'));
        Block\await($receive, $this->loop, self::TIMEOUT);

        $client->close();
    }

    public function testConnectToServerWhichSendsDataWithEndReceivesAllData()
    {
        $data = str_repeat('b', 100000);
        $this->server->on('connection', function (ConnectionInterface $peer) use ($data) {
            $peer->end($data);
        });

        $client = Block\await($this->connector->connect($this->address), $this->loop, self::TIMEOUT);
        /* @var $client ConnectionInterface */

        // await data from client until it closes
        $received = $this->buffer($client, $this->loop, self::TIMEOUT);

        $this->assertEquals($data, $received);
    }

    public function testConnectToServerWhichSendsDataWithoutEndingReceivesAllData()
    {
        $data = str_repeat('c', 100000);
        $this->server->on('connection', function (ConnectionInterface $peer) use ($data) {
            $peer->write($data);
        });

        $client = Block\await($this->connector->connect($this->address), $this->loop, self::TIMEOUT);
        /* @var $client ConnectionInterface */

        // buffer incoming data for 0.1s (should be plenty of time)
        $received = '';
        $client->on('data', function ($chunk) use (&$received) {
            $received .= $chunk;
        });
        Block\sleep(0.1, $this->loop);

        $client->close();

        $this->assertEquals($data, $received);
    }

    private function createPromiseForEvent(EventEmitterInterface $emitter, $event, $fn)
    {
        return new Promise(function ($resolve) use ($emitter, $event, $fn) {
            $emitter->on($event, function () use ($resolve, $fn) {
                $resolve(call_user_func_array($fn, func_get_args()));
            });
        });
    }
}
