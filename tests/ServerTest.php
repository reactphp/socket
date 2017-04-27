<?php

namespace React\Tests\Socket;

use React\EventLoop\Factory;
use React\Socket\Server;
use Clue\React\Block;

class ServerTest extends TestCase
{
    public function testCreateServer()
    {
        $loop = Factory::create();

        $server = new Server(0, $loop);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testConstructorThrowsForInvalidUri()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $server = new Server('invalid URI', $loop);
    }

    public function testEmitsConnectionForNewConnection()
    {
        $loop = Factory::create();

        $server = new Server(0, $loop);
        $server->on('connection', $this->expectCallableOnce());

        $client = stream_socket_client($server->getAddress());

        Block\sleep(0.1, $loop);
    }

    public function testDoesNotEmitConnectionForNewConnectionToPausedServer()
    {
        $loop = Factory::create();

        $server = new Server(0, $loop);
        $server->pause();


        $client = stream_socket_client($server->getAddress());

        Block\sleep(0.1, $loop);
    }

    public function testDoesEmitConnectionForNewConnectionToResumedServer()
    {
        $loop = Factory::create();

        $server = new Server(0, $loop);
        $server->pause();
        $server->on('connection', $this->expectCallableOnce());

        $client = stream_socket_client($server->getAddress());

        Block\sleep(0.1, $loop);

        $server->resume();
        Block\sleep(0.1, $loop);
    }

    public function testDoesNotAllowConnectionToClosedServer()
    {
        $loop = Factory::create();

        $server = new Server(0, $loop);
        $server->on('connection', $this->expectCallableNever());
        $address = $server->getAddress();
        $server->close();

        $client = @stream_socket_client($address);

        Block\sleep(0.1, $loop);

        $this->assertFalse($client);
    }
}
