<?php

declare(strict_types=1);

namespace Tracing\Sdk\Hash;

interface HasherInterface
{
    /**
     * Hash a canonicalized string. The result must change completely for
     * even a single-bit change in the input (avalanche property).
     */
    public function hash(string $canonical): string;
}
