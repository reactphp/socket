<?php

namespace React\Tests\SocketClient;

use React\EventLoop\Factory as LoopFactory;
use React\Socket\Server;
use React\SocketClient\TcpConnector;
use React\SocketClient\SecureConnector;
use React\Stream\Stream;
use Clue\React\Block;
use React\Promise\Promise;
use Evenement\EventEmitterInterface;
use React\Promise\Deferred;
use React\Stream\BufferedSink;

class SecureIntegrationTest extends TestCase
{
    private $portSecure;
    private $portPlain;

    private $loop;
    private $server;
    private $connector;

    public function setUp()
    {
        if (defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on HHVM');
        }

        $this->portSecure = getenv('TEST_SECURE');
        $this->portPlain = getenv('TEST_PLAIN');

        if ($this->portSecure === false || $this->portPlain === false) {
            $this->markTestSkipped('Needs TEST_SECURE=X and TEST_PLAIN=Y environment variables to run, see README.md');
        }

        $this->loop = LoopFactory::create();
        $this->server = new Server($this->loop);
        $this->server->listen($this->portPlain);
        $this->connector = new SecureConnector(new TcpConnector($this->loop), $this->loop, array('verify_peer' => false));
    }

    public function tearDown()
    {
        if ($this->server !== null) {
            $this->server->shutdown();
            $this->server = null;
        }
    }

    public function testConnectToServer()
    {
        $client = Block\await($this->connector->create('127.0.0.1', $this->portSecure), $this->loop);
        /* @var $client Stream */

        $client->close();
    }

    public function testConnectToServerEmitsConnection()
    {
        $promiseServer = $this->createPromiseForEvent($this->server, 'connection', $this->expectCallableOnce());

        $promiseClient = $this->connector->create('127.0.0.1', $this->portSecure);

        list($_, $client) = Block\awaitAll(array($promiseServer, $promiseClient), $this->loop);
        /* @var $client Stream */

        $client->close();
    }

    public function testSendSmallDataToServerReceivesOneChunk()
    {
        // server expects one connection which emits one data event
        $received = new Deferred();
        $this->server->on('connection', function (Stream $peer) use ($received) {
            $peer->on('data', function ($chunk) use ($received) {
                $received->resolve($chunk);
            });
        });

        $client = Block\await($this->connector->create('127.0.0.1', $this->portSecure), $this->loop);
        /* @var $client Stream */

        $client->write('hello');

        // await server to report one "data" event
        $data = Block\await($received->promise(), $this->loop);

        $client->close();

        $this->assertEquals('hello', $data);
    }

    public function testSendDataWithEndToServerReceivesAllData()
    {
        $disconnected = new Deferred();
        $this->server->on('connection', function (Stream $peer) use ($disconnected) {
            $received = '';
            $peer->on('data', function ($chunk) use (&$received) {
                $received .= $chunk;
            });
            $peer->on('close', function () use (&$received, $disconnected) {
                $disconnected->resolve($received);
            });
        });

        $client = Block\await($this->connector->create('127.0.0.1', $this->portSecure), $this->loop);
        /* @var $client Stream */

        $data = str_repeat('a', 200000);
        $client->end($data);

        // await server to report connection "close" event
        $received = Block\await($disconnected->promise(), $this->loop);

        $this->assertEquals($data, $received);
    }

    public function testSendDataWithoutEndingToServerReceivesAllData()
    {
        $received = '';
        $this->server->on('connection', function (Stream $peer) use (&$received) {
            $peer->on('data', function ($chunk) use (&$received) {
                $received .= $chunk;
            });
        });

        $client = Block\await($this->connector->create('127.0.0.1', $this->portSecure), $this->loop);
        /* @var $client Stream */

        $data = str_repeat('d', 200000);
        $client->write($data);

        // buffer incoming data for 0.1s (should be plenty of time)
        Block\sleep(0.1, $this->loop);

        $client->close();

        $this->assertEquals($data, $received);
    }

    public function testConnectToServerWhichSendsSmallDataReceivesOneChunk()
    {
        $this->server->on('connection', function (Stream $peer) {
            $peer->write('hello');
        });

        $client = Block\await($this->connector->create('127.0.0.1', $this->portSecure), $this->loop);
        /* @var $client Stream */

        // await client to report one "data" event
        $receive = $this->createPromiseForEvent($client, 'data', $this->expectCallableOnceWith('hello'));
        Block\await($receive, $this->loop);

        $client->close();
    }

    public function testConnectToServerWhichSendsDataWithEndReceivesAllData()
    {
        $data = str_repeat('b', 100000);
        $this->server->on('connection', function (Stream $peer) use ($data) {
            $peer->end($data);
        });

        $client = Block\await($this->connector->create('127.0.0.1', $this->portSecure), $this->loop);
        /* @var $client Stream */

        // await data from client until it closes
        $received = Block\await(BufferedSink::createPromise($client), $this->loop);

        $this->assertEquals($data, $received);
    }

    public function testConnectToServerWhichSendsDataWithoutEndingReceivesAllData()
    {
        $data = str_repeat('c', 100000);
        $this->server->on('connection', function (Stream $peer) use ($data) {
            $peer->write($data);
        });

        $client = Block\await($this->connector->create('127.0.0.1', $this->portSecure), $this->loop);
        /* @var $client Stream */

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
