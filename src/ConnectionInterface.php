<?php

namespace React\Socket;

use Evenement\EventEmitterInterface;
use React\Stream\ReadableStreamInterface;
use React\Stream\WritableStreamInterface;

interface ConnectionInterface extends ReadableStreamInterface, WritableStreamInterface
{
    /**
     * @return string The remote address for the connection.
     */
    public function getRemoteAddress();
}
