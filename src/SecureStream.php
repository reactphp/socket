<?php

namespace React\SocketClient;

use Evenement\EventEmitterTrait;
use React\EventLoop\LoopInterface;
use React\Stream\DuplexStreamInterface;
use React\Stream\WritableStreamInterface;
use React\Stream\Stream;
use React\Stream\Util;

class SecureStream extends Stream implements DuplexStreamInterface
{
//    use EventEmitterTrait;

    public $stream;

    public $decorating;
    protected $loop;

    public function __construct(Stream $stream, LoopInterface $loop) {
        $this->stream = $stream->stream;
        $this->decorating = $stream;
        $this->loop = $loop;

        $stream->on('error', function($error) {
            $this->emit('error', [$error, $this]);
        });
        $stream->on('end', function() {
            $this->emit('end', [$this]);
        });
        $stream->on('close', function() {
            $this->emit('close', [$this]);
        });
        $stream->on('drain', function() {
            $this->emit('drain', [$this]);
        });

        $stream->pause();

        $this->resume();
    }

    public function handleData($stream)
    {
        $data = stream_get_contents($stream);

        $this->emit('data', [$data, $this]);

        if (!is_resource($stream) || feof($stream)) {
            $this->end();
        }
    }

    public function pause()
    {
        $this->loop->removeReadStream($this->decorating->stream);
    }

    public function resume()
    {
        if ($this->isReadable()) {
            $this->loop->addReadStream($this->decorating->stream, [$this, 'handleData']);
        }
    }

    public function isReadable()
    {
        return $this->decorating->isReadable();
    }

    public function isWritable()
    {
        return $this->decorating->isWritable();
    }

    public function write($data)
    {
        return $this->decorating->write($data);
    }

    public function close()
    {
        return $this->decorating->close();
    }

    public function end($data = null)
    {
        return $this->decorating->end($data);
    }

    public function pipe(WritableStreamInterface $dest, array $options = array())
    {
        Util::pipe($this, $dest, $options);

        return $dest;
    }
}