<?php

// Simple plaintext HTTP client example (for illustration purposes only).
// This shows how a plaintext TCP/IP connection is established to then send an
// application level protocol message (HTTP).
// Real applications should use react/http-client instead

use React\EventLoop\Factory;
use React\Socket\Connector;
use React\Socket\ConnectionInterface;

$target = isset($argv[1]) ? $argv[1] : 'www.google.com:80';

require __DIR__ . '/../vendor/autoload.php';

$loop = Factory::create();
$connector = new Connector($loop);

$connector->connect($target)->then(function (ConnectionInterface $connection) use ($target) {
    $connection->on('data', function ($data) {
        echo $data;
    });
    $connection->on('close', function () {
        echo '[CLOSED]' . PHP_EOL;
    });

    $connection->write("GET / HTTP/1.0\r\nHost: $target\r\n\r\n");
}, 'printf');

$loop->run();
