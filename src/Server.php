<?php

namespace React\Socket;

use Evenement\EventEmitterTrait;
use React\EventLoop\LoopInterface;

/** @event connection */
class Server implements ServerInterface
{
    use EventEmitterTrait;
    use ServerTrait;

    public function __construct(LoopInterface $loop)
    {
        $this->setLoop($loop);
    }
}
