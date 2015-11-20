<?php

namespace React\SocketClient;

use React\EventLoop\LoopInterface;
use React\Stream\Stream;
use React\Promise;

class SecureConnector implements ConnectorInterface
{
    private $connector;
    private $streamEncryption;
    private $context;

    public function __construct(ConnectorInterface $connector, LoopInterface $loop, array $context = array())
    {
        $this->connector = $connector;
        $this->streamEncryption = new StreamEncryption($loop);
        $this->context = $context;
    }

    public function create($host, $port)
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            return Promise\reject(new \BadMethodCallException('Encryption not supported on your platform (HHVM < 3.8?)'));
        }

        $context = $this->context + array(
            'SNI_enabled' => true,
            'SNI_server_name' => $host,
            'peer_name' => $host
        );

        return $this->connector->create($host, $port)->then(function (Stream $stream) use ($context) {
            // (unencrypted) TCP/IP connection succeeded

            // set required SSL/TLS context options
            stream_context_set_option($stream->stream, array('ssl' => $context));

            // try to enable encryption
            return $this->streamEncryption->enable($stream)->then(null, function ($error) use ($stream) {
                // establishing encryption failed => close invalid connection and return error
                $stream->close();
                throw $error;
            });
        });
    }
}
