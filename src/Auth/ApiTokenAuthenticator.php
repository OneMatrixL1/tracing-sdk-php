<?php

declare(strict_types=1);

namespace Tracing\Sdk\Auth;

class ApiTokenAuthenticator implements AuthenticatorInterface
{
    /** @var string */
    private $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    public function apply($ch, array &$headers): void
    {
        $headers[] = "X-API-Key: {$this->token}";
    }
}
