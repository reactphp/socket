<?php

namespace React\Tests\Socket;

use Clue\React\Block;
use React\EventLoop\Factory;
use React\Socket\UnixConnection;
use React\Socket\UnixConnector;
use React\Socket\UnixServer;

class FunctionalUnixServerTest extends TestCase
{
    public function testEmitsConnectionForNewConnection()
    {
        $loop = Factory::create();

        $server = new UnixServer($this->getRandomSocketUri(), $loop);
        $server->on('connection', $this->expectCallableOnce());

        $connector = new UnixConnector($loop);
        $promise = $connector->connect($server->getAddress());

        $promise->then($this->expectCallableOnce());

        Block\sleep(0.1, $loop);
    }

    public function testEmitsNoConnectionForNewConnectionWhenPaused()
    {
        $loop = Factory::create();

        $server = new UnixServer($this->getRandomSocketUri(), $loop);
        $server->on('connection', $this->expectCallableNever());
        $server->pause();

        $connector = new UnixConnector($loop);
        $promise = $connector->connect($server->getAddress());

        $promise->then($this->expectCallableOnce());

        Block\sleep(0.1, $loop);
    }

    public function testEmitsConnectionForNewConnectionWhenResumedAfterPause()
    {
        $loop = Factory::create();

        $server = new UnixServer($this->getRandomSocketUri(), $loop);
        $server->on('connection', $this->expectCallableOnce());
        $server->pause();
        $server->resume();

        $connector = new UnixConnector($loop);
        $promise = $connector->connect($server->getAddress());

        $promise->then($this->expectCallableOnce());

        Block\sleep(0.1, $loop);
    }

    public function testConnectionDetailsCanBeRetrieved()
    {
        $loop = Factory::create();

        $server = new UnixServer($this->getRandomSocketUri(), $loop);
        $remote_address = null;
        $remote_pid = null;
        $local_address = null;
        $local_pid = null;
        $server->on('connection', function (UnixConnection $conn) use (&$remote_address, &$remote_pid, &$local_address, &$local_pid) {
            $remote_address = $conn->getRemoteAddress();
            $remote_pid = $conn->getRemotePid();
            $local_address = $conn->getLocalAddress();
            $local_pid = $conn->getLocalPid();
        });

        $connector = new UnixConnector($loop);
        $promise = $connector->connect($server->getAddress());

        $promise->then($this->expectCallableOnce());

        Block\sleep(0.1, $loop);

        $this->assertNull($remote_address);
        $this->assertValidPid($remote_pid);
        $this->assertContains('unix:///tmp/', $local_address);
        $this->assertSame($server->getAddress(), $local_address);
        $this->assertValidPid($local_pid);
    }

    public function testRemotePidCannotBeRetrievedAfterConnectionIsClosedLocally()
    {
        $loop = Factory::create();

        $server = new UnixServer($this->getRandomSocketUri(), $loop);
        $remote_pid = false;
        $server->on('connection', function (UnixConnection $conn) use (&$remote_pid) {
            $conn->close();
            $remote_pid = $conn->getRemotePid();
        });

        $connector = new UnixConnector($loop);
        $promise = $connector->connect($server->getAddress());

        $promise->then($this->expectCallableOnce());

        Block\sleep(0.1, $loop);

        // Will be null since the connection was closed before the pid was requested.
        $this->assertNull($remote_pid);
    }

    public function testRemotePidCanBeRetrievedAfterConnectionIsClosedLocallyIfRemotePidWasCachedBeforeClose()
    {
        $loop = Factory::create();

        $server = new UnixServer($this->getRandomSocketUri(), $loop);
        $remote_pid = false;
        $server->on('connection', function (UnixConnection $conn) use (&$remote_pid) {
            $conn->getRemotePid();
            $conn->close();
            $remote_pid = $conn->getRemotePid();
        });

        $connector = new UnixConnector($loop);
        $promise = $connector->connect($server->getAddress());

        $promise->then($this->expectCallableOnce());

        Block\sleep(0.1, $loop);

        // The cached value will be used so it is known.
        $this->assertValidPid($remote_pid);
    }
}
