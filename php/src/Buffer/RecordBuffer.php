<?php

declare(strict_types=1);

namespace Tracing\Sdk\Buffer;

/**
 * In-memory-only buffer of { hash, signingTime } entries. The original
 * rawData is never stored here — only the hash and the caller-supplied
 * signingTime survive past processing, matching the SDK's local-only
 * pipeline (nothing leaves the process until a flush is triggered).
 */
class RecordBuffer
{
    /** @var array<int, array{hash: string, signingTime: mixed}> */
    private $items = [];

    /** @var int epoch milliseconds */
    private $lastFlushAt;

    public function __construct()
    {
        $this->lastFlushAt = self::nowMs();
    }

    /**
     * @param array{hash: string, signingTime: mixed} $item
     */
    public function push(array $item): void
    {
        $this->items[] = $item;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Remove and return all buffered items.
     *
     * @return array<int, array{hash: string, signingTime: mixed}>
     */
    public function drain(): array
    {
        $items = $this->items;
        $this->items = [];

        return $items;
    }

    public function markFlushed(): void
    {
        $this->lastFlushAt = self::nowMs();
    }

    public function elapsedSinceLastFlushMs(): int
    {
        return self::nowMs() - $this->lastFlushAt;
    }

    private static function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
