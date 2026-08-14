# Tracing SDK (PHP)

PHP implementation of the Tracing SDK. Canonicalizes a record, hashes it with **Keccak-256**, and sends the hash to an Indexer service. Canonicalization and hashing happen inside `send()`/`sendBatch()`, which return the hash alongside the Indexer's response. The SDK has no buffering, timers, or background sending — you decide when to send, one record at a time or a batch. Once a record is anchored, it can be looked up again by its hash.

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
echo $anchor['txHashes'][0];
```

### Choosing the data type

`dataType` decides how a record is canonicalized before hashing. It travels in a `SendOptions` object, which can be set as the config default, per call, or both:

```php
use Tracing\Sdk\SendOptions;

// Uses the config default.
$sdk->send($rawJson, $signingTime);

// Overrides it for this call only.
$sdk->send($rawXml, $signingTime, SendOptions::dataType('xml'));
$sdk->sendBatch($xmlRecords, SendOptions::dataType('xml'));
```

A per-call `SendOptions` wins over the config one; when neither supplies a `dataType`, the call throws `ConfigException`. That makes `options` optional in the constructor — omit it if every call passes its own. Each `sendBatch()` call canonicalizes all of its records with one data type, so send mixed types as separate calls.

`SendOptions` is immutable: `new SendOptions('json')` and `SendOptions::dataType('json')` are equivalent, and `withDataType()` returns a modified copy rather than mutating the original.

`send()` returns `['hash' => …, 'response' => ['statusCode', 'body', 'recordCount']]`. `sendBatch()` sends every record in a single request and returns one such entry per input record, in input order — each carries its own `hash` and shares the one `response` of that request.

`send()`/`sendBatch()` throw `Tracing\Sdk\Exception\TransportException` on a failed or rejected request — catch it, retry, queue, batch up before sending, or whatever else suits the caller. They throw `Tracing\Sdk\Exception\ConfigException` when `signingTime` is missing or a batch record lacks `rawData`/`signingTime`, and `Tracing\Sdk\Exception\CanonicalizationException` when `rawData` can't be canonicalized for the chosen `dataType`; both are raised before anything is sent.

### Runnable examples

`example/php/` holds complete, runnable scripts — the quickest way to see the whole flow end to end:

| File | What it shows |
| --- | --- |
| [`example/php/single-send-example.php`](example/php/single-send-example.php) | Sending one JSON record with `send()` |
| [`example/php/example.php`](example/php/example.php) | Sending several JSON records in one request with `sendBatch()` |
| [`example/php/xml-example.php`](example/php/xml-example.php) | The same batch flow with `SendOptions::dataType('xml')` |
| [`example/php/query-example.php`](example/php/query-example.php) | Sending a record, then looking the anchor up with `queryByHash()` |

Each script points at `http://localhost:3000` with a placeholder API token — edit the `endpoint` and `auth` values at the top to match your Indexer, then run:

```bash
composer install
php example/php/single-send-example.php
```

### Querying an anchor by hash

`queryByHash()` resolves a record's hash to the blockchain transactions that anchored it, via `GET {endpoint}/api/anchors?hash=<hash>`. The hash is URL-encoded for you, and the configured auth is applied exactly as it is for sending.

```php
use Tracing\Sdk\Exception\TransportException;

try {
    $anchor = $sdk->queryByHash('0x1c8a…');
    // ['hash' => '0x1c8a…', 'txHashes' => ['0x9f42…', '0x3b07…']]
} catch (TransportException $e) {
    // Not yet anchored, unknown hash, or the Indexer was unreachable.
    echo $e->getMessage();
}
```

Returns an array with exactly two keys:

| Key | Description |
| --- | --- |
| `hash` | The record hash that was queried, as echoed back by the Indexer. |
| `txHashes` | List of blockchain transactions the record was anchored in. The same record can be anchored more than once, so this is always a list — iterate it rather than assuming a single element. |

It throws `Tracing\Sdk\Exception\ConfigException` when `$hash` is empty, and `Tracing\Sdk\Exception\TransportException` when the request fails, the Indexer answers with a non-2xx status (including the `404` you get for a hash that was never anchored), or the response body isn't a `{ hash, txHashes }` object. A hash that hasn't been anchored yet is therefore an exception, not a `null` return — so a lookup that must tolerate "not there yet" belongs in a `try`/`catch`.

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

- **Canonicalization.** JSON follows [RFC 8785](https://www.rfc-editor.org/rfc/rfc8785) (the JSON Canonicalization Scheme / JCS): object member names are sorted by UTF-16 code unit value (not byte order — the two disagree for characters outside the Basic Multilingual Plane), numbers are formatted per the ECMAScript `Number::toString` algorithm (so `1`, `1.0`, and `1e0` all canonicalize identically, and `-0` normalizes to `0`), and a JSON object is never mistaken for a JSON array even when its keys happen to be sequential integers starting at 0. XML is canonicalized with [Exclusive XML Canonicalization 1.0](https://www.w3.org/TR/xml-exc-c14n/) (`http://www.w3.org/2001/10/xml-exc-c14n#`) without comments, via `DOMDocument::C14N(true)`, which normalizes attribute order and insignificant whitespace and emits only the namespace declarations actually used by the document — a declared-but-unused `xmlns` does not affect the hash. Namespace *prefixes* are still significant: Exclusive 1.0 has no prefix rewriting (that is a Canonical XML 2.0 feature, which libxml does not implement), so re-serializing a document with different prefixes changes its hash. External entity resolution is disabled for XML input to prevent XXE, since `rawData` is untrusted. `dataType: 'raw'` skips canonicalization entirely — the input is hashed exactly as given, with no parsing; use it when the caller already guarantees a single deterministic representation.
- **Hashing.** Keccak-256 (the original Keccak, as used by Ethereum — not FIPS-202 SHA3-256), via [`kornrunner/keccak`](https://github.com/kornrunner/php-keccak) rather than a hand-rolled implementation. Output is a `0x`-prefixed hex string.

## Testing

```bash
composer install
vendor/bin/phpunit
```
