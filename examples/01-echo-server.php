<?php

// Just start this server and connect to it. Everything you send to it will be
// sent back to you.
//
// $ php examples/01-echo-server.php 127.0.0.1:8000
// $ telnet localhost 8000
//
// You can also run a secure TLS echo server like this:
//
// $ php examples/01-echo-server.php tls://127.0.0.1:8000 examples/localhost.pem
// $ openssl s_client -connect localhost:8000
//
// You can also run a Unix domain socket (UDS) server like this:
//
// $ php examples/01-echo-server.php unix:///tmp/server.sock
// $ nc -U /tmp/server.sock
//
// You can also use systemd socket activation and listen on an inherited file descriptor:
//
// $ systemd-socket-activate -l 8000 php examples/01-echo-server.php php://fd/3
// $ telnet localhost 8000

require __DIR__ . '/../vendor/autoload.php';

$socket = new React\Socket\SocketServer(isset($argv[1]) ? $argv[1] : '127.0.0.1:0', array(
    'tls' => array(
        'local_cert' => isset($argv[2]) ? $argv[2] : (__DIR__ . '/localhost.pem')
    )
));

$socket->on('connection', function (React\Socket\ConnectionInterface $connection) {
    echo '[' . $connection->getRemoteAddress() . ' connected]' . PHP_EOL;
    $connection->pipe($connection);

    $connection->on('close', function () use ($connection) {
        echo '[' . $connection->getRemoteAddress() . ' disconnected]' . PHP_EOL;
    });
});

$socket->on('error', function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});

echo 'Listening on ' . $socket->getAddress() . PHP_EOL;
