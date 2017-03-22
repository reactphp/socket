<?php

// Just start this server and connect to it. Everything you send to it will be
// sent back to you.
//
// $ php examples/01-echo.php unix:///tmp/my.sock
// $ socat - UNIX-CONNECT:/tmp/my.sock

use React\EventLoop\Factory;
use React\Socket\Server;
use React\Socket\ConnectionInterface;

require __DIR__ . '/../vendor/autoload.php';

$loop = Factory::create();

$server = new Server(isset($argv[1]) ? $argv[1] : 'unix:///tmp/my.sock', $loop);
$server->on('connection', function (ConnectionInterface $conn) {
    echo '[connected]' . PHP_EOL;
    $conn->pipe($conn);
});

$server->on('error', 'printf');

echo 'Listening on ' . $server->getAddress() . PHP_EOL;

$loop->run();
