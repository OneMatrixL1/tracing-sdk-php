<?php

declare(strict_types=1);

namespace Tracing\Sdk;

/**
 * Per-call overrides for send(), sendBatch(), and hash(). Anything left unset
 * falls back to the value the SDK was constructed with.
 */
final class SendOptions
{
    /** @var string|null */
    private $dataType;

    /**
     * @param string|null $dataType "json" | "xml" | "raw"
     */
    public function __construct(?string $dataType = null)
    {
        $this->dataType = $dataType;
    }

    public static function dataType(string $dataType): self
    {
        return new self($dataType);
    }

    public function getDataType(): ?string
    {
        return $this->dataType;
    }

    public function withDataType(?string $dataType): self
    {
        $clone = clone $this;
        $clone->dataType = $dataType;

        return $clone;
    }
}
