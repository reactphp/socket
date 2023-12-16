<?php

namespace React\Socket;

use React\Promise\PromiseInterface;

/**
 * The `OpportunisticTlsConnectionInterface` extends the
 * [`ConnectionInterface`](#connectioninterface) and adds the ability of
 * enabling the TLS encryption on the connection when desired.
 *
 * @see DuplexStreamInterface
 * @see ServerInterface
 * @see ConnectionInterface
 */
interface OpportunisticTlsConnectionInterface extends ConnectionInterface
{
    /**
     * When negotiated with the server when to start encrypting traffic using TLS, you
     * can enable it by calling `enableEncryption()`. This will either return a promise
     * that resolves with a `OpportunisticTlsConnectionInterface` connection or throw a
     * `RuntimeException` if the encryption failed. If successful, all traffic back and
     * forth will be encrypted. In the following example we ask the server if they want
     * to encrypt the connection, and when it responds with `yes` we enable the encryption:
     *
     * ```php
     * $connector = new React\Socket\Connector();
     * $connector->connect('opportunistic+tls://example.com:5432/')->then(function (React\Socket\OpportunisticTlsConnectionInterface $startTlsConnection) {
     * $connection->write('let\'s encrypt?');
     *
     * return React\Promise\Stream\first($connection)->then(function ($data) use ($connection) {
     *     if ($data === 'yes') {
     *         return $connection->enableEncryption();
     *     }
     *
     *     return $stream;
     * });
     * })->then(function (React\Socket\ConnectionInterface $connection) {
     *     $connection->write('Hello!');
     * });
     * ```
     *
     * The `enableEncryption` function resolves with itself. As such you can't see the data
     * encrypted when you hook into the events before enabling, as shown below:
     *
     * ```php
     * $connector = new React\Socket\Connector();
     * $connector->connect('opportunistic+tls://example.com:5432/')->then(function (React\Socket\OpportunisticTlsConnectionInterface $startTlsConnection) {
     *     $connection->on('data', function ($data) {
     *         echo 'Raw: ', $data, PHP_EOL;
     *     });
     *
     *     return $connection->enableEncryption();
     * })->then(function (React\Socket\ConnectionInterface $connection) {
     *     $connection->on('data', function ($data) {
     *         echo 'TLS: ', $data, PHP_EOL;
     *     });
     * });
     * ```
     *
     * When the other side sends `Hello World!` over the encrypted connection, the output
     * will be the following:
     *
     * ```
     * Raw: Hello World!
     * TLS: Hello World!
     * ```
     *
     * @return PromiseInterface<OpportunisticTlsConnectionInterface>
     */
    public function enableEncryption();
}
