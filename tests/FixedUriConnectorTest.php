<?php

namespace React\Tests\Socket;

use React\Promise\PromiseInterface;
use React\Socket\ConnectorInterface;
use React\Socket\FixedUriConnector;

class FixedUriConnectorTest extends TestCase
{
    public function testWillInvokeGivenConnector()
    {
        $promise = $this->createMock(PromiseInterface::class);
        $base = $this->createMock(ConnectorInterface::class);
        $base->expects($this->once())->method('connect')->with('test')->willReturn($promise);

        $connector = new FixedUriConnector('test', $base);

        $this->assertSame($promise, $connector->connect('ignored'));
    }
}
