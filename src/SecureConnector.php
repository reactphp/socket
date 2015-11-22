<?php

namespace React\SocketClient;

use React\EventLoop\LoopInterface;
use React\Stream\Stream;
use React\Promise;

class SecureConnector implements ConnectorInterface
{
    private $connector;
    private $streamEncryption;

    public function __construct(ConnectorInterface $connector, LoopInterface $loop)
    {
        $this->connector = $connector;
        $this->streamEncryption = new StreamEncryption($loop);
    }

    public function create($host, $port)
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            return Promise\reject(new \BadMethodCallException('Encryption not supported on your platform (HHVM < 3.8?)'));
        }

        return $this->connector->create($host, $port)->then(function (Stream $stream) use ($host) {
            // (unencrypted) TCP/IP connection succeeded

            // set required SSL/TLS context options
            $resource = $stream->stream;
            stream_context_set_option($resource, 'ssl', 'SNI_enabled', true);
            stream_context_set_option($resource, 'ssl', 'SNI_server_name', $host);
            stream_context_set_option($resource, 'ssl', 'peer_name', $host);

            // try to enable encryption
            return $this->streamEncryption->enable($stream)->then(null, function ($error) use ($stream) {
                // establishing encryption failed => close invalid connection and return error
                $stream->close();
                throw $error;
            });
        });
    }
}
