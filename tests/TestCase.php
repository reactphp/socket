<?php

namespace React\Tests\Socket;

use PHPUnit\Framework\TestCase as BaseTestCase;
use React\Promise\Promise;
use React\Stream\ReadableStreamInterface;

class TestCase extends BaseTestCase
{
    public function expectCallableExactly($amount)
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

    protected function expectCallableOnceWithException($type, $message = null, $code = null)
    {
        return $this->expectCallableOnceWith(
            $this->logicalAnd(
                $this->isInstanceOf($type),
                $this->callback(function (\Exception $e) use ($message) {
                    return $message === null || $e->getMessage() === $message;
                }),
                $this->callback(function (\Exception $e) use ($code) {
                    return $code === null || $e->getCode() === $code;
                })
            )
        );
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

    protected function buffer(ReadableStreamInterface $stream, $timeout)
    {
        if (!$stream->isReadable()) {
            return '';
        }

        $buffer = \React\Async\await(\React\Promise\Timer\timeout(new Promise(
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
        ), $timeout));

        // let loop tick for reactphp/async v4 to clean up any remaining stream resources
        // @link https://github.com/reactphp/async/pull/65 reported upstream // TODO remove me once merged
        if (function_exists('React\Async\async')) {
            \React\Async\await(\React\Promise\Timer\sleep(0));
        }

        return $buffer;
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

    protected function supportsTls13()
    {
        // TLS 1.3 is supported as of OpenSSL 1.1.1 (https://www.openssl.org/blog/blog/2018/09/11/release111/)
        // The OpenSSL library version can only be obtained by parsing output from phpinfo().
        // OPENSSL_VERSION_TEXT refers to header version which does not necessarily match actual library version
        // see php -i | grep OpenSSL
        // OpenSSL Library Version => OpenSSL 1.1.1  11 Sep 2018
        ob_start();
        phpinfo(INFO_MODULES);
        $info = ob_get_clean();

        if (preg_match('/OpenSSL Library Version => OpenSSL ([\d\.]+)/', $info, $match)) {
            return version_compare($match[1], '1.1.1', '>=');
        }
        return false;
    }

    public function assertContainsString($needle, $haystack)
    {
        if (method_exists($this, 'assertStringContainsString')) {
            // PHPUnit 7.5+
            $this->assertStringContainsString($needle, $haystack);
        } else {
            // legacy PHPUnit 4- PHPUnit 7.5
            $this->assertContains($needle, $haystack);
        }
    }

    public function assertMatchesRegExp($pattern, $string)
    {
        if (method_exists($this, 'assertMatchesRegularExpression')) {
            // PHPUnit 10
            $this->assertMatchesRegularExpression($pattern, $string);
        } else {
            // legacy PHPUnit 4 - PHPUnit 9.2
            $this->assertRegExp($pattern, $string);
        }
    }

    public function assertDoesNotMatchRegExp($pattern, $string)
    {
        if (method_exists($this, 'assertDoesNotMatchRegularExpression')) {
            // PHPUnit 10
            $this->assertDoesNotMatchRegularExpression($pattern, $string);
        } else {
            // legacy PHPUnit 4 - PHPUnit 9.2
            $this->assertNotRegExp($pattern, $string);
        }
    }

}
