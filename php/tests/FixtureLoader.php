<?php

declare(strict_types=1);

namespace Tracing\Sdk\Tests;

/**
 * Loads the language-agnostic test vectors shared across all SDKs from
 * `testdata/*.json` at the repo root, so this SDK's canonicalizer/hasher
 * tests stay in lockstep with every other language's implementation.
 */
final class FixtureLoader
{
    public static function load(string $name): array
    {
        $path = dirname(__DIR__, 2) . '/testdata/' . $name;
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read fixture file: %s', $path));
        }

        $decoded = json_decode($contents, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(sprintf('Invalid JSON in fixture file %s: %s', $path, json_last_error_msg()));
        }

        return $decoded;
    }
}
