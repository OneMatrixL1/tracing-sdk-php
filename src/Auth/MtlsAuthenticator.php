<?php

declare(strict_types=1);

namespace Tracing\Sdk\Auth;

use Tracing\Sdk\Exception\ConfigException;

/**
 * Mutual TLS: the SDK presents a client certificate during the TLS handshake
 * and (optionally) verifies the server's certificate against a supplied CA.
 */
class MtlsAuthenticator implements AuthenticatorInterface
{
    /** @var string */
    private $cert;

    /** @var string */
    private $key;

    /** @var string|null */
    private $caCert;

    /** @var string|null */
    private $passphrase;

    public function __construct(string $cert, string $key, ?string $caCert = null, ?string $passphrase = null)
    {
        if (!is_file($cert)) {
            throw new ConfigException(\sprintf('mTLS client certificate not found: %s', $cert));
        }

        if (!is_file($key)) {
            throw new ConfigException(\sprintf('mTLS client key not found: %s', $key));
        }

        if ($caCert !== null && !is_file($caCert)) {
            throw new ConfigException(\sprintf('mTLS CA certificate not found: %s', $caCert));
        }

        $this->cert = $cert;
        $this->key = $key;
        $this->caCert = $caCert;
        $this->passphrase = $passphrase;
    }

    /**
     * @param \CurlHandle|resource $ch
     * @param string[] $headers
     */
    public function apply($ch, array &$headers): void
    {
        curl_setopt($ch, CURLOPT_SSLCERT, $this->cert);
        curl_setopt($ch, CURLOPT_SSLKEY, $this->key);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        if ($this->passphrase !== null) {
            curl_setopt($ch, CURLOPT_SSLKEYPASSWD, $this->passphrase);
        }

        if ($this->caCert !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $this->caCert);
        }
    }
}
