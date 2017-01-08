<?php

namespace React\Socket;

use Evenement\EventEmitterInterface;

/**
 * The `ServerInterface` is responsible for providing an interface for accepting
 * incoming streaming connections, such as a normal TCP/IP connection.
 *
 * Most higher-level components (such as a HTTP server) accept an instance
 * implementing this interface to accept incoming streaming connections.
 * This is usually done via dependency injection, so it's fairly simple to actually
 * swap this implementation against any other implementation of this interface.
 * This means that you SHOULD typehint against this interface instead of a concrete
 * implementation of this interface.
 *
 * Besides defining a few methods, this interface also implements the
 * `EventEmitterInterface` which allows you to react to certain events:
 *
 * connection event:
 *     The `connection` event will be emitted whenever a new connection has been
 *     established, i.e. a new client connects to this server socket:
 *
 *     ```php
 *     $server->on('connection', function (ConnectionInterface $connection) {
 *         echo 'new connection' . PHP_EOL;
 *     });
 *     ```
 *
 *     See also the `ConnectionInterface` for more details about handling the
 *     incoming connection.
 *
 * error event:
 *     The `error` event will be emitted whenever there's an error accepting a new
 *     connection from a client.
 *
 *     ```php
 *     $server->on('error', function (Exception $e) {
 *         echo 'error: ' . $e->getMessage() . PHP_EOL;
 *     });
 *     ```
 *
 *     Note that this is not a fatal error event, i.e. the server keeps listening for
 *     new connections even after this event.
 *
 * @see ConnectionInterface
 */
interface ServerInterface extends EventEmitterInterface
{
    /**
     * Returns the port this server is currently listening on
     *
     * This method MUST NOT be called after calling shutdown().
     *
     * @return int the port number
     */
    public function getPort();

    /**
     * Shuts down this listening socket
     *
     * This will stop listening for new incoming connections on this socket.
     *
     * This method MUST NOT be called more than once on the same instance.
     *
     * @return void
     */
    public function shutdown();
}
