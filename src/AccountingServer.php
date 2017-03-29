<?php

namespace React\Socket;

use Evenement\EventEmitter;

/**
 * The `AccountingServer` decorators wraps a given `ServerInterface` and is responsible
 * for keeping track of open connections to this server instance.
 *
 * Whenever the underlying server emits a `connection` event, it will keep track
 * of this connection by adding it to the list of open connections and then
 * forward the `connection` event (unless its limits are exceeded).
 *
 * Whenever a connection closes, it will remove this connection from the list of
 * open connections.
 *
 * ```php
 * $server = new AccountingServer($server);
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
class AccountingServer extends EventEmitter implements ServerInterface
{
    private $connections = array();
    private $server;
    private $limit;

    /**
     * Instantiates a new AccountingServer.
     *
     * You can optionally pass a maximum number of open connections to ensure
     * the server will automatically reject (close) connections once this limit
     * is exceeded. In this case, it will emit an `error` event to inform about
     * this and no `connection` event will be emitted.
     *
     * @param ServerInterface $server
     * @param null|int        $connectionLimit
     */
    public function __construct(ServerInterface $server, $connectionLimit = null)
    {
        $this->server = $server;
        $this->limit = $connectionLimit;

        $this->server->on('connection', array($this, 'handleConnection'));
        $this->server->on('error', array($this, 'handleError'));
    }

    /**
     * Returns an array with all currently active connections
     *
     * ```php
     * foreach ($server->getConnection() as $connection) {
     *     $connection->write('Hi!');
     * }
     * ```
     *
     * @return ConnectionInterface[]
     */
    public function getConnections()
    {
        return $this->connections;
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
        // close connection is limit exceeded
        if ($this->limit !== null && count($this->connections) >= $this->limit) {
            $this->handleError(new \OverflowException('Connection closed because server reached connection limit'));
            $connection->close();
            return;
        }

        $this->connections[] = $connection;
        $that = $this;
        $connection->on('close', function () use ($that, $connection) {
            $that->handleDisconnection($connection);
        });

        $this->emit('connection', array($connection));
    }

    /** @internal */
    public function handleDisconnection(ConnectionInterface $connection)
    {
        unset($this->connections[array_search($connection, $this->connections)]);
    }

    /** @internal */
    public function handleError(\Exception $error)
    {
        $this->emit('error', array($error));
    }
}
