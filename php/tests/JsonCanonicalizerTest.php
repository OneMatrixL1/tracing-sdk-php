<?php

declare(strict_types=1);

namespace Tracing\Sdk\Tests;

use PHPUnit\Framework\TestCase;
use Tracing\Sdk\Canonicalize\JsonCanonicalizer;
use Tracing\Sdk\Exception\CanonicalizationException;

/**
 * Cases come from testdata/json-canonicalize.json (shared with every other
 * language SDK) rather than being hardcoded here.
 */
class JsonCanonicalizerTest extends TestCase
{
    public static function caseProvider(): array
    {
        $fixture = FixtureLoader::load('json-canonicalize.json');
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
        $canonicalizer = new JsonCanonicalizer();
        $inputs = $case['inputs'] ?? [$case['input']];

        if (!empty($case['expectError'])) {
            foreach ($inputs as $input) {
                try {
                    $canonicalizer->canonicalize($input);
                    $this->fail(sprintf('Expected CanonicalizationException for case "%s"', $case['id']));
                } catch (CanonicalizationException $e) {
                    $this->addToAssertionCount(1);
                }
            }

            return;
        }

        foreach ($inputs as $input) {
            $this->assertSame(
                $case['expectedOutput'],
                $canonicalizer->canonicalize($input),
                sprintf('Fixture case "%s" failed for input: %s', $case['id'], $input)
            );
        }
    }
}
