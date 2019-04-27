<?php

namespace React\Socket;

/**
 * The `SecureConnectorInterface` is responsible for establishing secure connections.
 * It defines methods to enable and disable TLS onto a given connection.
 *
 * As it extends the `ConnectorInterface`, it provides all
 * other features of a Connector as well.
 *
 * @see ConnectorInterface
 */
interface SecureConnectorInterface extends ConnectorInterface
{
    /**
     * Enables TLS on the given connection.
     * @param ExtConnectionInterface $connection
     * @return \React\Promise\PromiseInterface
     */
    public function enableTLS(ExtConnectionInterface $connection);

    /**
     * Disables TLS on the given connection.
     *
     * Current PHP versions [update me!] report
     * a successful TLS downgrade handshake as failure. As such
     * this method MAY return false even though the TLS downgrade
     * handshake was successful, however we do not have a way
     * to guarantee that the handshake was successful.
     *
     * @param ExtConnectionInterface $connection
     * @return \React\Promise\PromiseInterface
     */
    public function disableTLS(ExtConnectionInterface $connection);
}
