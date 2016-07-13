<?php


namespace React\Socket;


use React\EventLoop\LoopInterface;

interface ConnectionFactoryInterface
{
    /**
     * @param $socket The PHP stream resource.
     * @param LoopInterface $loop The loop to use for constructing the connection.
     * @throws \InvalidArgumentException when $socket is not of type resource.
     * @return ConnectionInterface
     */
    public function createConnection($socket, LoopInterface $loop);
}