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

        $connector = new Connector($loop);

        $connection = Block\await($connector->connect('localhost:9998'), $loop, self::TIMEOUT);

        $server->close();

        $this->assertInstanceOf('React\Socket\ConnectionInterface', $connection);

        $connection->close();
    }

    /**
     * @group internet
     */
    public function testConnectTwiceWithoutHappyEyeBallsOnlySendsSingleDnsQueryDueToLocalDnsCache()
    {
        $loop = Factory::create();

        $socket = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);

        $connector = new Connector($loop, array(
            'dns' => 'udp://' . stream_socket_get_name($socket, false),
            'happy_eyeballs' => false
        ));

        // minimal DNS proxy stub which forwards DNS messages to actual DNS server
        $received = 0;
        $loop->addReadStream($socket, function ($socket) use (&$received) {
            $request = stream_socket_recvfrom($socket, 65536, 0, $peer);

            $client = stream_socket_client('udp://8.8.8.8:53');
            fwrite($client, $request);
            $response = fread($client, 65536);

            stream_socket_sendto($socket, $response, 0, $peer);
            ++$received;
        });

        $connection = Block\await($connector->connect('example.com:80'), $loop);
        $connection->close();
        $this->assertEquals(1, $received);

        $connection = Block\await($connector->connect('example.com:80'), $loop);
        $connection->close();
        $this->assertEquals(1, $received);
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
        $loop = Factory::create();

        $connector = new Connector($loop, array('happy_eyeballs' => true));

        try {
            $ip = Block\await($this->request('ipv4.tlund.se', $connector), $loop, self::TIMEOUT);
        } catch (\Exception $e) {
            $this->checkIpv4();
            throw $e;
        }

        $this->assertSame($ip, filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4), $ip);
        $this->assertFalse(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6), $ip);
    }

    /**
     * @test
     * @group internet
     */
    public function connectionToRemoteTCP6ServerShouldResultInOurIP()
    {
        $loop = Factory::create();

        $connector = new Connector($loop, array('happy_eyeballs' => true));

        try {
            $ip = Block\await($this->request('ipv6.tlund.se', $connector), $loop, self::TIMEOUT);
        } catch (\Exception $e) {
            $this->checkIpv6();
            throw $e;
        }

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
            $connection->write("GET / HTTP/1.1\r\nHost: " . $host . "\r\nConnection: close\r\n\r\n");

            return \React\Promise\Stream\buffer($connection);
        })->then(function ($response) use ($that) {
            return $that->parseIpFromPage($response);
        });
    }

    private function checkIpv4()
    {
        if ($this->ipv4 === null) {
            $this->ipv4 = !!@file_get_contents('http://ipv4.tlund.se/');
        }

        if (!$this->ipv4) {
            $this->markTestSkipped('IPv4 connection not supported on this system');
        }
    }

    private function checkIpv6()
    {
        if ($this->ipv6 === null) {
            $this->ipv6 = !!@file_get_contents('http://ipv6.tlund.se/');
        }

        if (!$this->ipv6) {
            $this->markTestSkipped('IPv6 connection not supported on this system');
        }
    }
}
