<?php

namespace React\Socket;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use React\Socket\Server;
use React\Socket\ConnectionInterface;

/**
 * The `SecureServer` class implements the `ServerInterface` and is responsible
 * for providing a secure TLS (formerly known as SSL) server.
 *
 * It does so by wrapping a `Server` instance which waits for plaintext
 * TCP/IP connections and then performs a TLS handshake for each connection.
 * It thus requires valid [TLS context options],
 * which in its most basic form may look something like this if you're using a
 * PEM encoded certificate file:
 *
 * ```
 * $context = array(
 *     'local_cert' => __DIR__ . '/localhost.pem'
 * );
 * ```
 *
 * If your private key is encrypted with a passphrase, you have to specify it
 * like this:
 *
 * ```php
 * $context = array(
 *     'local_cert' => 'server.pem',
 *     'passphrase' => 'secret'
 * );
 * ```
 *
 * @see Server
 * @link http://php.net/manual/en/context.ssl.php for TLS context options
 */
class SecureServer extends EventEmitter implements ServerInterface
{
    private $tcp;
    private $context;
    private $loop;
    private $encryption;

    public function __construct(Server $tcp, LoopInterface $loop, array $context)
    {
        // default to empty passphrase to surpress blocking passphrase prompt
        $context += array(
            'passphrase' => ''
        );

        $this->tcp = $tcp;
        $this->context = $context;
        $this->loop = $loop;
        $this->encryption = new StreamEncryption($loop);

        $that = $this;
        $this->tcp->on('connection', function ($connection) use ($that) {
            $that->handleConnection($connection);
        });
        $this->tcp->on('error', function ($error) use ($that) {
            $that->emit('error', array($error));
        });
    }

    public function listen($port, $host = '127.0.0.1')
    {
        $this->tcp->listen($port, $host);

        foreach ($this->context as $name => $value) {
            stream_context_set_option($this->tcp->master, 'ssl', $name, $value);
        }
    }

    public function getPort()
    {
        return $this->tcp->getPort();
    }

    public function shutdown()
    {
        return $this->tcp->shutdown();
    }

    /** @internal */
    public function handleConnection(ConnectionInterface $connection)
    {
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
