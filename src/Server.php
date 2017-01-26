<?php

namespace React\Socket;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;

/**
 * The `Server` class implements the `ServerInterface` and
 * is responsible for accepting plaintext TCP/IP connections.
 *
 * Whenever a client connects, it will emit a `connection` event with a connection
 * instance implementing `ConnectionInterface`:
 *
 * ```php
 * $server->on('connection', function (ConnectionInterface $connection) {
 *     echo 'Plaintext connection from ' . $connection->getRemoteAddress() . PHP_EOL;
 *     $connection->write('hello there!' . PHP_EOL);
 *     …
 * });
 * ```
 *
 * See also the `ServerInterface` for more details.
 *
 * Note that the `Server` class is a concrete implementation for TCP/IP sockets.
 * If you want to typehint in your higher-level protocol implementation, you SHOULD
 * use the generic `ServerInterface` instead.
 *
 * @see ServerInterface
 * @see ConnectionInterface
 */
class Server extends EventEmitter implements ServerInterface
{
    public $master;
    private $loop;
    private $context;

    /**
     * Creates a plaintext TCP/IP server.
     *
     * ```php
     * $server = new Server($loop);
     *
     * $server->listen(8080);
     * ```
     *
     * Optionally, you can specify [socket context options](http://php.net/manual/en/context.socket.php)
     * for the underlying stream socket resource like this:
     *
     * ```php
     * $server = new Server($loop, array(
     *     'backlog' => 200,
     *     'so_reuseport' => true,
     *     'ipv6_v6only' => true
     * ));
     *
     * $server->listen(8080, '::1');
     * ```
     *
     * Note that available [socket context options](http://php.net/manual/en/context.socket.php),
     * their defaults and effects of changing these may vary depending on your system
     * and/or PHP version.
     * Passing unknown context options has no effect.
     *
     * @param LoopInterface $loop
     * @param array $context
     */
    public function __construct(LoopInterface $loop, array $context = array())
    {
        $this->loop = $loop;
        $this->context = $context;
    }

    public function listen($port, $host = '127.0.0.1')
    {
        if (strpos($host, ':') !== false) {
            // enclose IPv6 addresses in square brackets before appending port
            $host = '[' . $host . ']';
        }

        $this->master = @stream_socket_server(
            "tcp://$host:$port",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            stream_context_create(array('socket' => $this->context))
        );
        if (false === $this->master) {
            $message = "Could not bind to tcp://$host:$port: $errstr";
            throw new ConnectionException($message, $errno);
        }
        stream_set_blocking($this->master, 0);

        $that = $this;

        $this->loop->addReadStream($this->master, function ($master) use ($that) {
            $newSocket = @stream_socket_accept($master);
            if (false === $newSocket) {
                $that->emit('error', array(new \RuntimeException('Error accepting new connection')));

                return;
            }
            $that->handleConnection($newSocket);
        });
    }

    public function handleConnection($socket)
    {
        stream_set_blocking($socket, 0);

        $client = $this->createConnection($socket);

        $this->emit('connection', array($client));
    }

    public function getPort()
    {
        $name = stream_socket_get_name($this->master, false);

        return (int) substr(strrchr($name, ':'), 1);
    }

    public function shutdown()
    {
        $this->loop->removeStream($this->master);
        fclose($this->master);
        $this->removeAllListeners();
    }

    public function createConnection($socket)
    {
        return new Connection($socket, $this->loop);
    }
}
