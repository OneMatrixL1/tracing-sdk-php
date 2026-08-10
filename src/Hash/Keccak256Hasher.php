<?php

declare(strict_types=1);

namespace Tracing\Sdk\Hash;

use kornrunner\Keccak;

/**
 * Keccak-256 (the original NIST submission, not FIPS-202 SHA3-256 —
 * this is the variant used by Ethereum/EVM-style integrity proofs).
 * Delegates to kornrunner/keccak, a pure-PHP, well-exercised implementation,
 * rather than hand-rolling the sponge construction.
 */
class Keccak256Hasher implements HasherInterface
{
    public function hash(string $canonical): string
    {
        return '0x' . Keccak::hash($canonical, 256);
    }
}
