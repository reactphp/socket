<?php

namespace React\Tests\Socket;

use React\Stream\ReadableStreamInterface;
use React\EventLoop\LoopInterface;
use Clue\React\Block;
use React\Promise\Promise;
use PHPUnit\Framework\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    protected function expectCallableExactly($amount)
    {
        $mock = $this->createCallableMock();
        $mock
            ->expects($this->exactly($amount))
            ->method('__invoke');

        return $mock;
    }

    protected function expectCallableOnce()
    {
        $mock = $this->createCallableMock();
        $mock
            ->expects($this->once())
            ->method('__invoke');

        return $mock;
    }

    protected function expectCallableOnceWith($value)
    {
        $mock = $this->createCallableMock();
        $mock
            ->expects($this->once())
            ->method('__invoke')
            ->with($value);

        return $mock;
    }

    protected function expectCallableNever()
    {
        $mock = $this->createCallableMock();
        $mock
            ->expects($this->never())
            ->method('__invoke');

        return $mock;
    }

    protected function createCallableMock()
    {
        return $this->getMockBuilder('React\Tests\Socket\Stub\CallableStub')->getMock();
    }

    protected function buffer(ReadableStreamInterface $stream, LoopInterface $loop, $timeout)
    {
        if (!$stream->isReadable()) {
            return '';
        }

        return Block\await(new Promise(
            function ($resolve, $reject) use ($stream) {
                $buffer = '';
                $stream->on('data', function ($chunk) use (&$buffer) {
                    $buffer .= $chunk;
                });

                $stream->on('error', $reject);

                $stream->on('close', function () use (&$buffer, $resolve) {
                    $resolve($buffer);
                });
            },
            function () use ($stream) {
                $stream->close();
                throw new \RuntimeException();
            }
        ), $loop, $timeout);
    }

    public function setExpectedException($exception, $exceptionMessage = '', $exceptionCode = null)
    {
        if (method_exists($this, 'expectException')) {
            // PHPUnit 5+
            $this->expectException($exception);
            if ($exceptionMessage !== '') {
                $this->expectExceptionMessage($exceptionMessage);
            }
            if ($exceptionCode !== null) {
                $this->expectExceptionCode($exceptionCode);
            }
        } else {
            // legacy PHPUnit 4
            parent::setExpectedException($exception, $exceptionMessage, $exceptionCode);
        }
    }
}
