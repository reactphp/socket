<?php

use React\SocketClient\Connector;
use React\SocketClient\SecureConnector;
use React\Socket\Server;
use React\Stream\Stream;
require __DIR__ . '/../vendor/autoload.php';

$loop = React\EventLoop\Factory::create();

$server = new Server($loop);
$server->on('connection', function (Stream $stream) {
    echo 'connected' . PHP_EOL;

    // $stream->pipe($stream);
    $stream->on('data', function ($data) use ($stream) {
        echo 'server received: ' . $data . PHP_EOL;
        $stream->write($data);
    });
});
$server->listen(6000);

$dnsResolverFactory = new React\Dns\Resolver\Factory();
$resolver = $dnsResolverFactory->createCached('8.8.8.8', $loop);

$connector = new Connector($loop, $resolver);
$secureConnector = new SecureConnector($connector, $loop);

$promise = $secureConnector->create('127.0.0.1', 6001);
//$promise = $connector->create('127.0.0.1', 6000);

$promise->then(
    function (Stream $client) use ($loop) {
        $loop->addReadStream(STDIN, function ($fd) use ($client) {
            echo 'client send: ';
            $m = rtrim(fread($fd, 8192));
            echo $m . PHP_EOL;
            $client->write($m);
        });

        //$stdin = new Stream(STDIN, $loop);
        //$stdin->pipe($client);
        $client->on('data', function ($data) {
            echo 'client received: ' . $data . PHP_EOL;
        });

        // send a 10k message once to fill buffer
        $client->write(str_repeat('1234567890', 10000));
    },
    'var_dump'
);

$loop->run();
