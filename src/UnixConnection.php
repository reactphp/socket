<?php

namespace React\Socket;

/**
 * A UnixConnection is a specific implementation of a Connection for Unix connections.
 *
 * Unix connections have the ability to retrieve the PID of the local and remote side of the socket.
 *
 * @see Connection
 */
class UnixConnection extends Connection
{
    /**
     * @see http://php.net/manual/en/function.get-resource-type.php
     */
    const RESOURCE_TYPE_STREAM = 'stream';

    /**
     * PHP has no built in constant for retrieving the credentials of the peer process.
     *
     * @see http://php.net/manual/en/sockets.constants.php
     * @see http://php.net/manual/en/function.socket-get-option.php#101380
     */
    const SO_PEERCRED = 17;

    /**
     * @var int|null Variable to cache the remote pid.
     */
    private $remote_pid;

    /**
     * @var int|null Variable to cache the local pid.
     */
    private $local_pid;

    protected function parseAddress($address)
    {
        if ($address === false) {
            return null;
        }

        // remove trailing colon from address for HHVM < 3.19: https://3v4l.org/5C1lo
        // note that technically ":" is a valid address, so keep this in place otherwise
        if (substr($address, -1) === ':' && defined('HHVM_VERSION_ID') && HHVM_VERSION_ID < 31900) {
            $address = (string)substr($address, 0, -1);
        }

        // work around unknown addresses should return null value: https://3v4l.org/5C1lo and https://bugs.php.net/bug.php?id=74556
        // PHP uses "\0" string and HHVM uses empty string (colon removed above)
        if ($address === '' || $address[0] === "\x00") {
            return null;
        }

        return 'unix://' . $address;
    }

    /**
     * Retrieve the PID (process identifier) of the remote side of the Connection. The pid will be cached to avoid
     * requesting the value multiple times. If the remote pid was not cached before the connection is closed, the remote
     * pid cannot be retrieved anymore.
     *
     * Warning: Process IDs are not unique, thus they are a weak entropy source. Relying on pids in security-dependent
     * contexts should be avoided.
     *
     * @return int|null
     */
    public function getRemotePid()
    {
        // If the remote pid has already been cached, return that value.
        if ($this->remote_pid !== null) {
            return $this->remote_pid;
        }

        if (get_resource_type($this->stream) !== self::RESOURCE_TYPE_STREAM) {
            return null;
        }

        $socket = socket_import_stream($this->stream);

        if ($socket === false || $socket === null) {
            return null;
        }

        // Get the PID of the remote side of the socket.
        $pid = socket_get_option($socket, SOL_SOCKET, self::SO_PEERCRED);

        if ($pid === false) {
            return null;
        }

        $this->remote_pid = (int)$pid;

        return $this->remote_pid;
    }

    /**
     * Retrieve the PID (process identifier) of the local side of the Connection.
     *
     * Warning: Process IDs are not unique, thus they are a weak entropy source. Relying on pids in security-dependent
     * contexts should be avoided.
     *
     * @return int|null
     */
    public function getLocalPid()
    {
        if ($this->local_pid !== null) {
            return $this->local_pid;
        }

        $pid = getmypid();
        if ($pid === false) {
            return null;
        }

        $this->local_pid = $pid;

        return $this->local_pid;
    }
}
