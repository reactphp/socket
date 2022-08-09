<?php

namespace React\Tests\Socket;

use React\Socket\FixedUriConnector;

class FixedUriConnectorTest extends TestCase
{
    public function testWillInvokeGivenConnector()
    {
        $base = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $base->expects($this->once())->method('connect')->with('test')->willReturn('ret');

        $connector = new FixedUriConnector('test', $base);

        $this->assertEquals('ret', $connector->connect('ignored'));
    }
}
