<?php

declare(strict_types=1);

namespace Tracing\Sdk\Tests;

use PHPUnit\Framework\TestCase;
use Tracing\Sdk\Canonicalize\RawCanonicalizer;

class RawCanonicalizerTest extends TestCase
{
    public function testReturnsInputUnchanged(): void
    {
        $canonicalizer = new RawCanonicalizer();

        $this->assertSame('  {"b":1,"a":2}  ', $canonicalizer->canonicalize('  {"b":1,"a":2}  '));
    }

    public function testEmptyStringIsPreserved(): void
    {
        $canonicalizer = new RawCanonicalizer();

        $this->assertSame('', $canonicalizer->canonicalize(''));
    }

    public function testDoesNotThrowOnMalformedJsonOrXml(): void
    {
        $canonicalizer = new RawCanonicalizer();

        $this->assertSame('{not-json', $canonicalizer->canonicalize('{not-json'));
    }
}
