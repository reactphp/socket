<?php

namespace React\Socket;

use React\Stream\DuplexStreamInterface;

/**
 * Any outgoing connection is represented by this interface,
 * such as a normal TCP/IP connection.
 *
 * An outgoing connection is a duplex stream (both readable and writable) that
 * implements React's
 * [`DuplexStreamInterface`](https://github.com/reactphp/stream#duplexstreaminterface).
 * It contains additional properties for the local and remote address
 * where this connection has been established to.
 *
 * Most commonly, instances implementing this `ConnectionInterface` are returned
 * by all classes implementing the [`ConnectorInterface`](#connectorinterface).
 *
 * > Note that this interface is only to be used to represent the client-side end
 * of an outgoing connection.
 * It MUST NOT be used to represent an incoming connection in a server-side context.
 * If you want to accept incoming connections,
 * use the [`Socket`](https://github.com/reactphp/socket) component instead.
 *
 * Because the `ConnectionInterface` implements the underlying
 * [`DuplexStreamInterface`](https://github.com/reactphp/stream#duplexstreaminterface)
 * you can use any of its events and methods as usual:
 *
 * ```php
 * $connection->on('data', function ($chunk) {
 *     echo $chunk;
 * });
 *
 * $connection->on('end', function () {
 *     echo 'ended';
 * });
 *
 * $connection->on('error', function (Exception $e) {
 *     echo 'error: ' . $e->getMessage();
 * });
 *
 * $connection->on('close', function () {
 *     echo 'closed';
 * });
 *
 * $connection->write($data);
 * $connection->end($data = null);
 * $connection->close();
 * // …
 * ```
 *
 * For more details, see the
 * [`DuplexStreamInterface`](https://github.com/reactphp/stream#duplexstreaminterface).
 *
 * @see DuplexStreamInterface
 * @see ConnectorInterface
 */
interface ConnectionInterface extends DuplexStreamInterface
{
    /**
     * Returns the remote address (IP and port) where this connection has been established to
     *
     * ```php
     * $address = $connection->getRemoteAddress();
     * echo 'Connected to ' . $address . PHP_EOL;
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
     * echo 'Connected to ' . $ip . PHP_EOL;
     * ```
     *
     * @return ?string remote address (IP and port) or null if unknown
     */
    public function getRemoteAddress();

    /**
     * Returns the full local address (IP and port) where this connection has been established from
     *
     * ```php
     * $address = $connection->getLocalAddress();
     * echo 'Connected via ' . $address . PHP_EOL;
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
     * If your system has multiple interfaces (e.g. a WAN and a LAN interface),
     * you can use this method to find out which interface was actually
     * used for this connection.
     *
     * @return ?string local address (IP and port) or null if unknown
     * @see self::getRemoteAddress()
     */
    public function getLocalAddress();
}
