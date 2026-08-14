# Tracing SDK (PHP) — Usage Guide

> 🇻🇳 Bản tiếng Việt: [USAGE.vi.md](USAGE.vi.md)

The SDK does three things for every record you hand it: **canonicalize** it, **hash** it with Keccak-256, and **send** the hash to an Indexer service.

> **Query by hash is not ready yet.** `queryByHash()` exists in the code, but the Indexer endpoint it depends on (`GET /api/anchors?hash=...`) is not available for use — do not build on it yet. See [Query by hash — not ready](#7-query-by-hash--not-ready-yet).

---

## 1. Requirements & install

- PHP **7.1+** or **8.x**
- Extensions: `json`, `dom`, `libxml`, `curl`, `mbstring`

```bash
composer require onematrix/tracing-sdk
```

---

## 2. Creating the client

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Tracing\Sdk\SendOptions;
use Tracing\Sdk\TracingSDK;

$sdk = new TracingSDK([
    // Base URL of your Indexer. Paths are appended by the SDK.
    'endpoint' => 'https://indexer.example.com',

    // Default options for every send()/sendBatch()/hash() call. Optional
    // if every call passes its own SendOptions.
    'options'  => new SendOptions('json', 5000), // dataType, timeoutMs

    // Required.
    'auth'     => [
        'type'  => 'apiToken',
        'token' => 'your-api-token',
    ],
]);
```

| Config key | Required | Description |
| --- | --- | --- |
| `endpoint` | yes | Indexer base URL. A trailing `/` is trimmed. |
| `auth` | yes | Auth config — see [Authentication](#5-authentication). |
| `options` | no | A `SendOptions` instance used as the default for every call. |

Invalid or missing config throws `Tracing\Sdk\Exception\ConfigException` **at construction time**, so a bad `dataType` or a missing certificate file fails immediately rather than on the first send.

---

## 3. Sending records

### 3.1 One record — `send()`

`send()` canonicalizes, hashes, and POSTs `{endpoint}/api/anchors`.

```php
use Tracing\Sdk\Exception\TransportException;

$rawData     = json_encode(['orderId' => 1, 'amount' => 250000]);
$signingTime = time();

try {
    $result = $sdk->send($rawData, $signingTime);

    echo $result['hash'];                          // 0x1c8a…
    echo $result['response']['statusCode'];        // 200
    echo $result['response']['recordCount'];       // 1
    var_dump($result['response']['body']);         // decoded JSON body, or the raw string
} catch (TransportException $e) {
    // Network failure, or the Indexer answered with a non-2xx status.
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
}
```

Return shape:

```php
[
    'hash'     => '0x1c8a…',
    'response' => [
        'statusCode'  => 200,
        'body'        => /* decoded JSON, or the raw string if not JSON */,
        'recordCount' => 1,
    ],
]
```

`signingTime` is passed through to the Indexer untouched — whatever your Indexer expects (Unix timestamp, ISO-8601 string) is what you should send. It may not be `null`.

### 3.2 Several records — `sendBatch()`

`sendBatch()` hashes every record, then POSTs them all in **one** request to `{endpoint}/api/anchors/batch`.

```php
$results = $sdk->sendBatch([
    ['rawData' => json_encode(['orderId' => 1]), 'signingTime' => time()],
    ['rawData' => json_encode(['orderId' => 2]), 'signingTime' => time()],
    ['rawData' => json_encode(['orderId' => 3]), 'signingTime' => time()],
]);

foreach ($results as $i => $r) {
    printf("#%d %s -> HTTP %d\n", $i, $r['hash'], $r['response']['statusCode']);
}
```

- One entry is returned per input record, **in input order**.
- Each entry carries its own `hash`, and all entries share the single `response` of that one request (`recordCount` = number of records sent).
- Every record in a batch is canonicalized with the **same** data type. Mixed types → separate calls.
- Each record must have both `rawData` and `signingTime`, otherwise `ConfigException` is thrown *before* anything is sent.

### 3.3 Hash without sending — `hash()`

Useful for pre-computing a hash, deduplicating, or storing it locally before deciding to anchor.

```php
$hash = $sdk->hash(json_encode(['orderId' => 1]));                       // config default type
$hash = $sdk->hash($xmlString, SendOptions::dataType('xml'));            // per-call type
```

Hashing is deterministic: the same record always produces the same hash, on any machine, in any SDK language.

---

## 4. Options: data type & timeout

`SendOptions` is an immutable value object carrying two settings — `dataType` and `timeoutMs`. It can be given as the config default, per call, or both.

### 4.1 Data types & canonicalization

`dataType` decides how a record is normalized before hashing.

| Value | Behaviour |
| --- | --- |
| `json` | [RFC 8785](https://www.rfc-editor.org/rfc/rfc8785) JSON Canonicalization Scheme — keys sorted by UTF-16 code unit, numbers normalized per ECMAScript `Number::toString` (`1`, `1.0`, `1e0` are identical; `-0` → `0`). |
| `xml` | [Exclusive XML Canonicalization 1.0](https://www.w3.org/TR/xml-exc-c14n/) (`http://www.w3.org/2001/10/xml-exc-c14n#`), without comments, via `DOMDocument::C14N(true)` — normalizes attribute order and insignificant whitespace, and keeps only the namespace declarations the document actually uses. Namespace prefixes remain significant (Exclusive 1.0 has no prefix rewriting). External entity resolution is disabled (XXE protection). |
| `raw` | No parsing at all — the bytes you pass are hashed exactly as given. Use when the caller already guarantees one deterministic representation. |

### Resolution order

```php
// 1. Config default
$sdk->send($rawJson, $signingTime);

// 2. Per-call override — wins over the config default
$sdk->send($rawXml, $signingTime, SendOptions::dataType('xml'));
$sdk->sendBatch($xmlRecords, SendOptions::dataType('xml'));
```

If neither the call nor the config supplies a `dataType`, a `ConfigException` is thrown.

### 4.2 Request timeout

`timeoutMs` caps how long a single HTTP request to the Indexer may take, in milliseconds. It defaults to **10 000 ms (10 s)** and covers the whole request, not just connecting.

```php
// As the default for every request this client makes.
$sdk = new TracingSDK([
    'endpoint' => 'https://indexer.example.com',
    'options'  => new SendOptions('json', 5000),   // dataType, timeoutMs
    'auth'     => ['type' => 'apiToken', 'token' => 'your-api-token'],
]);

// For one call only — e.g. a large batch that deserves more headroom.
$sdk->sendBatch($records, SendOptions::timeoutMs(30000));
$sdk->send($rawXml, time(), SendOptions::dataType('xml')->withTimeoutMs(3000));
```

A per-call `timeoutMs` wins over the config one; a call that supplies no `timeoutMs` keeps the config default. Unlike `dataType`, a timeout is never required — omit it everywhere and every request uses the 10 s default. A value of `0` or less throws `ConfigException`, raised when the `SendOptions` is built. Exceeding the timeout surfaces as `TransportException`.

### 4.3 `SendOptions` is immutable

```php
$json = new SendOptions('json');           // same as SendOptions::dataType('json')
$xml  = $json->withDataType('xml');        // returns a copy; $json is unchanged
$fast = $json->withTimeoutMs(2000);        // likewise
```

### 4.4 XML example

```php
$xml = <<<XML
<order>
  <id>1</id>
  <amount>250000</amount>
</order>
XML;

$result = $sdk->send($xml, time(), SendOptions::dataType('xml'));
```

---

## 5. Authentication

```php
// API token — sent as the "X-API-Key" header.
'auth' => [
    'type'  => 'apiToken',
    'token' => 'your-api-token',
],

// HTTP Basic — sent as "Authorization: Basic <base64>".
'auth' => [
    'type'     => 'basic',
    'username' => 'your-username',
    'password' => 'your-password',
],

// Mutual TLS — client certificate presented during the TLS handshake.
'auth' => [
    'type'       => 'mTLS',
    'cert'       => '/path/to/client.crt',
    'key'        => '/path/to/client.key',
    'caCert'     => '/path/to/ca.crt',   // optional: verify the server certificate
    'passphrase' => 'key-passphrase',    // optional: encrypted private key
],
```

With mTLS the SDK always enables peer and host verification (`CURLOPT_SSL_VERIFYPEER`, `CURLOPT_SSL_VERIFYHOST = 2`). Certificate paths are checked when the SDK is constructed; a missing file throws `ConfigException`.

---

## 6. Error handling

All SDK exceptions extend `Tracing\Sdk\Exception\TracingSdkException` (itself a `RuntimeException`), so a single `catch` can cover everything.

| Exception | Raised when |
| --- | --- |
| `ConfigException` | Missing/invalid config, unsupported `dataType` or `auth.type`, a `timeoutMs` of `0` or less, missing `signingTime`, a batch record without `rawData`/`signingTime`, empty `hash`. |
| `CanonicalizationException` | `rawData` cannot be canonicalized for the chosen data type (e.g. malformed JSON/XML). |
| `TransportException` | The HTTP request failed (network, or it exceeded `timeoutMs` — 10 s by default), or the Indexer answered with a non-2xx status. |

`ConfigException` and `CanonicalizationException` are raised **before** anything leaves the process, so a rejected call never half-sends a batch.

```php
use Tracing\Sdk\Exception\CanonicalizationException;
use Tracing\Sdk\Exception\ConfigException;
use Tracing\Sdk\Exception\TransportException;

try {
    $result = $sdk->send($rawData, time());
} catch (ConfigException | CanonicalizationException $e) {
    // Bad input — retrying the same payload will not help.
    fwrite(STDERR, '[input] ' . $e->getMessage() . PHP_EOL);
} catch (TransportException $e) {
    // Transient — safe to retry or queue for later.
    fwrite(STDERR, '[transport] ' . $e->getMessage() . PHP_EOL);
}
```

### Retrying

The SDK does not retry on your behalf. Because hashing is deterministic and `send()` is a plain POST, retrying with the same payload re-sends the same hash:

```php
$attempt = 0;

while (true) {
    try {
        $result = $sdk->send($rawData, $signingTime);
        break;
    } catch (TransportException $e) {
        if (++$attempt >= 3) {
            throw $e;
        }
        sleep(2 ** $attempt); // simple backoff
    }
}
```

---

## 7. Query by hash — **not ready yet**

> **Status: not available.** The `GET /api/anchors?hash=...` endpoint this method calls is not ready on the Indexer side, so `queryByHash()` must not be relied upon in production code yet. The method and the example script (`example/php/query-example.php`) are shipped so the interface can be reviewed, but calling it today will fail against a live Indexer. This section documents the *intended* behaviour and may still change.

Planned usage:

```php
use Tracing\Sdk\Exception\TransportException;

try {
    $anchor = $sdk->queryByHash('0x1c8a…');
    // ['hash' => '0x1c8a…', 'txHashes' => ['0x9f42…', '0x3b07…']]

    foreach ($anchor['txHashes'] as $txHash) {
        echo $txHash, PHP_EOL;
    }
} catch (TransportException $e) {
    // Endpoint unavailable, hash not anchored, or the Indexer was unreachable.
    echo $e->getMessage();
}
```

Planned return shape:

| Key | Description |
| --- | --- |
| `hash` | The record hash queried, as echoed back by the Indexer. |
| `txHashes` | Every blockchain transaction the record was anchored in. A record can be anchored more than once, so always iterate rather than assuming a single element. |

Planned errors: `ConfigException` for an empty `$hash`; `TransportException` for a failed request, a non-2xx status (including the `404` for a hash that was never anchored), or a body that is not a `{ hash, txHashes }` object. A not-yet-anchored hash is an exception, not a `null` return.

**In the meantime:** keep the `hash` returned by `send()`/`sendBatch()` in your own storage. Hashing is deterministic, so you can also re-derive it at any time from the original record with `$sdk->hash($rawData)` — no lookup needed to prove the two match.

---

## 8. Runnable examples

`example/php/` contains complete scripts:

| File | Shows |
| --- | --- |
| [`single-send-example.php`](../example/php/single-send-example.php) | One JSON record with `send()` |
| [`example.php`](../example/php/example.php) | Several JSON records in one request with `sendBatch()` |
| [`xml-example.php`](../example/php/xml-example.php) | The batch flow with `SendOptions::dataType('xml')` |
| [`query-example.php`](../example/php/query-example.php) | `queryByHash()` — **endpoint not ready yet**, see §7 |

Each script points at `http://localhost:3000` with a placeholder token — edit `endpoint` and `auth` at the top, then run:

```bash
composer install
php example/php/single-send-example.php
```

---

## 9. API reference

```php
new TracingSDK(array $config)

send(string $rawData, mixed $signingTime, ?SendOptions $options = null): array
    // ['hash' => string, 'response' => ['statusCode' => int, 'body' => mixed, 'recordCount' => int]]

sendBatch(array $records, ?SendOptions $options = null): array
    // [['hash' => string, 'response' => [...]], ...] — one entry per input record

hash(string $rawData, ?SendOptions $options = null): string
    // '0x…' Keccak-256 hex

queryByHash(string $hash, ?SendOptions $options = null): array   // NOT READY — see §7
    // ['hash' => string, 'txHashes' => string[]]
```

`SendOptions`:

```php
new SendOptions(?string $dataType = null, ?int $timeoutMs = null)
SendOptions::dataType(string $dataType): SendOptions
SendOptions::timeoutMs(int $timeoutMs): SendOptions
$options->getDataType(): ?string
$options->getTimeoutMs(): ?int
$options->withDataType(?string $dataType): SendOptions   // returns a copy
$options->withTimeoutMs(?int $timeoutMs): SendOptions    // returns a copy
```

HTTP endpoints used: `POST /api/anchors`, `POST /api/anchors/batch`, and (once ready) `GET /api/anchors?hash=…`. Request timeout is `timeoutMs`, defaulting to `CurlHttpTransport::DEFAULT_TIMEOUT_MS` (10 000 ms).
