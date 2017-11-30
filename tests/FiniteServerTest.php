<?php

namespace React\Tests\Socket;

use React\Socket\FiniteServer;

class FiniteServerTest extends TestCase
{
    public function testDefaultTimes()
    {
        $socketServer = $this->getMockBuilder('\React\Socket\ServerInterface')->getMock();
        $tcpConnection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $tcpConnection->expects($this->exactly(0))->method('on');
        $server = new FiniteServer($socketServer);
        $server->handleConnection($tcpConnection);
        $server->handleConnection($tcpConnection);
        $server->handleConnection($tcpConnection);
        $server->handleConnection($tcpConnection);
        $server->handleConnection($tcpConnection);
    }

    public function testInvalidTimesWorkingAsNeverStop()
    {
        $socketServer = $this->getMockBuilder('\React\Socket\ServerInterface')->getMock();
        $tcpConnection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $tcpConnection->expects($this->exactly(0))->method('on');
        $server = new FiniteServer($socketServer, -1);
        $server->handleConnection($tcpConnection);
        $server->handleConnection($tcpConnection);
    }

    public function testNeverStop()
    {
        $socketServer = $this->getMockBuilder('\React\Socket\ServerInterface')->getMock();
        $tcpConnection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $tcpConnection->expects($this->exactly(0))->method('on');
        $server = new FiniteServer($socketServer, FiniteServer::NEVER_STOP);
        $server->handleConnection($tcpConnection);
        $server->handleConnection($tcpConnection);
        $server->handleConnection($tcpConnection);
        $server->handleConnection($tcpConnection);
        $server->handleConnection($tcpConnection);
    }

    public function testRunAndStop()
    {
        $socketServer = $this->getMockBuilder('\React\Socket\ServerInterface')->getMock();
        $tcpConnection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $tcpConnection->expects($this->exactly(1))->method('on');
        $server = new FiniteServer($socketServer, 1);
        $server->handleConnection($tcpConnection);
    }

    public function testRunNTimes()
    {
        $socketServer = $this->getMockBuilder('\React\Socket\ServerInterface')->getMock();
        $tcpConnection = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();
        $tcpConnection->expects($this->exactly(1))->method('on');
        $server = new FiniteServer($socketServer, 3);
        $server->handleConnection($tcpConnection);
        $server->handleConnection($tcpConnection);
        $server->handleConnection($tcpConnection);
    }
}
