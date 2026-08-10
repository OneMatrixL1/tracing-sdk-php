<?php

declare(strict_types=1);

namespace Tracing\Sdk\Tests;

use PHPUnit\Framework\TestCase;
use Tracing\Sdk\Hash\Keccak256Hasher;

/**
 * Cases come from testdata/keccak256.json (shared with every other language
 * SDK) rather than being hardcoded here.
 */
class Keccak256HasherTest extends TestCase
{
    public static function caseProvider(): array
    {
        $fixture = FixtureLoader::load('keccak256.json');
        $cases = [];

        foreach ($fixture['cases'] as $case) {
            $cases[$case['id']] = [$case];
        }

        return $cases;
    }

    /**
     * @dataProvider caseProvider
     */
    public function testFixtureCase(array $case): void
    {
        $hasher = new Keccak256Hasher();

        $this->assertSame($case['expectedHash'], $hasher->hash($case['input']));
    }
}
