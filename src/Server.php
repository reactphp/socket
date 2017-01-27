<?php

namespace React\Socket;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use InvalidArgumentException;

/**
 * The `Server` class implements the `ServerInterface` and
 * is responsible for accepting plaintext TCP/IP connections.
 *
 * ```php
 * $server = new Server(8080, $loop);
 * ```
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

    /**
     * Creates a plaintext TCP/IP socket server and starts listening on the given address
     *
     * This starts accepting new incoming connections on the given address.
     * See also the `connection event` documented in the `ServerInterface`
     * for more details.
     *
     * ```php
     * $server = new Server(8080, $loop);
     * ```
     *
     * As above, the `$uri` parameter can consist of only a port, in which case the
     * server will default to listening on the localhost address `127.0.0.1` and thus
     * it will not be reachable from outside of this system.
     *
     * In order to use a random port assignment, you can use the port `0`:
     *
     * ```php
     * $server = new Server(0, $loop);
     * $port = $server->getPort();
     * ```
     *
     * In order to change the host the socket is listening on, you can provide an IP
     * addres through the first parameter provided to the constructor, optionally
     * preceded by the `tcp://` scheme:
     *
     * ```php
     * $server = new Server('192.168.0.1:8080', $loop);
     * ```
     *
     * If you want to listen on an IPv6 address, you MUST enclose the host in square
     * brackets:
     *
     * ```php
     * $server = new Server('[::1]:8080', $loop);
     * ```
     *
     * If the given URI is invalid, does not contain a port, any other scheme or if it
     * contains a hostname, it will throw an `InvalidArgumentException`:
     *
     * ```php
     * // throws InvalidArgumentException due to missing port
     * $server = new Server('127.0.0.1', $loop);
     * ```
     *
     * Optionally, you can specify [socket context options](http://php.net/manual/en/context.socket.php)
     * for the underlying stream socket resource like this:
     *
     * ```php
     * $server = new Server('[::1]:8080', $loop, array(
     *     'backlog' => 200,
     *     'so_reuseport' => true,
     *     'ipv6_v6only' => true
     * ));
     * ```
     *
     * Note that available [socket context options](http://php.net/manual/en/context.socket.php),
     * their defaults and effects of changing these may vary depending on your system
     * and/or PHP version.
     * Passing unknown context options has no effect.
     *
     * @param string        $uri
     * @param LoopInterface $loop
     * @param array         $context
     * @throws InvalidArgumentException if the listening address is invalid
     * @throws ConnectionException if listening on this address fails (already in use etc.)
     */
    public function __construct($uri, LoopInterface $loop, array $context = array())
    {
        $this->loop = $loop;

        // a single port has been given => assume localhost
        if ((string)(int)$uri === (string)$uri) {
            $uri = '127.0.0.1:' . $uri;
        }

        // assume default scheme if none has been given
        if (strpos($uri, '://') === false) {
            $uri = 'tcp://' . $uri;
        }

        // parse_url() does not accept null ports (random port assignment) => manually remove
        if (substr($uri, -2) === ':0') {
            $parts = parse_url(substr($uri, 0, -2));
            if ($parts) {
                $parts['port'] = 0;
            }
        } else {
            $parts = parse_url($uri);
        }

        // ensure URI contains TCP scheme, host and port
        if (!$parts || !isset($parts['scheme'], $parts['host'], $parts['port']) || $parts['scheme'] !== 'tcp') {
            throw new InvalidArgumentException('Invalid URI "' . $uri . '" given');
        }

        if (false === filter_var(trim($parts['host'], '[]'), FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException('Given URI "' . $uri . '" does not contain a valid host IP');
        }

        $this->master = @stream_socket_server(
            $uri,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            stream_context_create(array('socket' => $context))
        );
        if (false === $this->master) {
            $message = "Could not bind to $uri: $errstr";
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
        if (!is_resource($this->master)) {
            return null;
        }

        $name = stream_socket_get_name($this->master, false);

        return (int) substr(strrchr($name, ':'), 1);
    }

    public function close()
    {
        if (!is_resource($this->master)) {
            return;
        }

        $this->loop->removeStream($this->master);
        fclose($this->master);
        $this->removeAllListeners();
    }

    public function createConnection($socket)
    {
        return new Connection($socket, $this->loop);
    }
}
