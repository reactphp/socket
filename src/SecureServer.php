<?php

namespace React\Socket;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use React\Socket\Server;
use React\Socket\ConnectionInterface;
use React\Stream\Stream;

/**
 * The `SecureServer` class implements the `ServerInterface` and is responsible
 * for providing a secure TLS (formerly known as SSL) server.
 *
 * It does so by wrapping a `Server` instance which waits for plaintext
 * TCP/IP connections and then performs a TLS handshake for each connection.
 *
 * ```php
 * $server = new Server(8000, $loop);
 * $server = new SecureServer($server, $loop, array(
 *     // tls context options here…
 * ));
 * ```
 *
 * Whenever a client completes the TLS handshake, it will emit a `connection` event
 * with a connection instance implementing [`ConnectionInterface`](#connectioninterface):
 *
 * ```php
 * $server->on('connection', function (ConnectionInterface $connection) {
 *     echo 'Secure connection from' . $connection->getRemoteAddress() . PHP_EOL;
 *
 *     $connection->write('hello there!' . PHP_EOL);
 *     …
 * });
 * ```
 *
 * Whenever a client fails to perform a successful TLS handshake, it will emit an
 * `error` event and then close the underlying TCP/IP connection:
 *
 * ```php
 * $server->on('error', function (Exception $e) {
 *     echo 'Error' . $e->getMessage() . PHP_EOL;
 * });
 * ```
 *
 * See also the `ServerInterface` for more details.
 *
 * Note that the `SecureServer` class is a concrete implementation for TLS sockets.
 * If you want to typehint in your higher-level protocol implementation, you SHOULD
 * use the generic `ServerInterface` instead.
 *
 * @see ServerInterface
 * @see ConnectionInterface
 */
final class SecureServer extends EventEmitter implements ServerInterface
{
    private $tcp;
    private $encryption;
    private $context;

    /**
     * Creates a secure TLS server and starts waiting for incoming connections
     *
     * It does so by wrapping a `Server` instance which waits for plaintext
     * TCP/IP connections and then performs a TLS handshake for each connection.
     * It thus requires valid [TLS context options],
     * which in its most basic form may look something like this if you're using a
     * PEM encoded certificate file:
     *
     * ```php
     * $server = new Server(8000, $loop);
     * $server = new SecureServer($server, $loop, array(
     *     'local_cert' => 'server.pem'
     * ));
     * ```
     *
     * Note that the certificate file will not be loaded on instantiation but when an
     * incoming connection initializes its TLS context.
     * This implies that any invalid certificate file paths or contents will only cause
     * an `error` event at a later time.
     *
     * If your private key is encrypted with a passphrase, you have to specify it
     * like this:
     *
     * ```php
     * $server = new Server(8000, $loop);
     * $server = new SecureServer($server, $loop, array(
     *     'local_cert' => 'server.pem',
     *     'passphrase' => 'secret'
     * ));
     * ```
     *
     * Note that available [TLS context options],
     * their defaults and effects of changing these may vary depending on your system
     * and/or PHP version.
     * Passing unknown context options has no effect.
     *
     * Advanced usage: Despite allowing any `ServerInterface` as first parameter,
     * you SHOULD pass a `Server` instance as first parameter, unless you
     * know what you're doing.
     * Internally, the `SecureServer` has to set the required TLS context options on
     * the underlying stream resources.
     * These resources are not exposed through any of the interfaces defined in this
     * package, but only through the `React\Stream\Stream` class.
     * The `Server` class is guaranteed to emit connections that implement
     * the `ConnectionInterface` and also extend the `Stream` class in order to
     * expose these underlying resources.
     * If you use a custom `ServerInterface` and its `connection` event does not
     * meet this requirement, the `SecureServer` will emit an `error` event and
     * then close the underlying connection.
     *
     * @param ServerInterface|Server $tcp
     * @param LoopInterface $loop
     * @param array $context
     * @see Server
     * @link http://php.net/manual/en/context.ssl.php for TLS context options
     */
    public function __construct(ServerInterface $tcp, LoopInterface $loop, array $context)
    {
        // default to empty passphrase to surpress blocking passphrase prompt
        $context += array(
            'passphrase' => ''
        );

        $this->tcp = $tcp;
        $this->encryption = new StreamEncryption($loop);
        $this->context = $context;

        $that = $this;
        $this->tcp->on('connection', function ($connection) use ($that) {
            $that->handleConnection($connection);
        });
        $this->tcp->on('error', function ($error) use ($that) {
            $that->emit('error', array($error));
        });
    }

    public function getAddress()
    {
        return $this->tcp->getAddress();
    }

    public function close()
    {
        return $this->tcp->close();
    }

    /** @internal */
    public function handleConnection(ConnectionInterface $connection)
    {
        if (!$connection instanceof Stream) {
            $this->emit('error', array(new \UnexpectedValueException('Connection event MUST emit an instance extending Stream in order to access underlying stream resource')));
            $connection->end();
            return;
        }

        foreach ($this->context as $name => $value) {
            stream_context_set_option($connection->stream, 'ssl', $name, $value);
        }

        $that = $this;

        $this->encryption->enable($connection)->then(
            function ($conn) use ($that) {
                $that->emit('connection', array($conn));
            },
            function ($error) use ($that, $connection) {
                $that->emit('error', array($error));
                $connection->end();
            }
        );
    }
}
