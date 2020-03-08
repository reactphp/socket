<?php

namespace React\Tests\Socket;

use React\Promise\Promise;
use React\Socket\HappyEyeBallsConnectionBuilder;

class HappyEyeBallsConnectionBuilderTest extends TestCase
{
    public function testAttemptConnectionWillConnectViaConnectorToGivenIpWithPortAndHostnameFromUriParts()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('tcp://10.1.1.1:80?hostname=reactphp.org')->willReturn(new Promise(function () { }));

        $resolver = $this->getMockBuilder('React\Dns\Resolver\ResolverInterface')->getMock();
        $resolver->expects($this->never())->method('resolveAll');

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $builder->attemptConnection('10.1.1.1');
    }

    public function testAttemptConnectionWillConnectViaConnectorToGivenIpv6WithAllUriParts()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $connector = $this->getMockBuilder('React\Socket\ConnectorInterface')->getMock();
        $connector->expects($this->once())->method('connect')->with('tcp://[::1]:80/path?test=yes&hostname=reactphp.org#start')->willReturn(new Promise(function () { }));

        $resolver = $this->getMockBuilder('React\Dns\Resolver\ResolverInterface')->getMock();
        $resolver->expects($this->never())->method('resolveAll');

        $uri = 'tcp://reactphp.org:80/path?test=yes#start';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $builder->attemptConnection('::1');
    }
}
