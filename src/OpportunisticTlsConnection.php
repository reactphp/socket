<?php

namespace React\Socket;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use React\Stream\DuplexResourceStream;
use React\Stream\Util;
use React\Stream\WritableResourceStream;
use React\Stream\WritableStreamInterface;

/**
 * The actual connection implementation for StartTlsConnectionInterface
 *
 * This class should only be used internally, see StartTlsConnectionInterface instead.
 *
 * @see OpportunisticTlsConnectionInterface
 * @internal
 */
class OpportunisticTlsConnection extends EventEmitter implements OpportunisticTlsConnectionInterface
{
    /** @var Connection */
    private $connection;

    /** @var StreamEncryption */
    private $streamEncryption;

    /** @var string */
    private $uri;

    public function __construct(Connection $connection, StreamEncryption $streamEncryption, $uri)
    {
        $this->connection = $connection;
        $this->streamEncryption = $streamEncryption;
        $this->uri = $uri;

        Util::forwardEvents($connection, $this, array('data', 'end', 'error', 'close'));
    }

    public function getRemoteAddress()
    {
        return $this->connection->getRemoteAddress();
    }

    public function getLocalAddress()
    {
        return $this->connection->getLocalAddress();
    }

    public function isReadable()
    {
        return $this->connection->isReadable();
    }

    public function pause()
    {
        $this->connection->pause();
    }

    public function resume()
    {
        $this->connection->resume();
    }

    public function pipe(WritableStreamInterface $dest, array $options = array())
    {
        return $this->connection->pipe($dest, $options);
    }

    public function close()
    {
        $this->connection->close();
    }

    public function enableEncryption()
    {
        $that = $this;
        $connection = $this->connection;
        $uri = $this->uri;

        return $this->streamEncryption->enable($connection)->then(function () use ($that) {
            return $that;
        }, function ($error) use ($connection, $uri) {
            // establishing encryption failed => close invalid connection and return error
            $connection->close();

            throw new \RuntimeException(
                'Connection to ' . $uri . ' failed during TLS handshake: ' . $error->getMessage(),
                $error->getCode()
            );
        });
    }

    public function isWritable()
    {
        return $this->connection->isWritable();
    }

    public function write($data)
    {
        return $this->connection->write($data);
    }

    public function end($data = null)
    {
        $this->connection->end($data);
    }
}
