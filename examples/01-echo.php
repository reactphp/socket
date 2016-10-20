<?php

// Just start this server and connect to it. Everything you send to it will be
// sent back to you.
//
// $ php examples/01-echo.php 8000
// $ telnet localhost 8000
//
// You can also run a secure TLS echo server like this:
//
// $ php examples/01-echo.php 8000 examples/localhost.pem
// $ openssl s_client -connect localhost:8000

use React\EventLoop\Factory;
use React\Socket\Server;
use React\Socket\ConnectionInterface;
use React\Socket\SecureServer;

require __DIR__ . '/../vendor/autoload.php';

$loop = Factory::create();

$server = new Server($loop);

// secure TLS mode if certificate is given as second parameter
if (isset($argv[2])) {
    $server = new SecureServer($server, $loop, array(
        'local_cert' => $argv[2]
    ));
}

$server->listen(isset($argv[1]) ? $argv[1] : 0);

$server->on('connection', function (ConnectionInterface $conn) use ($loop) {
    echo '[connected]' . PHP_EOL;
    $conn->pipe($conn);
});

$server->on('error', 'printf');

echo 'bound to ' . $server->getPort() . PHP_EOL;

$loop->run();
