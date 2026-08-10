<?php

declare(strict_types=1);

namespace Tracing\Sdk\Canonicalize;

use Tracing\Sdk\Exception\CanonicalizationException;

/**
 * Canonical JSON: decode, recursively sort object keys (arrays keep their
 * original order), then re-encode without any of the original whitespace.
 * Two JSON documents with the same content but different key order or
 * formatting collapse to the exact same string.
 */
class JsonCanonicalizer implements CanonicalizerInterface
{
    public function canonicalize(string $rawData): string
    {
        $decoded = json_decode($rawData, true, 512, JSON_BIGINT_AS_STRING);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new CanonicalizationException('Invalid JSON payload: ' . json_last_error_msg());
        }

        $sorted = $this->sortRecursively($decoded);

        $encoded = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            throw new CanonicalizationException('Failed to encode canonical JSON: ' . json_last_error_msg());
        }

        return $encoded;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function sortRecursively($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $isList = $this->isList($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }

        if (!$isList) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    private function isList(array $array): bool
    {
        if ($array === []) {
            return true;
        }

        return array_keys($array) === range(0, count($array) - 1);
    }
}
