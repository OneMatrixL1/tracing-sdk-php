# Tracing SDK (PHP)

PHP implementation of the Tracing SDK. Canonicalizes a record, hashes it with **Keccak-256**, and sends the hash to an Indexer service. Canonicalization and hashing happen inside `send()`/`sendBatch()`, which return the hash alongside the Indexer's response. The SDK has no buffering, timers, or background sending — you decide when to send, one record at a time or a batch. Once a record is anchored, it can be looked up again by its hash and verified against the chain through your own RPC node.

Requires PHP 7.1+ or 8.x, plus the `json`, `dom`, `libxml`, `curl`, and `mbstring` extensions.

## Install

```bash
composer require onematrix/tracing-sdk
```

## Usage

```php
use Tracing\Sdk\SendOptions;
use Tracing\Sdk\TracingSDK;

$sdk = new TracingSDK([
    'endpoint' => 'https://indexer.example.com',
    'options'  => SendOptions::dataType('json'), // default: 'json' | 'xml' | 'raw'
    'auth'     => [
        'type'  => 'apiToken',                   // 'mTLS' | 'basic' | 'apiToken'
        'token' => 'your-api-token',
    ],
]);

// Canonicalize + hash + POST {endpoint}/api/anchors
$result = $sdk->send($rawJson, time());
// ['hash' => '0x1c8a…', 'response' => ['statusCode' => 200, 'body' => …, 'recordCount' => 1]]
```

That is the whole happy path for anchoring. Everything else — batching, XML and raw data, timeouts, the auth types, looking an anchor up by hash, and verifying it against the chain — is covered in the usage guide:

- **[docs/USAGE.md](docs/USAGE.md)** — full usage guide (English)
- **[docs/USAGE.vi.md](docs/USAGE.vi.md)** — bản tiếng Việt
- **[`example/php/`](example/php/)** — runnable scripts, including [`verify-example.php`](example/php/verify-example.php) for the query-then-verify flow

## Design notes

- **Canonicalization.** JSON follows [RFC 8785](https://www.rfc-editor.org/rfc/rfc8785) (the JSON Canonicalization Scheme / JCS): object member names are sorted by UTF-16 code unit value (not byte order — the two disagree for characters outside the Basic Multilingual Plane), numbers are formatted per the ECMAScript `Number::toString` algorithm (so `1`, `1.0`, and `1e0` all canonicalize identically, and `-0` normalizes to `0`), and a JSON object is never mistaken for a JSON array even when its keys happen to be sequential integers starting at 0. XML is canonicalized with [Exclusive XML Canonicalization 1.0](https://www.w3.org/TR/xml-exc-c14n/) (`http://www.w3.org/2001/10/xml-exc-c14n#`) without comments, via `DOMDocument::C14N(true)`, which normalizes attribute order and insignificant whitespace and emits only the namespace declarations actually used by the document — a declared-but-unused `xmlns` does not affect the hash. Namespace *prefixes* are still significant: Exclusive 1.0 has no prefix rewriting (that is a Canonical XML 2.0 feature, which libxml does not implement), so re-serializing a document with different prefixes changes its hash. External entity resolution is disabled for XML input to prevent XXE, since `rawData` is untrusted. `dataType: 'raw'` skips canonicalization entirely — the input is hashed exactly as given, with no parsing; use it when the caller already guarantees a single deterministic representation.
- **Hashing.** Keccak-256 (the original Keccak, as used by Ethereum — not FIPS-202 SHA3-256), via [`kornrunner/keccak`](https://github.com/kornrunner/php-keccak) rather than a hand-rolled implementation. Output is a `0x`-prefixed hex string.
- **Verification.** `verify()` trusts nothing but the chain: it reads the proof transaction's receipt straight from an RPC endpoint you configure, decodes the logs whose `topics[0]` is `keccak256("Anchored(bytes32,uint64)")`, and compares the event's `bytes32` argument with the record hash. The Indexer is never asked to vouch for itself.

## Testing

```bash
composer install
vendor/bin/phpunit
```
