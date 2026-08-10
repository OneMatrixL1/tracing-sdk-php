# Tracing SDK (PHP)

PHP implementation of the Tracing SDK. Canonicalizes a record and hashes it with **Keccak-256** — that's the SDK's only automatic behavior, and it never touches the network. Sending the result to an Indexer service is an explicit, separate step the caller controls: send a single record or a batch, whenever and however often makes sense.

Requires PHP 7.1+ or 8.x, plus the `json`, `dom`, `libxml`, and `curl` extensions.

## Install

```bash
composer require onematrix/tracing-sdk
```

## Usage

```php
use Tracing\Sdk\TracingSDK;

$sdk = new TracingSDK([
    'endpoint' => 'https://indexer.example.com',
    'dataType' => 'json',      // 'json' | 'xml' | 'raw'
    'auth'     => [
        'type'  => 'apiToken', // 'mTLS' | 'basic' | 'apiToken'
        'token' => 'your-api-token',
    ],
]);

// Hash one record, then decide when to send it.
$entry = $sdk->hash($rawJsonOrXml, $signingTime); // { hash, signingTime }
$sdk->send($entry);                                // POST {endpoint}/api/anchors

// Or hash several and send them together.
$entries = $sdk->hashBatch([
    ['rawData' => $rawA, 'signingTime' => $signingTimeA],
    ['rawData' => $rawB, 'signingTime' => $signingTimeB],
]);
$sdk->sendBatch($entries);                         // POST {endpoint}/api/anchors/batch
```

`send()`/`sendBatch()` throw `Tracing\Sdk\Exception\TransportException` on a failed or rejected request — catch it, retry, queue, batch up before sending, or whatever else suits the caller. The SDK itself has no buffering, timers, or background sending; it's entirely up to you when hashing happens and when (or whether) the result is sent.

### Auth options

```php
// mTLS
'auth' => [
    'type'   => 'mTLS',
    'cert'   => '/path/to/client.crt',
    'key'    => '/path/to/client.key',
    'caCert' => '/path/to/ca.crt',       // optional, verifies the server cert
],

// basic
'auth' => [
    'type'     => 'basic',
    'username' => 'your-username',
    'password' => 'your-password',
],

// apiToken
'auth' => [
    'type'  => 'apiToken',
    'token' => 'your-api-token',
],
```

## Design notes

- **Canonicalization.** JSON is decoded, object keys are sorted recursively (array/list order is preserved), and re-encoded — so two JSON payloads that differ only in key order or whitespace hash identically. XML is canonicalized with `DOMDocument::C14N()` (W3C canonical XML), which normalizes attribute order and insignificant whitespace. External entity resolution is disabled for XML input to prevent XXE, since `rawData` is untrusted. `dataType: 'raw'` skips canonicalization entirely — the input is hashed exactly as given, with no parsing; use it when the caller already guarantees a single deterministic representation.
- **Hashing.** Keccak-256 (the original Keccak, as used by Ethereum — not FIPS-202 SHA3-256), via [`kornrunner/keccak`](https://github.com/kornrunner/php-keccak) rather than a hand-rolled implementation. Output is a `0x`-prefixed hex string.

## Testing

```bash
composer install
vendor/bin/phpunit
```
