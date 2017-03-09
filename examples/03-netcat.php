<?php

use React\EventLoop\Factory;
use React\SocketClient\TcpConnector;
use React\SocketClient\DnsConnector;
use React\SocketClient\TimeoutConnector;
use React\SocketClient\ConnectionInterface;

require __DIR__ . '/../vendor/autoload.php';

if (!isset($argv[1])) {
    fwrite(STDERR, 'Usage error: required argument <host:port>' . PHP_EOL);
    exit(1);
}

$loop = Factory::create();

$factory = new \React\Dns\Resolver\Factory();
$resolver = $factory->create('8.8.8.8', $loop);

$tcp = new TcpConnector($loop);
$dns = new DnsConnector($tcp, $resolver);

// time out connection attempt in 3.0s
$dns = new TimeoutConnector($dns, 3.0, $loop);

$stdin = new Stream(STDIN, $loop);
$stdin->pause();
$stdout = new Stream(STDOUT, $loop);
$stdout->pause();
$stderr = new Stream(STDERR, $loop);
$stderr->pause();

$stderr->write('Connecting' . PHP_EOL);

$dns->connect($argv[1])->then(function (ConnectionInterface $connection) use ($stdin, $stdout, $stderr) {
    // pipe everything from STDIN into connection
    $stdin->resume();
    $stdin->pipe($connection);

    // pipe everything from connection to STDOUT
    $connection->pipe($stdout);

    // report errors to STDERR
    $connection->on('error', function ($error) use ($stderr) {
        $stderr->write('Stream ERROR: ' . $error . PHP_EOL);
    });

    // report closing and stop reading from input
    $connection->on('close', function () use ($stderr, $stdin) {
        $stderr->write('[CLOSED]' . PHP_EOL);
        $stdin->close();
    });

    $stderr->write('Connected' . PHP_EOL);
}, function ($error) use ($stderr) {
    $stderr->write('Connection ERROR: ' . $error . PHP_EOL);
});

$loop->run();
