<?php

namespace React\Tests\Socket;

use Evenement\EventEmitterInterface;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Socket\ConnectionInterface;
use React\Socket\SecureConnector;
use React\Socket\SecureServer;
use React\Socket\TcpConnector;
use React\Socket\TcpServer;

class SecureIntegrationTest extends TestCase
{
    const TIMEOUT = 2;

    private $server;
    private $connector;
    private $address;

    /**
     * @before
     */
    public function setUpConnector()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on legacy HHVM');
        }

        $this->server = new TcpServer(0);
        $this->server = new SecureServer($this->server, null, array(
            'local_cert' => __DIR__ . '/../examples/localhost.pem'
        ));
        $this->address = $this->server->getAddress();
        $this->connector = new SecureConnector(new TcpConnector(), null, array('verify_peer' => false));
    }

    /**
     * @after
     */
    public function tearDownServer()
    {
        if ($this->server !== null) {
            $this->server->close();
            $this->server = null;
        }
    }

    public function testConnectToServer()
    {
        $client = \React\Async\await(\React\Promise\Timer\timeout($this->connector->connect($this->address), self::TIMEOUT));
        /* @var $client ConnectionInterface */

        $client->close();

        // if we reach this, then everything is good
        $this->assertNull(null);
    }

    public function testConnectToServerEmitsConnection()
    {
        $promiseServer = $this->createPromiseForEvent($this->server, 'connection', $this->expectCallableOnce());

        $promiseClient = $this->connector->connect($this->address);

        list($_, $client) = \React\Async\await(\React\Promise\Timer\timeout(\React\Promise\all(array($promiseServer, $promiseClient)), self::TIMEOUT));
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

        $client = \React\Async\await(\React\Promise\Timer\timeout($this->connector->connect($this->address), self::TIMEOUT));
        /* @var $client ConnectionInterface */

        $client->write('hello');

        // await server to report one "data" event
        $data = \React\Async\await(\React\Promise\Timer\timeout($received->promise(), self::TIMEOUT));

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

            $this->connector = new SecureConnector(new TcpConnector(), null, array(
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

        $client = \React\Async\await(\React\Promise\Timer\timeout($this->connector->connect($this->address), self::TIMEOUT));
        /* @var $client ConnectionInterface */

        $data = str_repeat('a', 200000);
        $client->end($data);

        // await server to report connection "close" event
        $received = \React\Async\await(\React\Promise\Timer\timeout($disconnected->promise(), self::TIMEOUT));

        $this->assertEquals(strlen($data), strlen($received));
        $this->assertEquals($data, $received);
    }

    public function testSendDataWithoutEndingToServerReceivesAllData()
    {
        $server = $this->server;
        $promise = new Promise(function ($resolve, $reject) use ($server) {
            $server->on('connection', function (ConnectionInterface $connection) use ($resolve) {
                $received = '';
                $connection->on('data', function ($chunk) use (&$received, $resolve) {
                    $received .= $chunk;

                    if (strlen($received) >= 200000) {
                        $resolve($received);
                    }
                });
            });
        });

        $data = str_repeat('d', 200000);
        $connecting = $this->connector->connect($this->address);
        $connecting->then(function (ConnectionInterface $connection) use ($data) {
            $connection->write($data);
        });

        $received = \React\Async\await(\React\Promise\Timer\timeout($promise, self::TIMEOUT));

        $this->assertEquals(strlen($data), strlen($received));
        $this->assertEquals($data, $received);

        $connecting->then(function (ConnectionInterface $connection) {
            $connection->close();
        });
    }

    public function testConnectToServerWhichSendsSmallDataReceivesOneChunk()
    {
        $this->server->on('connection', function (ConnectionInterface $peer) {
            $peer->write('hello');
        });

        $client = \React\Async\await(\React\Promise\Timer\timeout($this->connector->connect($this->address), self::TIMEOUT));
        /* @var $client ConnectionInterface */

        // await client to report one "data" event
        $receive = $this->createPromiseForEvent($client, 'data', $this->expectCallableOnceWith('hello'));
        \React\Async\await(\React\Promise\Timer\timeout($receive, self::TIMEOUT));

        $client->close();
    }

    public function testConnectToServerWhichSendsDataWithEndReceivesAllData()
    {
        $data = str_repeat('b', 100000);
        $this->server->on('connection', function (ConnectionInterface $peer) use ($data) {
            $peer->end($data);
        });

        $client = \React\Async\await(\React\Promise\Timer\timeout($this->connector->connect($this->address), self::TIMEOUT));
        /* @var $client ConnectionInterface */

        // await data from client until it closes
        $received = $this->buffer($client, self::TIMEOUT);

        $this->assertEquals($data, $received);
    }

    public function testConnectToServerWhichSendsDataWithoutEndingReceivesAllData()
    {
        $data = str_repeat('c', 100000);
        $this->server->on('connection', function (ConnectionInterface $peer) use ($data) {
            $peer->write($data);
        });

        $connecting = $this->connector->connect($this->address);

        $promise = new Promise(function ($resolve, $reject) use ($connecting) {
            $connecting->then(function (ConnectionInterface $connection) use ($resolve) {
                $received = 0;
                $connection->on('data', function ($chunk) use (&$received, $resolve) {
                    $received += strlen($chunk);

                    if ($received >= 100000) {
                        $resolve($received);
                    }
                });
            }, $reject);
        });

        $received = \React\Async\await(\React\Promise\Timer\timeout($promise, self::TIMEOUT));

        $this->assertEquals(strlen($data), $received);

        $connecting->then(function (ConnectionInterface $connection) {
            $connection->close();
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
}
