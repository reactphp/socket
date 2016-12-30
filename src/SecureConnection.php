<?php

namespace React\Socket;

/**
 * A connection supporting transport layer security.
 */
class SecureConnection extends Connection
{
    protected $isSecure = false;
    protected $protocolNumber;

    public function handleData($stream)
    {
        if (! $this->isSecure) {
            $enabled = stream_socket_enable_crypto($stream, true, $this->protocolNumber);
            if ($enabled === false) {
                $this
                    ->err('Failed to complete a secure handshake with the client.')
                    ->end()
                ;
                return;
            } elseif ($enabled === 0) {
                return;
            }
            $this->isSecure = true;
            $this->emit('connection', array($this));
        }

        $data = fread($stream, $this->bufferSize);

        if ('' !== $data && false !== $data) {
            $this->emit('data', array($data, $this));
        }

        if (false === $data || !is_resource($stream) || feof($stream)) {
            $this->end();
        }
    }

    /**
     * Set the STREAM_CRYPTO_METHOD_*_SERVER flags suitable for enabling TLS on
     * the socket stream handled by this connection.
     *
     * @param int $protocolNumber
     */
    public function setProtocol($protocolNumber = null)
    {
        $this->protocolNumber = $protocolNumber;
    }

    private function err($message)
    {
        $this->emit('error', array(new \RuntimeException($message)));
        return $this;
    }
}
