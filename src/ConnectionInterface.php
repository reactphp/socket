<?php

namespace React\Socket;

use React\Stream\DuplexStreamInterface;

/**
 * Any incoming connection is represented by this interface.
 *
 * An incoming connection is a duplex stream (both readable and writable) that
 * implements React's DuplexStreamInterface.
 * It contains additional properties for the local and remote address (client IP)
 * where this connection has been established from.
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
     * Returns the remote address (client IP and port) where this connection has been established from
     *
     * ```php
     * $address = $connection->getRemoteAddress();
     * echo 'Connection from ' . $address . PHP_EOL;
     * ```
     *
     * If the remote address can not be determined or is unknown at this time (such as
     * after the connection has been closed), it MAY return a `NULL` value instead.
     *
     * Otherwise, it will return the full remote address as a string value.
     * If this is a TCP/IP based connection and you only want the remote IP, you may
     * use something like this:
     *
     * ```php
     * $address = $connection->getRemoteAddress();
     * $ip = trim(parse_url('tcp://' . $address, PHP_URL_HOST), '[]');
     * echo 'Connection from ' . $ip . PHP_EOL;
     * ```
     *
     * @return ?string remote address (client IP and port) or null if unknown
     */
    public function getRemoteAddress();

    /**
     * Returns the full local address (client IP and port) where this connection has been established to
     *
     * ```php
     * $address = $connection->getLocalAddress();
     * echo 'Connection to ' . $address . PHP_EOL;
     * ```
     *
     * If the local address can not be determined or is unknown at this time (such as
     * after the connection has been closed), it MAY return a `NULL` value instead.
     *
     * Otherwise, it will return the full local address as a string value.
     *
     * This method complements the [`getRemoteAddress()`](#getremoteaddress) method,
     * so they should not be confused.
     *
     * If your `Server` instance is listening on multiple interfaces (e.g. using
     * the address `0.0.0.0`), you can use this method to find out which interface
     * actually accepted this connection (such as a public or local interface).
     *
     * @return ?string local address (client IP and port) or null if unknown
     * @see self::getRemoteAddress()
     */
    public function getLocalAddress();
}
