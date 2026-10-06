<?php

namespace React\Tests\Socket\Stub;

use Evenement\EventEmitter;
use React\Socket\ConnectionInterface;
use React\Stream\WritableStreamInterface;
use React\Stream\Util;

class ConnectionStub extends EventEmitter implements ConnectionInterface
{
    private $data = '';

    public function isReadable()
    {
        return true;
    }

    public function isWritable()
    {
        return true;
    }

    public function pause(): void
    {
    }

    public function resume(): void
    {
    }

    public function pipe(WritableStreamInterface $dest, array $options = [])
    {
        Util::pipe($this, $dest, $options);

        return $dest;
    }

    public function write($data)
    {
        $this->data .= $data;

        return true;
    }

    public function end($data = null)
    {
    }

    public function close(): void
    {
    }

    public function getData()
    {
        return $this->data;
    }

    public function getRemoteAddress(): ?string
    {
        return '127.0.0.1';
    }

    public function getLocalAddress(): ?string
    {
        return null;
    }
}
