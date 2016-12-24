<?php

namespace React\SocketClient;

/**
 * The `ConnectorInterface` is responsible for providing an interface for
 * establishing streaming connections, such as a normal TCP/IP connection.
 *
 * This is the main interface defined in this package and it is used throughout
 * React's vast ecosystem.
 *
 * Most higher-level components (such as HTTP, database or other networking
 * service clients) accept an instance implementing this interface to create their
 * TCP/IP connection to the underlying networking service.
 * This is usually done via dependency injection, so it's fairly simple to actually
 * swap this implementation against any other implementation of this interface.
 *
 * The interface only offers a single `create()` method.
 */
interface ConnectorInterface
{
    /**
     * Creates a Promise which resolves with a stream once the connection to the given remote address succeeds
     *
     * The Promise resolves with a `React\Stream\Stream` instance on success or
     * rejects with an `Exception` if the connection is not successful.
     *
     * The returned Promise SHOULD be implemented in such a way that it can be
     * cancelled when it is still pending. Cancelling a pending promise SHOULD
     * reject its value with an Exception. It SHOULD clean up any underlying
     * resources and references as applicable.
     *
     * @param string $host
     * @param int    $port
     * @return React\Promise\PromiseInterface resolves with a Stream on success or rejects with an Exception on error
     */
    public function create($host, $port);
}
