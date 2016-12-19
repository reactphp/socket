<?php

// Just start this server and connect to it. Everything you send to it will be
// sent back to you.
//
// $ php examples/01-echo.php 8000
// $ telnet localhost 8000

use React\EventLoop\Factory;
use React\Socket\Server;
use React\Socket\ConnectionInterface;

require __DIR__ . '/../vendor/autoload.php';

$loop = Factory::create();

$server = new Server($loop);
$server->listen(isset($argv[1]) ? $argv[1] : 0);

$server->on('connection', function (ConnectionInterface $conn) use ($loop) {
    echo '[connected]' . PHP_EOL;
    $conn->pipe($conn);
});

echo 'Listening on ' . $server->getPort() . PHP_EOL;

$loop->run();
