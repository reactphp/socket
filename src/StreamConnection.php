<?php

namespace React\Socket;

use React\Stream\Stream;
use React\Socket\ConnectionInterface;

/**
 * @internal should not be relied upon, see ConnectionInterface instead
 * @see ConnectionInterface
 */
class StreamConnection extends Stream implements ConnectionInterface
{
    public function getRemoteAddress()
    {
        return $this->sanitizeAddress(@stream_socket_get_name($this->stream, true));
    }

    public function getLocalAddress()
    {
        return $this->sanitizeAddress(@stream_socket_get_name($this->stream, false));
    }

    private function sanitizeAddress($address)
    {
        if ($address === false) {
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
