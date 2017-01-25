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
        if (is_resource($this->stream)) {
            // http://chat.stackoverflow.com/transcript/message/7727858#7727858
            stream_socket_shutdown($this->stream, STREAM_SHUT_RDWR);
            stream_set_blocking($this->stream, false);
            fclose($this->stream);
        }
    }

    public function getRemoteAddress()
    {
        return $this->parseAddress(@stream_socket_get_name($this->stream, true));
    }

    private function parseAddress($address)
    {
        if ($address === false) {
            return null;
        }

        return trim(substr($address, 0, strrpos($address, ':')), '[]');
    }
}
