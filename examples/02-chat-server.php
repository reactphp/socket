<?php

// Just start this server and connect with any number of clients to it.
// Everything a client sends will be broadcasted to all connected clients.
//
// $ php examples/02-chat-server.php 8000
// $ telnet localhost 8000
//
// You can also run a secure TLS chat server like this:
//
// $ php examples/02-chat-server.php 8000 examples/localhost.pem
// $ openssl s_client -connect localhost:8000

use React\EventLoop\Factory;
use React\Socket\TcpServer;
use React\Socket\ConnectionInterface;
use React\Socket\SecureServer;
use React\Socket\LimitingServer;

require __DIR__ . '/../vendor/autoload.php';

$loop = Factory::create();

$server = new TcpServer(isset($argv[1]) ? $argv[1] : 0, $loop);

// secure TLS mode if certificate is given as second parameter
if (isset($argv[2])) {
    $server = new SecureServer($server, $loop, array(
        'local_cert' => $argv[2]
    ));
}

$server = new LimitingServer($server, null);

$server->on('connection', function (ConnectionInterface $client) use ($server) {
    // whenever a new message comes in
    $client->on('data', function ($data) use ($client, $server) {
        // remove any non-word characters (just for the demo)
        $data = trim(preg_replace('/[^\w\d \.\,\-\!\?]/u', '', $data));

        // ignore empty messages
        if ($data === '') {
            return;
        }

        // prefix with client IP and broadcast to all connected clients
        $data = $client->getRemoteAddress() . ': ' . $data . PHP_EOL;
        foreach ($server->getConnections() as $connection) {
            $connection->write($data);
        }
    });
});

$server->on('error', 'printf');

echo 'Listening on ' . $server->getAddress() . PHP_EOL;

$loop->run();
