<?php

namespace React\Socket;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;

/** Emits the connection event */
class Server extends EventEmitter implements ServerInterface
{
    public $master;
    private $loop;
    private $isSecure = false;

    public function __construct(LoopInterface $loop)
    {
        $this->loop = $loop;
    }

    public function listen($port, $host = '127.0.0.1', $streamContext = array())
    {
        if (strpos($host, ':') !== false) {
            // enclose IPv6 addresses in square brackets before appending port
            $host = '[' . $host . ']';
        }
        if (isset($streamContext['ssl'])) {
            $this->isSecure = true;
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
        stream_set_blocking($this->master, (int) $this->isSecure);

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
        if ($this->isSecure) {
            $this->enableConnectionSecurity($socket);
        }

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
        return new Connection($socket, $this->loop);
    }

    /**
     * Turn on encryption for the supplied socket.  If the socket does not have
     * a stream context with a value for ['ssl']['crypto_method'] then a default
     * crypto method is used.  For PHP before 5.6 this means only SSLv2, SSLv3
     * and TLSv1.0 are enabled and only if PHP was built with an OpenSSL that
     * supports those protocols.  You might find that only TLSv1.0 is made
     * available, for example with an Ubuntu packaged PHP 5.5.9.  For PHP 5.6
     * and above, any protocol made available to PHP by OpenSSL will be enabled.
     *
     * @param resource $socket
     */
    private function enableConnectionSecurity($socket)
    {
        $context = stream_context_get_options($socket);

        if (! isset($context['ssl']['crypto_method'])) {

            $defaultCrypto = defined('STREAM_CRYPTO_METHOD_ANY_SERVER')
                ? STREAM_CRYPTO_METHOD_ANY_SERVER
                : STREAM_CRYPTO_METHOD_SSLv23_SERVER|STREAM_CRYPTO_METHOD_TLS_SERVER
            ;
            stream_socket_enable_crypto($socket, true, $defaultCrypto);

        } else if (PHP_VERSION_ID < 50600) {
            # "When enabling encryption you must specify the crypto type"
            stream_socket_enable_crypto($socket, true, $context['ssl']['crypto_method']);
        } else {
            stream_socket_enable_crypto($socket, true);
        }
    }
}
