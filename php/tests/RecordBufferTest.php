<?php

declare(strict_types=1);

namespace Tracing\Sdk\Tests;

use PHPUnit\Framework\TestCase;
use Tracing\Sdk\Buffer\RecordBuffer;

class RecordBufferTest extends TestCase
{
    public function testPushAndCount(): void
    {
        $buffer = new RecordBuffer();

        $this->assertTrue($buffer->isEmpty());

        $buffer->push(['hash' => '0x1', 'signingTime' => 1]);
        $buffer->push(['hash' => '0x2', 'signingTime' => 2]);

        $this->assertSame(2, $buffer->count());
        $this->assertFalse($buffer->isEmpty());
    }

    public function testDrainEmptiesBuffer(): void
    {
        $buffer = new RecordBuffer();
        $buffer->push(['hash' => '0x1', 'signingTime' => 1]);

        $drained = $buffer->drain();

        $this->assertCount(1, $drained);
        $this->assertTrue($buffer->isEmpty());
    }

    public function testElapsedSinceLastFlushIncreasesOverTime(): void
    {
        $buffer = new RecordBuffer();

        usleep(2000);

        $this->assertGreaterThan(0, $buffer->elapsedSinceLastFlushMs());
    }

    public function testMarkFlushedResetsElapsedTimer(): void
    {
        $buffer = new RecordBuffer();

        usleep(5000);
        $buffer->markFlushed();

        $this->assertLessThan(5, $buffer->elapsedSinceLastFlushMs());
    }
}
