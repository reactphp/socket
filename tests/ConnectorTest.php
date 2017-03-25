<?php

namespace React\Tests\SocketClient;

use React\SocketClient\Connector;
use React\Promise\Promise;

class ConnectorTest extends TestCase
{
    public function testConnectorWithUnknownSchemeAlwaysFails()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $connector = new Connector($loop);

        $promise = $connector->connect('unknown://google.com:80');
        $promise->then(null, $this->expectCallableOnce());
    }

    public function testConnectorUsesGivenResolverInstance()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $promise = new Promise(function () { });
        $resolver = $this->getMockBuilder('React\Dns\Resolver\Resolver')->disableOriginalConstructor()->getMock();
        $resolver->expects($this->once())->method('resolve')->with('google.com')->willReturn($promise);

        $connector = new Connector($loop, array(
            'dns' => $resolver
        ));

        $connector->connect('google.com:80');
    }
}
