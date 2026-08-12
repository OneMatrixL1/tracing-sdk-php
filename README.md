# Tracing SDK (PHP)

PHP implementation of the Tracing SDK. Canonicalizes a record, hashes it with **Keccak-256**, and sends the hash to an Indexer service. Canonicalization and hashing happen inside `send()`/`sendBatch()`, which return the hash alongside the Indexer's response. The SDK has no buffering, timers, or background sending — you decide when to send, one record at a time or a batch. Once a record is anchored, it can be looked up again by its hash.

Requires PHP 7.1+ or 8.x, plus the `json`, `dom`, `libxml`, `curl`, and `mbstring` extensions.

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

// Send one record: canonicalize + hash + POST {endpoint}/api/anchors
$result = $sdk->send($rawJsonOrXml, $signingTime);
// ['hash' => '0x1c8a…', 'response' => ['statusCode' => 200, 'body' => …, 'recordCount' => 1]]

// Or send several together: POST {endpoint}/api/anchors/batch
$results = $sdk->sendBatch([
    ['rawData' => $rawA, 'signingTime' => $signingTimeA],
    ['rawData' => $rawB, 'signingTime' => $signingTimeB],
]);
// [['hash' => '0xaaa…', 'response' => […]], ['hash' => '0xbbb…', 'response' => […]]]

// Look up an already-anchored record by its hash.
$anchor = $sdk->queryByHash($result['hash']);      // GET {endpoint}/api/anchors?hash=...
echo $anchor['txHash'];
```

`send()` returns `['hash' => …, 'response' => ['statusCode', 'body', 'recordCount']]`. `sendBatch()` sends every record in a single request and returns one such entry per input record, in input order — each carries its own `hash` and shares the one `response` of that request.

`send()`/`sendBatch()` throw `Tracing\Sdk\Exception\TransportException` on a failed or rejected request — catch it, retry, queue, batch up before sending, or whatever else suits the caller. They throw `Tracing\Sdk\Exception\ConfigException` when `signingTime` is missing or a batch record lacks `rawData`/`signingTime`, and `Tracing\Sdk\Exception\CanonicalizationException` when `rawData` can't be canonicalized for the configured `dataType`; both are raised before anything is sent.

### Querying an anchor by hash

`queryByHash()` resolves a record's hash to the blockchain transaction that anchored it, via `GET {endpoint}/api/anchors?hash=<hash>`. The hash is URL-encoded for you, and the configured auth is applied exactly as it is for sending.

```php
use Tracing\Sdk\Exception\TransportException;

try {
    $anchor = $sdk->queryByHash('0x1c8a…');
    // ['hash' => '0x1c8a…', 'txHash' => '0x9f42…']
} catch (TransportException $e) {
    // Not yet anchored, unknown hash, or the Indexer was unreachable.
    echo $e->getMessage();
}
```

Returns an array with exactly two keys:

| Key | Description |
| --- | --- |
| `hash` | The record hash that was queried, as echoed back by the Indexer. |
| `txHash` | Hash of the blockchain transaction the record was anchored in. |

It throws `Tracing\Sdk\Exception\ConfigException` when `$hash` is empty, and `Tracing\Sdk\Exception\TransportException` when the request fails, the Indexer answers with a non-2xx status (including the `404` you get for a hash that was never anchored), or the response body isn't a `{ hash, txHash }` object. A hash that hasn't been anchored yet is therefore an exception, not a `null` return — so a lookup that must tolerate "not there yet" belongs in a `try`/`catch`.

Hashing is deterministic, so the same record always yields the same hash — keep the `hash` returned by `send()`/`sendBatch()` and query it whenever you need to.

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

- **Canonicalization.** JSON follows [RFC 8785](https://www.rfc-editor.org/rfc/rfc8785) (the JSON Canonicalization Scheme / JCS): object member names are sorted by UTF-16 code unit value (not byte order — the two disagree for characters outside the Basic Multilingual Plane), numbers are formatted per the ECMAScript `Number::toString` algorithm (so `1`, `1.0`, and `1e0` all canonicalize identically, and `-0` normalizes to `0`), and a JSON object is never mistaken for a JSON array even when its keys happen to be sequential integers starting at 0. XML is canonicalized with `DOMDocument::C14N()` (W3C canonical XML), which normalizes attribute order and insignificant whitespace. External entity resolution is disabled for XML input to prevent XXE, since `rawData` is untrusted. `dataType: 'raw'` skips canonicalization entirely — the input is hashed exactly as given, with no parsing; use it when the caller already guarantees a single deterministic representation.
- **Hashing.** Keccak-256 (the original Keccak, as used by Ethereum — not FIPS-202 SHA3-256), via [`kornrunner/keccak`](https://github.com/kornrunner/php-keccak) rather than a hand-rolled implementation. Output is a `0x`-prefixed hex string.

## Testing

```bash
composer install
vendor/bin/phpunit
```
