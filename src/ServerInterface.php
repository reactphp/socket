<?php

namespace React\Socket;

use Evenement\EventEmitterInterface;

/** Emits the connection event */
interface ServerInterface extends EventEmitterInterface
{
    public function listen($port, $host = '127.0.0.1');
    public function bind($address);
    public function getPort();
    public function shutdown();
}
