<?php

namespace React\Socket;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * The `UnixServer` class implements the `ServerInterface` and
 * is responsible for accepting plaintext connections on unix domain sockets.
 *
 * ```php
 * $server = new UnixServer('unix:///tmp/app.sock', $loop);
 * ```
 *
 * See also the `ServerInterface` for more details.
 *
 * @see ServerInterface
 * @see ConnectionInterface
 */
final class UnixServer extends EventEmitter implements ServerInterface
{
    private $master;
    private $loop;
    private $listening = false;

    /**
     * Creates a plaintext socket server and starts listening on the given unix socket
     *
     * This starts accepting new incoming connections on the given address.
     * See also the `connection event` documented in the `ServerInterface`
     * for more details.
     *
     * ```php
     * $server = new UnixServer('unix:///tmp/app.sock', $loop);
     * ```
     *
     * @param string        $path
     * @param LoopInterface $loop
     * @param array         $context
     * @throws InvalidArgumentException if the listening address is invalid
     * @throws RuntimeException if listening on this address fails (already in use etc.)
     */
    public function __construct($path, LoopInterface $loop, array $context = array())
    {
        $this->loop = $loop;

        if (strpos($path, '://') === false) {
            $path = 'unix://' . $path;
        } elseif (substr($path, 0, 7) !== 'unix://') {
            throw new InvalidArgumentException('Given URI "' . $path . '" is invalid');
        }

        $this->master = @stream_socket_server(
            $path,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            stream_context_create(array('socket' => $context))
        );
        if (false === $this->master) {
            throw new RuntimeException('Failed to listen on unix domain socket "' . $path . '": ' . $errstr, $errno);
        }
        stream_set_blocking($this->master, 0);

        $this->resume();
    }

    public function getAddress()
    {
        if (!is_resource($this->master)) {
            return null;
        }

        return 'unix://' . stream_socket_get_name($this->master, false);
    }

    public function pause()
    {
        if (!$this->listening) {
            return;
        }

        $this->loop->removeReadStream($this->master);
        $this->listening = false;
    }

    public function resume()
    {
        if ($this->listening || !is_resource($this->master)) {
            return;
        }

        $that = $this;
        $this->loop->addReadStream($this->master, function ($master) use ($that) {
            $newSocket = @stream_socket_accept($master);
            if (false === $newSocket) {
                $that->emit('error', array(new RuntimeException('Error accepting new connection')));

                return;
            }
            $that->handleConnection($newSocket);
        });
        $this->listening = true;
    }

    public function close()
    {
        if (!is_resource($this->master)) {
            return;
        }

        $this->pause();
        fclose($this->master);
        $this->removeAllListeners();
    }

    /** @internal */
    public function handleConnection($socket)
    {
        $connection = new UnixConnection($socket, $this->loop);

        $this->emit('connection', array(
            $connection
        ));
    }
}
