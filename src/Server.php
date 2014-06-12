<?php

namespace React\Socket;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;

/** @event connection(Connection $client) */
/** @event error(RuntimeException $error) */
class Server extends EventEmitter implements ServerInterface
{
    protected $address;
    public $master;
    protected $loop;

    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;
    }

    public function listen($address)
    {
        $this->address = $address;
        $this->master = @stream_socket_server($address, $errno, $errstr);

        if (false === $this->master) {
            $message = "Could not bind to {$address}: {$errstr}";
            throw new ConnectionException($message, $errno);
        }

        stream_set_blocking($this->master, 0);

        $this->loop->addReadStream($this->master, function ($master) {
            $newSocket = stream_socket_accept($master);
            if (false === $newSocket) {
                $this->emit('error', array(new \RuntimeException('Error accepting new connection')));

                return;
            }
            $this->handleConnection($newSocket);
        });
    }

    public function handleConnection($socket)
    {
        stream_set_blocking($socket, 0);

        $client = $this->createConnection($socket);

        $this->emit('connection', array($client));
    }

    public function getAddress()
    {
        return $this->address;
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
