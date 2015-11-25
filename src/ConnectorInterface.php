<?php

namespace React\SocketClient;

interface ConnectorInterface
{
    /**
     *
     *
     * @param string $uri
     * @return Promise Returns a Promise<\React\Stream\Stream, \Exception>, i.e.
     *     it either resolves with a Stream instance or rejects with an Exception.
     */
    public function connect($uri);
}
