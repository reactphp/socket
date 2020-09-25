<?php

namespace React\Socket;

use InvalidArgumentException;

class TlsPeer
{
    /** @var resource of type OpenSSL X.509 */
    private $peerCertificate;

    /** @var resource[] of type OpenSSL X.509 */
    private $peerCertificateChain;

    public function __construct($peerCertificate = null, array $peerCertificateChain = null)
    {
        if ($peerCertificate !== null) {
            static::assertX509Resource($peerCertificate);
            $this->peerCertificate = $peerCertificate;
        }
        if ($peerCertificateChain !== null) {
            foreach ($peerCertificateChain as $resource) {
                static::assertX509Resource($resource);
            }
            $this->peerCertificateChain = $peerCertificateChain;
        }
    }

    public static function fromContextOptions($options)
    {
        if (isset($options['ssl']['peer_certificate'])) {
            $peerCertificate = $options['ssl']['peer_certificate'];
        } else {
            $peerCertificate = null;
        }
        if (isset($options['ssl']['peer_certificate_chain'])) {
            $peerCertificateChain = $options['ssl']['peer_certificate_chain'];
        } else {
            $peerCertificateChain = null;
        }

        return new static($peerCertificate, $peerCertificateChain);
    }

    protected static function assertX509Resource($resource)
    {
        if (! \is_resource($resource)) {
            throw new \InvalidArgumentException(\sprintf(
                'Resource expected, got "%s"',
                \gettype($resource)
            ));
        }
        if (\get_resource_type($resource) !== 'OpenSSL X.509') {
            throw new \InvalidArgumentException(\sprintf(
                'Resource of type "OpenSSL X.509" expected, got "%s"',
                \get_resource_type($resource)
            ));
        }
    }

    /**
     * @return bool
     */
    public function hasPeerCertificate()
    {
        return $this->peerCertificate !== null;
    }

    /**
     * @return null|resource (OpenSSL x509)
     */
    public function getPeerCertificate()
    {
        return $this->peerCertificate;
    }

    /**
     * @return bool
     */
    public function hasPeerCertificateChain()
    {
        return $this->peerCertificateChain !== null;
    }

    /**
     * @return null|array of OpenSSL x509 resources
     */
    public function getPeerCertificateChain()
    {
        return $this->peerCertificateChain;
    }

    protected function free()
    {
        if ($this->peerCertificate) {
            \openssl_x509_free($this->peerCertificate);
            $this->peerCertificate = null;
        }
        if (\is_array($this->peerCertificateChain)) {
            foreach ($this->peerCertificateChain as $cert) {
                \openssl_x509_free($cert);
            }
            $this->peerCertificateChain = null;
        }
    }

    public function __destruct()
    {
        $this->free();
    }
}
