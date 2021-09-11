<?php

// Just start this server and connect with any number of clients to it.
// Everything a client sends will be broadcasted to all connected clients.
//
// $ php examples/02-chat-server.php 127.0.0.1:8000
// $ telnet localhost 8000
//
// You can also run a secure TLS chat server like this:
//
// $ php examples/02-chat-server.php tls://127.0.0.1:8000 examples/localhost.pem
// $ openssl s_client -connect localhost:8000
//
// You can also run a Unix domain socket (UDS) server like this:
//
// $ php examples/02-chat-server.php unix:///tmp/server.sock
// $ nc -U /tmp/server.sock
//
// You can also use systemd socket activation and listen on an inherited file descriptor:
//
// $ systemd-socket-activate -l 8000 php examples/02-chat-server.php php://fd/3
// $ telnet localhost 8000

require __DIR__ . '/../vendor/autoload.php';

$socket = new React\Socket\SocketServer(isset($argv[1]) ? $argv[1] : '127.0.0.1:0', array(
    'tls' => array(
        'local_cert' => isset($argv[2]) ? $argv[2] : (__DIR__ . '/localhost.pem')
    )
));

$socket = new React\Socket\LimitingServer($socket, null);

$socket->on('connection', function (React\Socket\ConnectionInterface $connection) use ($socket) {
    echo '[' . $connection->getRemoteAddress() . ' connected]' . PHP_EOL;

    // whenever a new message comes in
    $connection->on('data', function ($data) use ($connection, $socket) {
        // remove any non-word characters (just for the demo)
        $data = trim(preg_replace('/[^\w\d \.\,\-\!\?]/u', '', $data));

        // ignore empty messages
        if ($data === '') {
            return;
        }

        // prefix with client IP and broadcast to all connected clients
        $data = trim(parse_url($connection->getRemoteAddress(), PHP_URL_HOST), '[]') . ': ' . $data . PHP_EOL;
        foreach ($socket->getConnections() as $connection) {
            $connection->write($data);
        }
    });

    $connection->on('close', function () use ($connection) {
        echo '[' . $connection->getRemoteAddress() . ' disconnected]' . PHP_EOL;
    });
});

$socket->on('error', function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});

echo 'Listening on ' . $socket->getAddress() . PHP_EOL;
