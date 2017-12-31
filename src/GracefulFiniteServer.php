<?php

namespace React\Socket;

use Evenement\EventEmitter;

/**
 * The `GracefulFiniteServer` decorator wraps a given `ServerInterface` and is
 * responsible to die in a graceful way when an specific file exists.
 *
 * Before dying, this file is properly removed.
 *
 * ```php
 * $server = new GracefulFiniteServer($server, '/tmp/server.txt);
 * $server->on('connection', function (ConnectionInterface $connection) {
 *     $connection->write('hello there!' . PHP_EOL);
 *     …
 * });
 * ```
 *
 * See also the `ServerInterface` for more details.
 *
 * @see ServerInterface
 * @see ConnectionInterface
 */
class GracefulFiniteServer extends EventEmitter implements ServerInterface
{
    private $server;
    private $file;

    /**
     * @param ServerInterface $server
     * @param string $file
     */
    public function __construct(ServerInterface $server, $file)
    {
        $this->file = $file;
        $this->server = $server;
        $this->server->on('connection', array($this, 'handleConnection'));
        $this->server->on('error', array($this, 'handleError'));
    }

    public function getAddress()
    {
        return $this->server->getAddress();
    }

    public function pause()
    {
        $this->server->pause();
    }

    public function resume()
    {
        $this->server->resume();
    }

    public function close()
    {
        $this->server->close();
    }

    /** @internal */
    public function handleConnection(ConnectionInterface $connection)
    {
        if (
            file_exists($this->file) &&
            is_writable($this->file)
        ) {
            unlink($this->file);
            $connection->on('close', function() {
                $this->server->close();
            });
        }

        $this->emit('connection', array($connection));
    }

    /** @internal */
    public function handleError(\Exception $error)
    {
        $this->emit('error', array($error));
    }
}
