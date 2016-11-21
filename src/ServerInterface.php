<?php

namespace React\Socket;

use Evenement\EventEmitterInterface;

/** Emits the connection event */
interface ServerInterface extends EventEmitterInterface
{
    /**
     * @param int $port The port number to listen on.
     * @param string $host The address to bind on.
     * @return void
     */
    public function listen($port, $host = '127.0.0.1');

    /**
     * @return int
     */
    public function getPort();

    /**
     * @return void
     */
    public function shutdown();
}
