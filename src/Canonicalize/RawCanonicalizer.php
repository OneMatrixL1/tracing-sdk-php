<?php

declare(strict_types=1);

namespace Tracing\Sdk\Canonicalize;

/**
 * Identity canonicalizer for `dataType: 'raw'` — the caller is asserting
 * their data is already in a single, deterministic representation, so it's
 * hashed byte-for-byte as given, with no parsing or normalization step.
 */
class RawCanonicalizer implements CanonicalizerInterface
{
    public function canonicalize(string $rawData): string
    {
        return $rawData;
    }
}
