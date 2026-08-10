<?php

declare(strict_types=1);

namespace Tracing\Sdk\Auth;

class BasicAuthenticator implements AuthenticatorInterface
{
    /** @var string */
    private $username;

    /** @var string */
    private $password;

    public function __construct(string $username, string $password)
    {
        $this->username = $username;
        $this->password = $password;
    }

    public function apply($ch, array &$headers): void
    {
        $headers[] = 'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password);
    }
}
