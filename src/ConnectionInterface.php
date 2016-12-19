<?php

namespace React\Socket;

use React\Stream\DuplexStreamInterface;

/**
 * Any incoming connection is represented by this interface.
 *
 * An incoming connection is a duplex stream (both readable and writable) that
 * implements React's DuplexStreamInterface and contains only a single
 * additional property, the remote address (client IP) where this connection has
 * been established from.
 *
 * Note that this interface is only to be used to represent the server-side end
 * of an incoming connection.
 * It MUST NOT be used to represent an outgoing connection in a client-side
 * context.
 * If you want to establish an outgoing connection,
 * use React's SocketClient component instead.
 *
 * Because the `ConnectionInterface` implements the underlying
 * `DuplexStreamInterface` you can use any of its events and methods as usual.
 *
 * @see DuplexStreamInterface
 */
interface ConnectionInterface extends DuplexStreamInterface
{
    /**
     * Returns the remote address (client IP) where this connection has been established from
     *
     * @return string|null remote address (client IP) or null if unknown
     */
    public function getRemoteAddress();
}
