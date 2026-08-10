# Tracing SDK (PHP)

PHP implementation of the Tracing SDK (see `../tracing-sdk.md` for the full spec). Canonicalizes a record, hashes it with **Keccak-256**, buffers `{ hash, signingTime }` locally, and sends it to an Indexer service over HTTP. Everything up to the send decision runs in-process with no network calls. When `batchSize` is `0` or `1`, buffering is skipped entirely and each record is sent as soon as it's processed via `POST {endpoint}/api/anchors`; otherwise records accumulate and are sent together via `POST {endpoint}/api/anchors/batch`.

Requires PHP 7.1+ or 8.x, plus the `json`, `dom`, `libxml`, and `curl` extensions.

## Install

```bash
composer require tracing/sdk
```

## Usage

```php
use Tracing\Sdk\TracingSDK;

$sdk = new TracingSDK([
    'endpoint'      => 'https://indexer.example.com',
    'batchSize'     => 20,          // flush once the buffer reaches N records; 0 or 1 = send every record immediately, unbuffered
    'flushInterval' => 5000,        // flush after Δt ms even if batchSize hasn't been reached
    'dataType'      => 'json',      // 'json' | 'xml'
    'auth'          => [
        'type'   => 'apiToken',     // 'mTLS' | 'basic' | 'apiToken'
        'token'  => 'your-api-token',
    ],
]);

$sdk->on('sent', function ($result) {
    // $result = ['statusCode' => 200, 'body' => ..., 'recordCount' => N]
});
$sdk->on('error', function (\Throwable $e) {
    // canonicalization failures, or a failed/rejected HTTP request
});

$sdk->index($rawJsonOrXml, $signingTime);          // single record
$sdk->index([                                       // or a batch in one call
    ['rawData' => $rawA, 'signingTime' => $signingTimeA],
    ['rawData' => $rawB, 'signingTime' => $signingTimeB],
]);

$sdk->flush(); // force an immediate send regardless of batchSize/flushInterval
```

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

- **Canonicalization.** JSON is decoded, object keys are sorted recursively (array/list order is preserved), and re-encoded — so two JSON payloads that differ only in key order or whitespace hash identically. XML is canonicalized with `DOMDocument::C14N()` (W3C canonical XML), which normalizes attribute order and insignificant whitespace. External entity resolution is disabled for XML input to prevent XXE, since `rawData` is untrusted.
- **Hashing.** Keccak-256 (the original Keccak, as used by Ethereum — not FIPS-202 SHA3-256), via [`kornrunner/keccak`](https://github.com/kornrunner/php-keccak) rather than a hand-rolled implementation. Output is a `0x`-prefixed hex string.
- **Buffer.** Only `{ hash, signingTime }` is retained in memory — the original `rawData` is discarded once hashed and never sent to, or stored for, the Indexer.
- **Immediate vs. batched sending.** `batchSize` of `0` or `1` puts the SDK in immediate mode: nothing is buffered, `batchSize`/`flushInterval` don't apply, `flush()`/`tick()` are no-ops, and every record goes out on its own via `POST /api/anchors`. For `batchSize >= 2`, records buffer and go out together via `POST /api/anchors/batch` once `batchSize` or `flushInterval` is reached.
- **Flushing (batched mode).** `index()` is synchronous in PHP (there's no implicit event loop), but it never blocks on the network beyond a flush trigger: after buffering a record it checks whether `batchSize` or `flushInterval` has been reached and sends only then. In long-running workers where `index()` may not be called often enough for the time-based trigger to be noticed, call `$sdk->tick()` periodically. Because most PHP processes are short-lived (a single web request), the SDK also flushes any pending records via `register_shutdown_function` by default — disable this with `'flushOnShutdown' => false` if you manage flushing yourself (e.g. in a long-running worker).
- **Errors.** `flush()` never throws: transport failures are reported via the `error` event so a slow/unreachable Indexer can't take down the caller. Canonicalization/config errors are both emitted as `error` events and thrown from `index()`, since those indicate a caller bug rather than a transient network issue.

## Testing

```bash
composer install
vendor/bin/phpunit
```

Canonicalizer and hasher test cases are not hardcoded here — they're loaded from `../testdata/*.json` at the repo root (`keccak256.json`, `json-canonicalize.json`, `xml-canonicalize.json`). Those files are the language-agnostic source of truth: any future SDK (Node, Python, Go, ...) should load the same fixtures rather than re-deriving expected outputs, so all SDKs stay byte-for-byte compatible.
