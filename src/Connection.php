<?php

namespace React\Socket;

use React\Stream\Stream;

/**
 * The actual connection implementation for ConnectionInterface
 *
 * This class should only be used internally, see ConnectionInterface instead.
 *
 * @see ConnectionInterface
 * @internal
 */
class Connection extends Stream implements ConnectionInterface
{
    public function handleClose()
    {
        if (!is_resource($this->stream)) {
            return;
        }

        // Try to cleanly shut down socket and ignore any errors in case other
        // side already closed. Shutting down may return to blocking mode on
        // some legacy versions, so reset to non-blocking just in case before
        // continuing to close the socket resource.
        @stream_socket_shutdown($this->stream, STREAM_SHUT_RDWR);
        stream_set_blocking($this->stream, false);
        fclose($this->stream);
    }

    public function getRemoteAddress()
    {
        return $this->parseAddress(@stream_socket_get_name($this->stream, true));
    }

    public function getLocalAddress()
    {
        return $this->parseAddress(@stream_socket_get_name($this->stream, false));
    }

    private function parseAddress($address)
    {
        // work around https://bugs.php.net/bug.php?id=74458 by checking if  stream_socket_get_name has returned null string instead of address
        if ($address === false || $address === "\0") {
            return null;
        }

        // check if this is an IPv6 address which includes multiple colons but no square brackets
        $pos = strrpos($address, ':');
        if ($pos !== false && strpos($address, ':') < $pos && substr($address, 0, 1) !== '[') {
            $port = substr($address, $pos + 1);
            $address = '[' . substr($address, 0, $pos) . ']:' . $port;
        }

        return $address;
    }
}
