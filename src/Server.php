<?php

namespace React\Socket;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;

/** Emits the connection event */
class Server extends EventEmitter implements ServerInterface
{
    public $master;
    private $loop;

    /**
     * @var ConnectionFactoryInterface
     */
    protected $factory;

    /**
     * Server constructor.
     * @param LoopInterface $loop
     * @param ConnectionFactoryInterface|null $factory The factory to use for creating connections.
     * Uses a default factory if none is passed for backwards compatibility.
     */
    public function __construct(LoopInterface $loop, ConnectionFactoryInterface $factory = null)
    {
        $this->loop = $loop;
        $this->factory = $factory ?: new ConnectionFactory();
    }

    /**
     * @inheritdoc
     */
    public function listen($port, $host = '127.0.0.1')
    {
        if (strpos($host, ':') !== false) {
            // enclose IPv6 addresses in square brackets before appending port
            $host = '[' . $host . ']';
        }

        $this->master = @stream_socket_server("tcp://$host:$port", $errno, $errstr);
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

    /**
     * Handles a new connection by configuring the socket,
     * constructing the connection object and emitting the connection event.
     * @todo Check if this should be protected instead of public.
     * @param resource $socket
     */
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

    /**
     * Constructs a connection object given a socket.
     * @todo Check if this should be protected instead of public.
     * @param resource $socket The PHP stream resource.
     * @return ConnectionInterface
     */
    public function createConnection($socket)
    {
        return $this->factory->createConnection($socket, $this->loop);
    }
}
