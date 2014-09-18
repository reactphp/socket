<?php

namespace React\Socket;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;

/** @event connection */
class Server extends EventEmitter implements ServerInterface
{
    public $master;
    private $loop;
    private $options = array();

    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;
    }

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

        // apply any socket options on the connection!
        foreach ($this->options as $socket_level => $options) {
            foreach($options as $option_name => $option_value) {
                socket_set_option($socket, $option_level, $option_name, $option_value);
            }
        }

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

    public function setOption($option_level, $option_name, $option_value)
    {
        if (!is_array($this->options[$option_level]))
            $this->options[$option_level] = array();

        $this->options[$option_level][$option_name] = $option_value;

        return true;
    }

    public function setSocketOption($option_name, $option_value)
    {
        return $this->setOption(SOL_SOCKET, $option_name, $option_value);
    }

    public function setTCPOption($option_name, $option_value)
    {
        return $this->setOption(SOL_TCP, $option_name, $option_value);
    }
}
