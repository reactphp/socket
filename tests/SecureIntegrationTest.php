<?php

namespace React\Tests\SocketClient;

use React\EventLoop\Factory as LoopFactory;
use React\Socket\Server;
use React\SocketClient\TcpConnector;
use React\SocketClient\SecureConnector;
use React\Stream\Stream;
use Clue\React\Block;

class SecureIntegrationTest extends TestCase
{
    private $portSecure;
    private $portPlain;

    public function setUp()
    {
        $this->portSecure = getenv('TEST_SECURE');
        $this->portPlain = getenv('TEST_PLAIN');

        if ($this->portSecure === false || $this->portPlain === false) {
            $this->markTestSkipped('Needs TEST_SECURE=X and TEST_PLAIN=Y environment variables to run, see README.md');
        }
    }

    public function testA()
    {
        $loop = LoopFactory::create();

        $receivedServer = '';
        $receivedClient = '';
        $connected = 0;

        $server = new Server($loop);
        $server->on('connection', function (Stream $stream) use (&$receivedServer, &$connected) {
            $connected++;

            // $stream->pipe($stream);
            $stream->on('data', function ($data) use ($stream, &$receivedServer) {
                $receivedServer .= $data;
                $stream->write($data);
            });
        });
        $server->listen($this->portPlain);

        $connector = new SecureConnector(new TcpConnector($loop), $loop, array('verify_peer' => false));

        $client = Block\await($connector->create('127.0.0.1', $this->portSecure), $loop);
        /* @var $client Stream */

        while (!$connected) {
            $loop->tick();
        }

        $client->on('data', function ($data) use (&$receivedClient) {
            $receivedClient .= $data;
        });

        $this->assertEquals('', $receivedServer);
        $this->assertEquals('', $receivedClient);

        $echo = function ($str) use (&$receivedClient, &$receivedClient, $loop, $client) {
            $receivedClient = $receivedServer = '';
            $client->write($str);
            while ($receivedClient !== $str) {
                $loop->tick();
            }
        };

        $echo('hello');

        $echo('world');

        // send a 10k message once to fill buffer
        $echo(str_repeat('1234567890', 10000));

        $echo('again');
    }
}
