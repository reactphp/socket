<?php

namespace React\Tests\Socket;

use Clue\React\Block;
use React\EventLoop\Factory;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Socket\ConnectorInterface;
use React\Socket\TcpServer;

class FunctionalConnectorTest extends TestCase
{
    const TIMEOUT = 30.0;

    private $ipv4;
    private $ipv6;

    /** @test */
    public function connectionToTcpServerShouldSucceedWithLocalhost()
    {
        $loop = Factory::create();

        $server = new TcpServer(9998, $loop);
        $server->on('connection', $this->expectCallableOnce());
        $server->on('connection', array($server, 'close'));

        $connector = new Connector($loop);

        $connection = Block\await($connector->connect('localhost:9998'), $loop, self::TIMEOUT);

        $this->assertInstanceOf('React\Socket\ConnectionInterface', $connection);

        $connection->close();
        $server->close();
    }

    /**
     * @test
     * @group internet
     */
    public function connectionToRemoteTCP4n6ServerShouldResultInOurIP()
    {
        $loop = Factory::create();

        $connector = new Connector($loop, array('happy_eyeballs' => true));

        $ip = Block\await($this->request('dual.tlund.se', $connector), $loop, self::TIMEOUT);

        $this->assertSame($ip, filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6), $ip);
    }

    /**
     * @test
     * @group internet
     */
    public function connectionToRemoteTCP4ServerShouldResultInOurIP()
    {
        if ($this->ipv4() === false) {
            // IPv4 not supported on this system
            $this->assertFalse($this->ipv4());
            return;
        }

        $loop = Factory::create();

        $connector = new Connector($loop, array('happy_eyeballs' => true));

        $ip = Block\await($this->request('ipv4.tlund.se', $connector), $loop, self::TIMEOUT);

        $this->assertSame($ip, filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4), $ip);
        $this->assertFalse(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6), $ip);
    }

    /**
     * @test
     * @group internet
     */
    public function connectionToRemoteTCP6ServerShouldResultInOurIP()
    {
        if ($this->ipv6() === false) {
            // IPv6 not supported on this system
            $this->assertFalse($this->ipv6());
            return;
        }

        $loop = Factory::create();

        $connector = new Connector($loop, array('happy_eyeballs' => true));

        $ip = Block\await($this->request('ipv6.tlund.se', $connector), $loop, self::TIMEOUT);

        $this->assertFalse(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4), $ip);
        $this->assertSame($ip, filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6), $ip);
    }

    /**
     * @test
     * @group internet
     *
     * @expectedException \RuntimeException
     * @expectedExceptionMessageRegExp /Connection to ipv6.tlund.se:80 failed/
     */
    public function tryingToConnectToAnIPv6OnlyHostWithOutHappyEyeBallsShouldResultInFailure()
    {
        $loop = Factory::create();

        $connector = new Connector($loop, array('happy_eyeballs' => false));

        Block\await($this->request('ipv6.tlund.se', $connector), $loop, self::TIMEOUT);
    }

    /**
     * @test
     * @group internet
     *
     * @expectedException \RuntimeException
     * @expectedExceptionMessageRegExp /Connection to tcp:\/\/193.15.228.195:80 failed:/
     */
    public function connectingDirectlyToAnIPv4AddressShouldFailWhenIPv4IsntAvailable()
    {
        if ($this->ipv4() === true) {
            // IPv4 supported on this system
            throw new \RuntimeException('Connection to tcp://193.15.228.195:80 failed:');
        }

        $loop = Factory::create();

        $connector = new Connector($loop);

        $host = current(dns_get_record('ipv4.tlund.se', DNS_A));
        $host = $host['ip'];
        Block\await($this->request($host, $connector), $loop, self::TIMEOUT);
    }

    /**
     * @test
     * @group internet
     *
     * @expectedException \RuntimeException
     * @expectedExceptionMessageRegExp /Connection to tcp:\/\/\[2a00:801:f::195\]:80 failed:/
     */
    public function connectingDirectlyToAnIPv6AddressShouldFailWhenIPv6IsntAvailable()
    {
        if ($this->ipv6() === true) {
            // IPv6 supported on this system
            throw new \RuntimeException('Connection to tcp://[2a00:801:f::195]:80 failed:');
        }

        $loop = Factory::create();

        $connector = new Connector($loop);

        $host = current(dns_get_record('ipv6.tlund.se', DNS_AAAA));
        $host = $host['ipv6'];
        $host = '[' . $host . ']';
        $ip = Block\await($this->request($host, $connector), $loop, self::TIMEOUT);

        $this->assertFalse(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4), $ip);
        $this->assertSame($ip, filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6), $ip);
    }

    /**
     * @internal
     */
    public function parseIpFromPage($body)
    {
        $ex = explode('title="Look up on bgp.he.net">', $body);
        $ex = explode('<', $ex[1]);

        return $ex[0];
    }

    private function request($host, ConnectorInterface $connector)
    {
        $that = $this;
        return $connector->connect($host . ':80')->then(function (ConnectionInterface $connection) use ($host) {
            $connection->write("GET / HTTP/1.1\r\nHost: " . $host . "\r\n\r\n");

            return \React\Promise\Stream\buffer($connection);
        })->then(function ($response) use ($that) {
            return $that->parseIpFromPage($response);
        });
    }

    private function ipv4()
    {
        if ($this->ipv4 !== null) {
            return $this->ipv4;
        }

        $this->ipv4 = !!@file_get_contents('http://ipv4.tlund.se/');

        return $this->ipv4;
    }

    private function ipv6()
    {
        if ($this->ipv6 !== null) {
            return $this->ipv6;
        }

        $this->ipv6 = !!@file_get_contents('http://ipv6.tlund.se/');

        return $this->ipv6;
    }
}
