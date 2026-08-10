<?php

declare(strict_types=1);

namespace Tracing\Sdk\Canonicalize;

use Tracing\Sdk\Exception\CanonicalizationException;

/**
 * JSON Canonicalization Scheme per RFC 8785 (JCS): decode, then re-serialize
 * with object member names sorted by UTF-16 code unit value, numbers
 * formatted per the ECMAScript Number::toString algorithm (so `1`, `1.0`,
 * and `1e0` all collapse to `1`), and minimal string escaping. Two JSON
 * documents describing the same value produce the exact same canonical
 * byte string, regardless of key order, formatting, or numeric literal form.
 *
 * Decoding uses `json_decode($raw, false)` — objects become stdClass and
 * arrays become PHP arrays — so a JSON object with keys "0","1",... is never
 * mistaken for a JSON array; the two are distinct JSON types with distinct
 * canonical forms even when a naive heuristic can't otherwise tell them apart.
 */
class JsonCanonicalizer implements CanonicalizerInterface
{
    private const STRING_ESCAPE_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS;

    public function canonicalize(string $rawData): string
    {
        $decoded = json_decode($rawData, false, 512);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new CanonicalizationException('Invalid JSON payload: ' . json_last_error_msg());
        }

        return $this->encodeValue($decoded);
    }

    /**
     * @param mixed $value
     */
    private function encodeValue($value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            // A PHP int is always well within +/-1e21, so it's always the
            // plain-decimal case of the number algorithm below — no need to
            // route it through the float formatting logic.
            return (string) $value;
        }

        if (is_float($value)) {
            return $this->encodeNumber($value);
        }

        if (is_string($value)) {
            return $this->encodeString($value);
        }

        if (is_array($value)) {
            return $this->encodeArray($value);
        }

        if ($value instanceof \stdClass) {
            return $this->encodeObject($value);
        }

        throw new CanonicalizationException('Unsupported value encountered while canonicalizing JSON');
    }

    /**
     * @param array<int, mixed> $value
     */
    private function encodeArray(array $value): string
    {
        return '[' . implode(',', array_map([$this, 'encodeValue'], $value)) . ']';
    }

    private function encodeObject(\stdClass $value): string
    {
        $properties = get_object_vars($value);
        $keys = array_keys($properties);

        usort($keys, [$this, 'compareUtf16']);

        $members = [];

        foreach ($keys as $key) {
            $members[] = $this->encodeString((string) $key) . ':' . $this->encodeValue($properties[$key]);
        }

        return '{' . implode(',', $members) . '}';
    }

    private function encodeString(string $value): string
    {
        $encoded = json_encode($value, self::STRING_ESCAPE_FLAGS);

        if ($encoded === false) {
            throw new CanonicalizationException('Failed to encode string while canonicalizing JSON: ' . json_last_error_msg());
        }

        return $encoded;
    }

    /**
     * Formats a float per the ECMAScript `Number::toString` algorithm
     * (ECMA-262 7.1.12.1), which is what RFC 8785 mandates: the shortest
     * decimal digit sequence that round-trips to the same IEEE 754 double,
     * laid out as a plain integer, a decimal, or exponential notation
     * depending on magnitude.
     */
    private function encodeNumber(float $value): string
    {
        if (is_nan($value) || is_infinite($value)) {
            throw new CanonicalizationException('NaN and Infinity cannot be represented in JSON');
        }

        if ($value === 0.0) {
            // Covers -0.0 too (-0.0 === 0.0 in PHP); JCS normalizes both to "0".
            return '0';
        }

        $negative = $value < 0;

        [$digits, $n] = $this->shortestDigitsAndExponent(abs($value));
        $k = strlen($digits);

        if ($k <= $n && $n <= 21) {
            $formatted = $digits . str_repeat('0', $n - $k);
        } elseif (0 < $n && $n <= 21) {
            $formatted = substr($digits, 0, $n) . '.' . substr($digits, $n);
        } elseif (-6 < $n && $n <= 0) {
            $formatted = '0.' . str_repeat('0', -$n) . $digits;
        } else {
            $exponent = $n - 1;
            $mantissa = $k === 1 ? $digits : $digits[0] . '.' . substr($digits, 1);
            $formatted = $mantissa . 'e' . ($exponent >= 0 ? '+' : '-') . abs($exponent);
        }

        return ($negative ? '-' : '') . $formatted;
    }

    /**
     * Returns [$digits, $n] such that the value equals (int) $digits * 10 **
     * ($n - strlen($digits)), with $digits holding no leading or trailing
     * zeros — i.e. the shortest round-trippable digit sequence and its
     * decimal-point position, as required by the ECMAScript algorithm above.
     *
     * PHP's own float-to-string (via json_encode, forcing serialize_precision
     * to -1 for "shortest round-trip" regardless of the host's php.ini) uses
     * the same shortest-round-trip digit sequence as ECMAScript engines do —
     * that sequence is mathematically unique — so this only has to reparse
     * PHP's formatting into digits + exponent, not reimplement dtoa.
     *
     * @return array{0: string, 1: int}
     */
    private function shortestDigitsAndExponent(float $absValue): array
    {
        $previousPrecision = ini_get('serialize_precision');

        try {
            ini_set('serialize_precision', '-1');
            $phpRepr = json_encode($absValue);
        } finally {
            if ($previousPrecision !== false) {
                ini_set('serialize_precision', $previousPrecision);
            }
        }

        if (!is_string($phpRepr) || !preg_match('/^(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/', $phpRepr, $matches)) {
            throw new CanonicalizationException(sprintf('Unexpected float representation: %s', var_export($phpRepr, true)));
        }

        $fractionalDigits = $matches[2] ?? '';
        $exponent = isset($matches[3]) ? (int) $matches[3] : 0;

        $digits = ltrim($matches[1] . $fractionalDigits, '0');
        $pointExponent = $exponent - strlen($fractionalDigits);

        while (substr($digits, -1) === '0' && strlen($digits) > 1) {
            $digits = substr($digits, 0, -1);
            $pointExponent++;
        }

        return [$digits, $pointExponent + strlen($digits)];
    }

    /**
     * Compares two strings by their UTF-16 code unit sequence, per RFC 8785
     * — NOT by UTF-8 byte order. The two disagree for characters outside the
     * Basic Multilingual Plane: astral code points (> U+FFFF) are encoded in
     * UTF-16 as a surrogate pair starting with a unit in D800-DBFF, which is
     * numerically *less* than BMP characters in E000-FFFF, even though the
     * astral code point itself is numerically greater. JCS mandates UTF-16
     * order specifically so canonical output matches native JavaScript
     * string comparison (JS strings are UTF-16 internally).
     */
    private function compareUtf16(string $a, string $b): int
    {
        $aUnits = $this->utf16CodeUnits($a);
        $bUnits = $this->utf16CodeUnits($b);
        $length = min(\count($aUnits), \count($bUnits));

        for ($i = 0; $i < $length; $i++) {
            if ($aUnits[$i] !== $bUnits[$i]) {
                return $aUnits[$i] <=> $bUnits[$i];
            }
        }

        return \count($aUnits) <=> \count($bUnits);
    }

    /**
     * @return array<int, int>
     */
    private function utf16CodeUnits(string $value): array
    {
        $encoded = mb_convert_encoding($value, 'UTF-16BE', 'UTF-8');
        $units = unpack('n*', $encoded);

        return $units === false ? [] : array_values($units);
    }
}
