<?php

declare(strict_types=1);

namespace Tracing\Sdk\Verify;

use Tracing\Sdk\Exception\ConfigException;
use Tracing\Sdk\Hash\HasherInterface;

/**
 * ABI-decodes the Solidity event the anchoring contract emits for every
 * anchored record, plus the small hex helpers that come with reading receipt
 * logs.
 */
class AnchoredEventDecoder
{
    /** Solidity signature the event's topics[0] is derived from. */
    public const EVENT_SIGNATURE = 'Anchored(bytes32,uint64)';

    /** @var HasherInterface */
    private $hasher;

    /** @var string|null lazily computed keccak256 of EVENT_SIGNATURE */
    private $topic;

    public function __construct(HasherInterface $hasher)
    {
        $this->hasher = $hasher;
    }

    /**
     * keccak256 of the event signature — the topics[0] every anchor log
     * carries, lowercase and 0x-prefixed.
     */
    public function topic(): string
    {
        if ($this->topic === null) {
            $this->topic = strtolower($this->hasher->hash(self::EVENT_SIGNATURE));
        }

        return $this->topic;
    }

    /**
     * Decode one receipt log as Anchored(bytes32,uint64).
     *
     * Returns null when the log is not an anchor event at all — wrong
     * topics[0], or too few words to hold both arguments. Indexed and
     * non-indexed arguments are both accepted: the ABI puts indexed values in
     * topics[1..] in declaration order and the rest in data, so reading
     * topics-then-data recovers the argument list either way.
     *
     * @param mixed $log a log object from an eth_getTransactionReceipt result
     * @return array{dataHash: string, signingTime: int}|null
     */
    public function decode($log): ?array
    {
        if (!\is_array($log) || !isset($log['topics']) || !\is_array($log['topics']) || $log['topics'] === []) {
            return null;
        }

        $topics = array_values($log['topics']);

        if (self::hexBody((string) $topics[0]) !== self::hexBody($this->topic())) {
            return null;
        }

        // The event's argument words, in declaration order.
        $words = [];

        foreach (\array_slice($topics, 1) as $topic) {
            $words[] = self::hexBody((string) $topic);
        }

        $data = self::hexBody(isset($log['data']) ? (string) $log['data'] : '');

        if ($data !== '') {
            foreach (str_split($data, 64) as $chunk) {
                $words[] = $chunk;
            }
        }

        if (\count($words) < 2 || \strlen($words[0]) !== 64 || \strlen($words[1]) !== 64) {
            return null;
        }

        return [
            'dataHash' => '0x' . $words[0],
            // uint64 is right-aligned in its 32-byte word: the low 8 bytes hold it.
            'signingTime' => self::hexToInt(substr($words[1], 48)),
        ];
    }

    /**
     * Validate a 32-byte hex hash and return it lowercased and 0x-prefixed,
     * so comparing two hashes is plain string equality.
     *
     * @throws ConfigException if the value is empty or not 32 bytes of hex
     */
    public static function normalizeHash(string $hash, string $label): string
    {
        $body = self::hexBody(trim($hash));

        if ($body === '') {
            throw new ConfigException(\sprintf('%s is required', $label));
        }

        if (preg_match('/^[0-9a-f]{64}$/', $body) !== 1) {
            throw new ConfigException(\sprintf('%s must be a 32-byte hex string, got "%s"', $label, $hash));
        }

        return '0x' . $body;
    }

    /** Strip an optional 0x prefix and lowercase the remaining hex digits. */
    public static function hexBody(string $hex): string
    {
        $hex = strtolower(trim($hex));

        return strpos($hex, '0x') === 0 ? substr($hex, 2) : $hex;
    }

    /** Decode a hex quantity (0x-prefixed or bare) into an int. */
    public static function hexToInt(string $hex): int
    {
        $body = self::hexBody($hex);

        return $body === '' ? 0 : (int) hexdec($body);
    }
}
