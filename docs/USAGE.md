# Tracing SDK (PHP) — Usage Guide

> 🇻🇳 Bản tiếng Việt: [USAGE.vi.md](USAGE.vi.md)

The SDK does three things for every record you hand it: **canonicalize** it, **hash** it with Keccak-256, and **send** the hash to an Indexer service. Once anchored, a record can be looked up by its hash ([§7](#7-querying-an-anchor-by-hash)) and checked against the chain itself ([§8](#8-verifying-an-anchor-against-the-chain)).

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

    // Default options for every send()/sendBatch()/hash()/verify() call.
    // Optional if every call passes its own SendOptions.
    'options'  => new SendOptions(
        'json',                      // dataType
        5000,                        // timeoutMs
        'https://rpc.example.com'    // rpcUrl — only needed for verify()
    ),

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

## 4. Options: data type, timeout & RPC URL

`SendOptions` is an immutable value object carrying three settings — `dataType`, `timeoutMs`, and `rpcUrl`. It can be given as the config default, per call, or both.

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

### 4.3 RPC URL

`rpcUrl` is the JSON-RPC endpoint of a chain node — an archive/full node you trust, your own or a provider's. It is used **only** by `verify()` (see [§8](#8-verifying-an-anchor-against-the-chain)); sending and hashing never touch it, so leave it unset if you never verify.

```php
// As the default for every verify() call.
$sdk = new TracingSDK([
    'endpoint' => 'https://indexer.example.com',
    'options'  => SendOptions::dataType('json')->withRpcUrl('https://rpc.example.com'),
    'auth'     => ['type' => 'apiToken', 'token' => 'your-api-token'],
]);

// For one call only.
$sdk->verify($hash, $txHash, TracingSDK::MODE_TRANSACTION_HASH, SendOptions::rpcUrl('https://other-rpc.example.com'));
```

A per-call `rpcUrl` wins over the config one. Calling `verify()` with neither throws `ConfigException`; an empty string throws when the `SendOptions` is built.

### 4.4 `SendOptions` is immutable

```php
$json = new SendOptions('json');                       // same as SendOptions::dataType('json')
$xml  = $json->withDataType('xml');                    // returns a copy; $json is unchanged
$fast = $json->withTimeoutMs(2000);                    // likewise
$node = $json->withRpcUrl('https://rpc.example.com');  // likewise
```

### 4.5 XML example

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
| `ConfigException` | Missing/invalid config, unsupported `dataType`, `auth.type` or `verify()` mode, a `timeoutMs` of `0` or less, an empty `rpcUrl` or none at all when verifying, missing `signingTime`, a batch record without `rawData`/`signingTime`, empty `hash`, or a `dataHash`/`proof` that is not a 32-byte hex string. |
| `CanonicalizationException` | `rawData` cannot be canonicalized for the chosen data type (e.g. malformed JSON/XML). |
| `TransportException` | The HTTP request failed (network, or it exceeded `timeoutMs` — 10 s by default), the Indexer answered with a non-2xx status, or the RPC node rejected the call / does not know the proof transaction. |

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

## 7. Querying an anchor by hash

`queryByHash()` resolves a record's hash to the on-chain proofs that anchored it, via `GET {endpoint}/api/anchors?hash=<hash>`. The hash is URL-encoded for you, and the configured auth is applied exactly as it is for sending.

```php
use Tracing\Sdk\Exception\TransportException;

try {
    $anchor = $sdk->queryByHash('0x1c8a…');
    // ['hash' => '0x1c8a…', 'proof' => ['0x9f42…', '0x3b07…'], 'proofType' => 'transactionHash']

    foreach ($anchor['proof'] as $proof) {
        echo $proof, PHP_EOL;
    }
} catch (TransportException $e) {
    // Hash not anchored, or the Indexer was unreachable.
    echo $e->getMessage();
}
```

Return shape:

| Key | Description |
| --- | --- |
| `hash` | The record hash queried, as echoed back by the Indexer. |
| `proof` | Every on-chain proof the record was anchored by — with `proofType: 'transactionHash'`, the transaction hashes. A record can be anchored more than once, so always iterate rather than assuming a single element. |
| `proofType` | How each entry in `proof` should be resolved on chain. Pass it straight to `verify()` as its `$mode` (see [§8](#8-verifying-an-anchor-against-the-chain)); today the Indexer only reports `'transactionHash'`. Defaults to `'transactionHash'` if an older Indexer omits the field. |

Errors: `ConfigException` for an empty `$hash`; `TransportException` for a failed request, a non-2xx status (including the `404` for a hash that was never anchored), or a body that is not a `{ hash, proof }` object. A not-yet-anchored hash is therefore an exception, not a `null` return — wrap the call in `try`/`catch` when "not there yet" is a normal outcome for you.

Hashing is deterministic, so the same record always yields the same hash: keep the one returned by `send()`/`sendBatch()`, or re-derive it at any time from the original record with `$sdk->hash($rawData)`, and query it whenever you need to.

---

## 8. Verifying an anchor against the chain

`queryByHash()` tells you what the Indexer says. `verify()` checks that claim against the chain itself, over an RPC endpoint **you** choose — so a compromised or mistaken Indexer cannot vouch for itself.

```php
use Tracing\Sdk\Exception\TransportException;
use Tracing\Sdk\SendOptions;
use Tracing\Sdk\TracingSDK;

$sdk = new TracingSDK([
    'endpoint' => 'https://indexer.example.com',
    'options'  => new SendOptions('json', 5000, 'https://rpc.example.com'),
    'auth'     => ['type' => 'apiToken', 'token' => 'your-api-token'],
]);

$hash   = $sdk->hash($rawData);            // or the hash returned by send()
$anchor = $sdk->queryByHash($hash);        // ['hash' => …, 'proof' => [...], 'proofType' => …]

foreach ($anchor['proof'] as $proof) {
    // proofType tells verify() how to resolve the proof — no need to hardcode a mode.
    if ($sdk->verify($anchor['hash'], $proof, $anchor['proofType'])) {
        echo "anchored on chain in {$proof}", PHP_EOL;
        break;
    }
}
```

### What it does

1. Calls `eth_getTransactionReceipt` with the proof transaction hash on the configured `rpcUrl` — a plain JSON-RPC 2.0 POST, with no Indexer auth attached.
2. Walks the receipt's logs and keeps the ones whose `topics[0]` equals `keccak256("Anchored(bytes32,uint64)")`.
3. ABI-decodes each of those as `Anchored(bytes32 dataHash, uint64 signingTime)`.
4. Returns `true` as soon as one decoded `dataHash` equals the hash you passed, `false` if none does.

Both event layouts decode: an indexed `bytes32` is read from `topics[1]`, a non-indexed one from the log data. Comparison is on normalized hashes, so case and a missing `0x` prefix do not matter. Logs from other events in the same transaction are ignored.

### Signature

```php
verify(
    string $dataHash,                                  // the record hash
    string $proof,                                     // the on-chain proof
    string $mode = TracingSDK::MODE_TRANSACTION_HASH,   // how to resolve the proof
    ?SendOptions $options = null                        // per-call rpcUrl / timeoutMs
): bool
```

| Parameter | Description |
| --- | --- |
| `$dataHash` | The record's Keccak-256 hash — from `hash()`, `send()`, or `queryByHash()`. Must be 32 bytes of hex. |
| `$proof` | The on-chain evidence to check the hash against. With the current mode this is a transaction hash, e.g. one element of `queryByHash()`'s `proof`. |
| `$mode` | How `$proof` should be interpreted — pass `queryByHash()`'s `proofType` here. Only `TracingSDK::MODE_TRANSACTION_HASH` (`'transactionHash'`) exists today; the parameter is there so other proof kinds can be added without breaking the signature. Anything else throws `ConfigException`. |
| `$options` | Per-call `rpcUrl` and `timeoutMs`. Falls back to the config `options`. |

### `true`, `false`, or an exception

| Outcome | Meaning |
| --- | --- |
| `true` | The transaction really does contain an `Anchored` event carrying this data hash. |
| `false` | The transaction exists, but no `Anchored` event in it carries this hash — the record was not anchored by this transaction. |
| `ConfigException` | Bad input or config: malformed `dataHash`/`proof`, unsupported `$mode`, or no `rpcUrl` anywhere. |
| `TransportException` | The RPC endpoint was unreachable, returned a non-2xx status or a JSON-RPC error, or does not know the transaction (an unmined, dropped, or wrong-chain hash — the node returns a `null` receipt). |

A `false` is a real answer about a real transaction; a missing transaction is an exception, because "the node has never heard of it" says nothing about whether the record was anchored.

```php
try {
    $verified = $sdk->verify($hash, $txHash);
} catch (TransportException $e) {
    // RPC unreachable, or the tx is not mined yet — retry later, don't treat as "not anchored".
    $verified = null;
}
```

### Notes

- Pick an RPC node on the same chain the Indexer anchors to; a healthy node on the wrong chain simply does not know the transaction, which surfaces as `TransportException`.
- Use a node with enough history for the block you are checking. Some public endpoints prune old receipts.
- `timeoutMs` applies to the RPC request the same way it does to Indexer requests, defaulting to 10 000 ms.
- `rpcUrl` may embed a provider API key; it is sent to that URL only, never to the Indexer.

---

## 9. Runnable examples

`example/php/` contains complete scripts:

| File | Shows |
| --- | --- |
| [`single-send-example.php`](../example/php/single-send-example.php) | One JSON record with `send()` |
| [`example.php`](../example/php/example.php) | Several JSON records in one request with `sendBatch()` |
| [`xml-example.php`](../example/php/xml-example.php) | The batch flow with `SendOptions::dataType('xml')` |
| [`query-example.php`](../example/php/query-example.php) | Sending a record, then looking the anchor up with `queryByHash()` |
| [`verify-example.php`](../example/php/verify-example.php) | Query, then `verify()` each proof transaction against an RPC node |

Each script points at `http://localhost:3000` with a placeholder token — edit `endpoint` and `auth` at the top (plus `rpcUrl` for the verify example), then run:

```bash
composer install
php example/php/single-send-example.php
```

---

## 10. API reference

```php
new TracingSDK(array $config)

send(string $rawData, mixed $signingTime, ?SendOptions $options = null): array
    // ['hash' => string, 'response' => ['statusCode' => int, 'body' => mixed, 'recordCount' => int]]

sendBatch(array $records, ?SendOptions $options = null): array
    // [['hash' => string, 'response' => [...]], ...] — one entry per input record

hash(string $rawData, ?SendOptions $options = null): string
    // '0x…' Keccak-256 hex

queryByHash(string $hash, ?SendOptions $options = null): array
    // ['hash' => string, 'proof' => string[], 'proofType' => string]

verify(string $dataHash, string $proof, string $mode = TracingSDK::MODE_TRANSACTION_HASH, ?SendOptions $options = null): bool
    // true when $proof's logs contain Anchored(bytes32,uint64) with $dataHash

TracingSDK::MODE_TRANSACTION_HASH   // 'transactionHash' — the only verify mode today
```

`SendOptions`:

```php
new SendOptions(?string $dataType = null, ?int $timeoutMs = null, ?string $rpcUrl = null)
SendOptions::dataType(string $dataType): SendOptions
SendOptions::timeoutMs(int $timeoutMs): SendOptions
SendOptions::rpcUrl(string $rpcUrl): SendOptions
$options->getDataType(): ?string
$options->getTimeoutMs(): ?int
$options->getRpcUrl(): ?string
$options->withDataType(?string $dataType): SendOptions   // returns a copy
$options->withTimeoutMs(?int $timeoutMs): SendOptions     // returns a copy
$options->withRpcUrl(?string $rpcUrl): SendOptions        // returns a copy
```

Indexer endpoints used: `POST /api/anchors`, `POST /api/anchors/batch`, `GET /api/anchors?hash=…`. `verify()` additionally calls `eth_getTransactionReceipt` on `rpcUrl`. Request timeout is `timeoutMs`, defaulting to `CurlHttpTransport::DEFAULT_TIMEOUT_MS` (10 000 ms).
