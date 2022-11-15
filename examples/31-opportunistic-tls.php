<?php

// Opportunistic TLS example showing a basic negotiation before enabling the encryption. It starts out as an
// unencrypted TCP connection. After both parties agreed to encrypt the connection they both enable the encryption.
// After which any communication over the line is encrypted.
//
// This example is design to show both sides in one go, as such the server stops listening for new connection after
// the first, this makes sure the loop shuts down after the example connection has closed.
//
// $ php examples/31-opportunistic-tls.php

use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Socket\OpportunisticTlsConnectionInterface;
use React\Socket\SocketServer;

require __DIR__ . '/../vendor/autoload.php';

$server = new SocketServer('opportunistic+tls://127.0.0.1:0', array(
    'tls' => array(
        'local_cert' => __DIR__ . '/localhost.pem',
    )
));
$server->on('connection', static function (OpportunisticTlsConnectionInterface $connection) use ($server) {
    $server->close();

    $connection->on('data', function ($data) {
        echo 'From Client: ', $data, PHP_EOL;
    });
    React\Promise\Stream\first($connection)->then(function ($data) use ($connection) {
        if ($data === 'Let\'s encrypt?') {
            $connection->write('yes');
            return $connection->enableEncryption();
        }

        return $connection;
    })->then(static function (ConnectionInterface $connection) {
        $connection->write('Encryption enabled!');
    })->done();
});

$client = new Connector(array(
    'tls' => array(
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true,
    ),
));
$client->connect($server->getAddress())->then(static function (OpportunisticTlsConnectionInterface $connection) {
    $connection->on('data', function ($data) {
        echo 'From Server: ', $data, PHP_EOL;
    });
    $connection->write('Let\'s encrypt?');

    return React\Promise\Stream\first($connection)->then(function ($data) use ($connection) {
        if ($data === 'yes') {
            return $connection->enableEncryption();
        }

        return $connection;
    });
})->then(function (ConnectionInterface $connection) {
    $connection->write('Encryption enabled!');
    Loop::addTimer(1, static function () use ($connection) {
        $connection->end('Cool! Bye!');
    });
})->done();
