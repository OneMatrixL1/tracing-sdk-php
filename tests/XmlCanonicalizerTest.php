<?php

declare(strict_types=1);

namespace Tracing\Sdk\Tests;

use PHPUnit\Framework\TestCase;
use Tracing\Sdk\Canonicalize\XmlCanonicalizer;
use Tracing\Sdk\Exception\CanonicalizationException;

/**
 * Cases come from testdata/xml-canonicalize.json (shared with every other
 * language SDK) rather than being hardcoded here.
 */
class XmlCanonicalizerTest extends TestCase
{
    public static function caseProvider(): array
    {
        $fixture = FixtureLoader::load('xml-canonicalize.json');
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
        $canonicalizer = new XmlCanonicalizer();
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

        if (!empty($case['allowError'])) {
            foreach ($inputs as $input) {
                try {
                    $output = $canonicalizer->canonicalize($input);
                    $this->assertStringNotContainsString(
                        $case['forbiddenSubstring'],
                        $output,
                        sprintf('Fixture case "%s" leaked forbidden content', $case['id'])
                    );
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
