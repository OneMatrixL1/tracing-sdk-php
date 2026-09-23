<?php

declare(strict_types=1);

namespace Tracing\Sdk\Verify;

use Tracing\Sdk\Exception\ConfigException;
use Tracing\Sdk\Hash\Keccak256Hasher;

/**
 * Merkle inclusion proofs over Keccak-256 with sorted pairs — the layout of
 * OpenZeppelin's MerkleProof.verify (and merkletreejs with sortPairs). The
 * leaf is the record hash itself, and each parent is keccak256 of its two
 * children in ascending byte order, so a proof is just the sibling hashes
 * from the leaf up to the root, with no left/right flags.
 */
final class MerkleProof
{
    /** @var Keccak256Hasher|null */
    private static $hasher;

    /**
     * Fold a leaf and its sibling path into the tree root.
     *
     * @param string $leaf the record hash, 32 bytes of hex
     * @param array<int, string> $siblings sibling hashes ordered from the leaf
     *        up to the root; empty for a single-leaf tree, whose root is the
     *        leaf itself
     * @return string the root, 0x-prefixed lowercase hex
     * @throws ConfigException if a hash is not 32 bytes of hex
     */
    public static function computeRoot(string $leaf, array $siblings): string
    {
        $node = AnchoredEventDecoder::normalizeHash($leaf, 'leaf');

        foreach (array_values($siblings) as $i => $sibling) {
            $node = self::hashPair($node, AnchoredEventDecoder::normalizeHash((string) $sibling, \sprintf('siblings[%d]', $i)));
        }

        return $node;
    }

    /**
     * keccak256 of two 32-byte nodes concatenated in ascending byte order.
     *
     * @param string $a normalized (0x-prefixed lowercase) hex
     * @param string $b normalized (0x-prefixed lowercase) hex
     * @return string 0x-prefixed lowercase hex
     */
    public static function hashPair(string $a, string $b): string
    {
        // Same-length lowercase hex compares like the bytes it encodes.
        if (strcmp($a, $b) > 0) {
            [$a, $b] = [$b, $a];
        }

        if (self::$hasher === null) {
            self::$hasher = new Keccak256Hasher();
        }

        return self::$hasher->hash((string) hex2bin(substr($a, 2) . substr($b, 2)));
    }
}
