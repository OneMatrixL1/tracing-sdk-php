<?php

declare(strict_types=1);

namespace Tracing\Sdk\Auth;

interface AuthenticatorInterface
{
    /**
     * Apply this authentication method to an outgoing request. Implementations
     * may set cURL transport options directly (e.g. client certificates) and/or
     * append entries to $headers (e.g. an Authorization header).
     *
     * @param \CurlHandle|resource $ch
     * @param string[] $headers
     */
    public function apply($ch, array &$headers): void;
}
