<?php

namespace React\Tests\Socket\Stub;

use Evenement\EventEmitter;
use React\Socket\ServerInterface;

class ServerStub extends EventEmitter implements ServerInterface
{
    public function getPort()
    {
        return 80;
    }

    public function shutdown()
    {
    }
}
