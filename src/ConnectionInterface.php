<?php

namespace React\Socket;

use React\Stream\DuplexStreamInterface;

interface ConnectionInterface extends DuplexStreamInterface
{
    public function getRemoteAddress();
}
