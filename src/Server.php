<?php

namespace React\Socket;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;

/** Emits the connection event */
class Server extends EventEmitter implements ServerInterface
{
    public $master;
    private $loop;

    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;
    }

    public function listen($port, $host = '127.0.0.1', $streamContext = array())
    {
        if (isset($streamContext['ssl']) && PHP_VERSION_ID < 50600) {
            throw new \RuntimeException(
                'Secure connections are not available before PHP 5.6.0'
            );
        }

        if (strpos($host, ':') !== false) {
            // enclose IPv6 addresses in square brackets before appending port
            $host = '[' . $host . ']';
        }

        $this->master = @stream_socket_server(
            "tcp://$host:$port",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            stream_context_create($streamContext)
        );
        if (false === $this->master) {
            $message = "Could not bind to tcp://$host:$port: $errstr";
            throw new ConnectionException($message, $errno);
        }
        stream_set_blocking($this->master, 0);

        $that = $this;

        $this->loop->addReadStream($this->master, function ($master) use ($that) {
            $newSocket = @stream_socket_accept($master);
            if (false === $newSocket) {
                $that->emit('error', array(new \RuntimeException('Error accepting new connection')));

                return;
            }
            $that->handleConnection($newSocket);
        });
    }

    public function handleConnection($socket)
    {
        stream_set_blocking($socket, 0);

        $client = $this->createConnection($socket);

        $this->emit('connection', array($client));
    }

    public function getPort()
    {
        $name = stream_socket_get_name($this->master, false);

        return (int) substr(strrchr($name, ':'), 1);
    }

    public function shutdown()
    {
        $this->loop->removeStream($this->master);
        fclose($this->master);
        $this->removeAllListeners();
    }

    public function createConnection($socket)
    {
        $connection = null;
        $context = stream_context_get_options($socket);

        if (! isset($context['ssl'])) {
            $connection = new Connection($socket, $this->loop);
        } else {
            $connection = new SecureConnection($socket, $this->loop);
            $connection->setProtocol($this->getSecureProtocolNumber($context));
            $scope = $this;
            $connection->on('connection', function ($dataConn) use ($scope) {
                $scope->emit('connection', array($dataConn));
            });
        }

        return $connection;
    }

    /**
     * Get the STREAM_CRYPTO_METHOD_*_SERVER flags suitable for enabling TLS
     * on a server socket.
     *
     * Used the supplied $streamContext['ssl']['crypto_method'] or a default set
     * which will support as many SSL/TLS protocols as possible.
     *
     * @param array $streamContext
     *
     * @return int
     */
    public function getSecureProtocolNumber($streamContext)
    {
        if (isset($streamContext['ssl']['crypto_method'])) {
            return $streamContext['ssl']['crypto_method'];
        } elseif (defined('STREAM_CRYPTO_METHOD_ANY_SERVER')) {
            return constant('STREAM_CRYPTO_METHOD_ANY_SERVER');
        }
        $protoNum = 0;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_SERVER')) {
            $protoNum |= constant('STREAM_CRYPTO_METHOD_TLSv1_2_SERVER');
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_1_SERVER')) {
            $protoNum |= constant('STREAM_CRYPTO_METHOD_TLSv1_1_SERVER');
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_0_SERVER')) {
            $protoNum |= constant('STREAM_CRYPTO_METHOD_TLSv1_0_SERVER');
        }
        if (defined('STREAM_CRYPTO_METHOD_SSLv3_SERVER')) {
            $protoNum |= constant('STREAM_CRYPTO_METHOD_SSLv3_SERVER');
        }
        if (defined('STREAM_CRYPTO_METHOD_SSLv2_SERVER')) {
            $protoNum |= constant('STREAM_CRYPTO_METHOD_SSLv2_SERVER');
        }
        return $protoNum;
    }
}
