<?php

namespace React\Socket;

/**
 * This is an extended connection interface,
 * which allows to expose the underlying stream
 * in a non-BC way. This interface will be removed
 * in the next major release and merge into `ConnectionInterface`.
 *
 * However it is not exposed for arbitrary reasons.
 * Most notably it is exposed to enable TLS.
 */
interface ExtConnectionInterface extends ConnectionInterface
{
    /**
     * Returns the underlying stream.
     * @return resource
     */
    public function getStream();

    /**
     * Sets the internal flag to specify whether
     * TLS was enabled or not.
     *
     * This is used to return `tls://` or `tcp://` as scheme.
     * @param bool $flag
     * @return void
     */
    public function setTLSEnabledFlag($flag);
}
