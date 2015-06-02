<?php

namespace React\Tests\Socket;

use React\Socket\Connection;
use React\Socket\Server;
use React\EventLoop\StreamSelectLoop;

class ConnectionTest extends TestCase
{
    private $loop;
    private $server;

    /**
     * @covers React\Socket\Server::__construct
     * @covers React\Socket\Server::listen
     */
    public function setUp()
    {
        $this->loop = new StreamSelectLoop();
        $this->server = new Server($this->loop);
        $this->server->listen('tcp://127.0.0.1:4321');
    }

    /**
     * @covers React\Socket\Connection::getRemoteAddress
     * @covers React\Socket\Server::getAddress
     */
    public function testGetRemoteAddress()
    {
        $class  = new \ReflectionClass('React\\Socket\\Server');
        $master = $class->getProperty('master');
        $master->setAccessible(true);

        $client = stream_socket_client($this->server->getAddress());

        $class  = new \ReflectionClass('React\\Socket\\Connection');
        $method = $class->getMethod('parseAddress');
        $method->setAccessible(true);

        $servConn = new Connection($this->server->master, $this->loop);

        $mock = $this->createCallableMock();
        $mock
            ->expects($this->once())
            ->method('__invoke')
            ->with($method->invokeArgs($servConn, array(stream_socket_get_name($master->getValue($this->server), false))))
        ;

        $this->server->on('connection', function ($conn) use ($mock) {
            $mock($conn->getRemoteAddress());
        });
        $this->loop->tick();
    }

    public function remoteAddressProvider()
    {
        return array(
            array('192.168.1.120', '192.168.1.120:12345')
          , array('9999:0000:aaaa:bbbb:cccc:dddd:eeee:ffff', '[9999:0000:aaaa:bbbb:cccc:dddd:eeee:ffff]:12345')
          , array('10.0.0.1', '10.0.0.1:80')
        );
    }

    /**
     * @dataProvider remoteAddressProvider
     * @covers React\Socket\Connection::parseAddress
     */
    public function testParseAddress($expected, $given)
    {
        $class  = new \ReflectionClass('React\\Socket\\Connection');
        $method = $class->getMethod('parseAddress');
        $method->setAccessible(true);

        $socket = fopen('php://temp', 'r');
        $loop   = $this->createLoopMock();

        $conn = new Connection($socket, $loop);
        $result = $method->invokeArgs($conn, array($given));

        $this->assertEquals($expected, $result);
    }

    private function createLoopMock()
    {
        return $this->getMock('React\EventLoop\LoopInterface');
    }

    /**
     * @covers React\Socket\Server::shutdown
     */
    public function tearDown()
    {
        if ($this->server) {
            $this->server->shutdown();
        }
    }
}
