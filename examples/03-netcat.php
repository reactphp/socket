<?php

use React\EventLoop\Factory;
use React\SocketClient\TcpConnector;
use React\SocketClient\DnsConnector;
use React\Stream\Stream;
use React\SocketClient\TimeoutConnector;

require __DIR__ . '/../vendor/autoload.php';

if (!isset($argv[2])) {
    fwrite(STDERR, 'Usage error: required arguments <host> <port>' . PHP_EOL);
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

$dns->create($argv[1], $argv[2])->then(function (Stream $stream) use ($stdin, $stdout, $stderr) {
    // pipe everything from STDIN into connection
    $stdin->resume();
    $stdin->pipe($stream);

    // pipe everything from connection to STDOUT
    $stream->pipe($stdout);

    // report errors to STDERR
    $stream->on('error', function ($error) use ($stderr) {
        $stderr->write('Stream ERROR: ' . $error . PHP_EOL);
    });

    // report closing and stop reading from input
    $stream->on('close', function () use ($stderr, $stdin) {
        $stderr->write('[CLOSED]' . PHP_EOL);
        $stdin->close();
    });

    $stderr->write('Connected' . PHP_EOL);
}, function ($error) use ($stderr) {
    $stderr->write('Connection ERROR: ' . $error . PHP_EOL);
});

$loop->run();
