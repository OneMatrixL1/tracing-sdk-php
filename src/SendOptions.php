<?php

declare(strict_types=1);

namespace Tracing\Sdk;

use Tracing\Sdk\Exception\ConfigException;

/**
 * Per-call overrides for send(), sendBatch(), hash(), and queryByHash().
 * Anything left unset falls back to the value the SDK was constructed with.
 */
final class SendOptions
{
    /** @var string|null */
    private $dataType;

    /** @var int|null */
    private $timeoutMs;

    /**
     * @param string|null $dataType "json" | "xml" | "raw"
     * @param int|null $timeoutMs how long a single HTTP request may take, in
     *        milliseconds; null falls back to the transport default
     * @throws ConfigException if timeoutMs is not a positive integer
     */
    public function __construct(?string $dataType = null, ?int $timeoutMs = null)
    {
        $this->dataType = $dataType;
        $this->timeoutMs = self::assertTimeoutMs($timeoutMs);
    }

    public static function dataType(string $dataType): self
    {
        return new self($dataType);
    }

    /**
     * @throws ConfigException if timeoutMs is not a positive integer
     */
    public static function timeoutMs(int $timeoutMs): self
    {
        return new self(null, $timeoutMs);
    }

    public function getDataType(): ?string
    {
        return $this->dataType;
    }

    public function getTimeoutMs(): ?int
    {
        return $this->timeoutMs;
    }

    public function withDataType(?string $dataType): self
    {
        $clone = clone $this;
        $clone->dataType = $dataType;

        return $clone;
    }

    /**
     * @throws ConfigException if timeoutMs is not a positive integer
     */
    public function withTimeoutMs(?int $timeoutMs): self
    {
        $clone = clone $this;
        $clone->timeoutMs = self::assertTimeoutMs($timeoutMs);

        return $clone;
    }

    private static function assertTimeoutMs(?int $timeoutMs): ?int
    {
        if ($timeoutMs !== null && $timeoutMs <= 0) {
            throw new ConfigException('timeoutMs must be a positive number of milliseconds');
        }

        return $timeoutMs;
    }
}
