<?php

declare(strict_types=1);

namespace Tracing\Sdk\Canonicalize;

interface CanonicalizerInterface
{
    /**
     * Convert raw input into a single, deterministic string representation
     * so that semantically identical payloads always produce the same output,
     * regardless of field order, whitespace, or encoding.
     *
     * @throws \Tracing\Sdk\Exception\CanonicalizationException
     */
    public function canonicalize(string $rawData): string;
}
