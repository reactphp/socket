<?php

namespace React\Socket;

use React\Stream\Stream;
use React\EventLoop\LoopInterface;

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
    /**
     * Internal flag whether encryption has been enabled on this connection
     *
     * Mostly used by internal StreamEncryption so that connection returns
     * `tls://` scheme for encrypted connections instead of `tcp://`.
     *
     * @internal
     */
    public $encryptionEnabled = false;

    public function __construct($stream, LoopInterface $loop)
    {
        parent::__construct($stream, $loop);

        // PHP < 5.6.8 suffers from a buffer indicator bug on secure TLS connections
        // as a work-around we always read the complete buffer until its end.
        // The buffer size is limited due to TCP/IP buffers anyway, so this
        // should not affect usage otherwise.
        // See https://bugs.php.net/bug.php?id=65137
        // https://bugs.php.net/bug.php?id=41631
        // https://github.com/reactphp/socket-client/issues/24
        if (version_compare(PHP_VERSION, '5.6.8', '<')) {
            $this->bufferSize = null;
        }
    }

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
        if ($address === false) {
            return null;
        }

        // check if this is an IPv6 address which includes multiple colons but no square brackets
        $pos = strrpos($address, ':');
        if ($pos !== false && strpos($address, ':') < $pos && substr($address, 0, 1) !== '[') {
            $port = substr($address, $pos + 1);
            $address = '[' . substr($address, 0, $pos) . ']:' . $port;
        }

        return ($this->encryptionEnabled ? 'tls' : 'tcp') . '://' . $address;
    }
}
