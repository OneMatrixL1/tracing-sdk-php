# Tracing SDK (PHP) — Hướng dẫn sử dụng

> 🇬🇧 English version: [USAGE.md](USAGE.md)

Với mỗi bản ghi bạn đưa vào, SDK làm ba việc: **chuẩn hoá (canonicalize)**, **băm (hash)** bằng Keccak-256, và **gửi** hash tới dịch vụ Indexer. Sau khi đã được anchor, bản ghi có thể được tra cứu lại theo hash ([§7](#7-query-anchor-theo-hash)) và đối chiếu trực tiếp với blockchain ([§8](#8-xác-minh-anchor-trên-blockchain)).

---

## 1. Yêu cầu & cài đặt

- PHP **7.1+** hoặc **8.x**
- Extension: `json`, `dom`, `libxml`, `curl`, `mbstring`

```bash
composer require onematrix/tracing-sdk
```

---

## 2. Khởi tạo client

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Tracing\Sdk\SendOptions;
use Tracing\Sdk\TracingSDK;

$sdk = new TracingSDK([
    // URL gốc của Indexer. SDK sẽ tự nối đường dẫn phía sau.
    'endpoint' => 'https://indexer.example.com',

    // Tuỳ chọn mặc định cho mọi lần gọi send()/sendBatch()/hash()/verify().
    // Có thể bỏ qua nếu mọi lần gọi đều tự truyền SendOptions.
    'options'  => new SendOptions(
        'json',                      // dataType
        5000,                        // timeoutMs
        'https://rpc.example.com'    // rpcUrl — chỉ cần cho verify()
    ),

    // Bắt buộc.
    'auth'     => [
        'type'  => 'apiToken',
        'token' => 'your-api-token',
    ],
]);
```

| Khoá cấu hình | Bắt buộc | Mô tả |
| --- | --- | --- |
| `endpoint` | có | URL gốc của Indexer. Dấu `/` ở cuối sẽ được cắt bỏ. |
| `auth` | có | Cấu hình xác thực — xem [Xác thực](#5-xác-thực). |
| `options` | không | Một đối tượng `SendOptions` dùng làm mặc định cho mọi lần gọi. |

Cấu hình sai hoặc thiếu sẽ ném `Tracing\Sdk\Exception\ConfigException` **ngay lúc khởi tạo**, nên `dataType` không hợp lệ hay file chứng thư không tồn tại sẽ lỗi ngay, không đợi tới lần gửi đầu tiên.

---

## 3. Gửi bản ghi

### 3.1 Một bản ghi — `send()`

`send()` chuẩn hoá, băm, rồi POST tới `{endpoint}/api/anchors`.

```php
use Tracing\Sdk\Exception\TransportException;

$rawData     = json_encode(['orderId' => 1, 'amount' => 250000]);
$signingTime = time();

try {
    $result = $sdk->send($rawData, $signingTime);

    echo $result['hash'];                          // 0x1c8a…
    echo $result['response']['statusCode'];        // 200
    echo $result['response']['recordCount'];       // 1
    var_dump($result['response']['body']);         // body JSON đã decode, hoặc chuỗi thô
} catch (TransportException $e) {
    // Lỗi mạng, hoặc Indexer trả về status ngoài dải 2xx.
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
}
```

Cấu trúc giá trị trả về:

```php
[
    'hash'     => '0x1c8a…',
    'response' => [
        'statusCode'  => 200,
        'body'        => /* JSON đã decode, hoặc chuỗi thô nếu không phải JSON */,
        'recordCount' => 1,
    ],
]
```

`signingTime` được chuyển thẳng tới Indexer, SDK không xử lý gì thêm — Indexer của bạn cần định dạng nào (Unix timestamp, chuỗi ISO-8601) thì truyền đúng định dạng đó. Không được để `null`.

### 3.2 Nhiều bản ghi — `sendBatch()`

`sendBatch()` băm tất cả bản ghi rồi POST **một** request duy nhất tới `{endpoint}/api/anchors/batch`.

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

- Trả về một phần tử cho mỗi bản ghi đầu vào, **đúng thứ tự đầu vào**.
- Mỗi phần tử mang `hash` riêng của nó, còn `response` là chung của một request duy nhất (`recordCount` = số bản ghi đã gửi).
- Mọi bản ghi trong cùng một batch được chuẩn hoá bằng **cùng một** `dataType`. `dataType` khác nhau → tách thành nhiều lần gọi.
- Mỗi bản ghi phải có cả `rawData` và `signingTime`, nếu không sẽ ném `ConfigException` *trước khi* gửi bất cứ thứ gì.

### 3.3 Chỉ băm, không gửi — `hash()`

Hữu ích khi cần tính trước hash, khử trùng lặp, hoặc lưu lại cục bộ trước khi quyết định anchor.

```php
$hash = $sdk->hash(json_encode(['orderId' => 1]));                       // dùng kiểu mặc định trong config
$hash = $sdk->hash($xmlString, SendOptions::dataType('xml'));            // chỉ định kiểu cho lần gọi này
```

Việc băm là tất định (deterministic): cùng một bản ghi luôn cho ra cùng một hash, trên mọi máy, ở mọi phiên bản ngôn ngữ của SDK.

---

## 4. Tuỳ chọn: kiểu dữ liệu, timeout & RPC URL

`SendOptions` là một value object bất biến mang ba thiết lập — `dataType`, `timeoutMs` và `rpcUrl`. Có thể truyền làm mặc định trong config, truyền theo từng lần gọi, hoặc cả hai.

### 4.1 Kiểu dữ liệu & chuẩn hoá

`dataType` quyết định cách bản ghi được chuẩn hoá trước khi băm.

| Giá trị | Hành vi |
| --- | --- |
| `json` | Chuẩn [RFC 8785](https://www.rfc-editor.org/rfc/rfc8785) (JSON Canonicalization Scheme) — sắp xếp khoá theo giá trị đơn vị mã UTF-16, chuẩn hoá số theo thuật toán `Number::toString` của ECMAScript (`1`, `1.0`, `1e0` là như nhau; `-0` → `0`). |
| `xml` | [Exclusive XML Canonicalization 1.0](https://www.w3.org/TR/xml-exc-c14n/) (`http://www.w3.org/2001/10/xml-exc-c14n#`), không kèm comment, qua `DOMDocument::C14N(true)` — chuẩn hoá thứ tự thuộc tính và khoảng trắng không đáng kể, và chỉ giữ lại các khai báo namespace mà tài liệu thực sự sử dụng. Prefix của namespace vẫn ảnh hưởng đến kết quả (Exclusive 1.0 không có cơ chế prefix rewriting). Việc phân giải external entity bị tắt (chống XXE). |
| `raw` | Không phân tích cú pháp — băm đúng chuỗi byte bạn truyền vào. Dùng khi phía gọi đã tự đảm bảo dữ liệu chỉ có một biểu diễn tất định. |

### Thứ tự ưu tiên

```php
// 1. Mặc định trong config
$sdk->send($rawJson, $signingTime);

// 2. Ghi đè cho từng lần gọi — ưu tiên hơn mặc định trong config
$sdk->send($rawXml, $signingTime, SendOptions::dataType('xml'));
$sdk->sendBatch($xmlRecords, SendOptions::dataType('xml'));
```

Nếu cả lần gọi lẫn config đều không có `dataType`, SDK ném `ConfigException`.

### 4.2 Timeout của request

`timeoutMs` giới hạn thời gian tối đa của **một** request HTTP tới Indexer, tính bằng mili giây. Mặc định là **10 000 ms (10 giây)** và tính cho toàn bộ request, không chỉ riêng bước kết nối.

```php
// Đặt làm mặc định cho mọi request của client này.
$sdk = new TracingSDK([
    'endpoint' => 'https://indexer.example.com',
    'options'  => new SendOptions('json', 5000),   // dataType, timeoutMs
    'auth'     => ['type' => 'apiToken', 'token' => 'your-api-token'],
]);

// Chỉ cho một lần gọi — ví dụ batch lớn cần nhiều thời gian hơn.
$sdk->sendBatch($records, SendOptions::timeoutMs(30000));
$sdk->send($rawXml, time(), SendOptions::dataType('xml')->withTimeoutMs(3000));
```

`timeoutMs` truyền theo lần gọi được ưu tiên hơn giá trị trong config; lần gọi không truyền `timeoutMs` thì giữ nguyên mặc định của config. Khác với `dataType`, `timeoutMs` không bao giờ bắt buộc — bỏ qua ở mọi nơi thì mọi request dùng mặc định 10 giây. Giá trị `0` hoặc âm sẽ ném `ConfigException` ngay lúc tạo `SendOptions`. Khi vượt quá timeout, lỗi được báo dưới dạng `TransportException`.

### 4.3 RPC URL

`rpcUrl` là endpoint JSON-RPC của một node blockchain — node của bạn hoặc của nhà cung cấp mà bạn tin cậy. Nó **chỉ** được dùng bởi `verify()` (xem [§8](#8-xác-minh-anchor-trên-blockchain)); việc gửi và băm không bao giờ chạm tới nó, nên có thể bỏ trống nếu bạn không dùng `verify()`.

```php
// Đặt làm mặc định cho mọi lần gọi verify().
$sdk = new TracingSDK([
    'endpoint' => 'https://indexer.example.com',
    'options'  => SendOptions::dataType('json')->withRpcUrl('https://rpc.example.com'),
    'auth'     => ['type' => 'apiToken', 'token' => 'your-api-token'],
]);

// Chỉ cho một lần gọi.
$sdk->verify($hash, $txHash, TracingSDK::MODE_TRANSACTION_HASH, SendOptions::rpcUrl('https://other-rpc.example.com'));
```

`rpcUrl` truyền theo lần gọi được ưu tiên hơn giá trị trong config. Gọi `verify()` mà không có `rpcUrl` ở cả hai nơi sẽ ném `ConfigException`; chuỗi rỗng bị ném lỗi ngay lúc tạo `SendOptions`.

### 4.4 `SendOptions` là bất biến

```php
$json = new SendOptions('json');                       // tương đương SendOptions::dataType('json')
$xml  = $json->withDataType('xml');                    // trả về bản sao; $json không đổi
$fast = $json->withTimeoutMs(2000);                    // tương tự
$node = $json->withRpcUrl('https://rpc.example.com');  // tương tự
```

### 4.5 Ví dụ với XML

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

## 5. Xác thực

```php
// API token — gửi qua header "X-API-Key".
'auth' => [
    'type'  => 'apiToken',
    'token' => 'your-api-token',
],

// HTTP Basic — gửi qua header "Authorization: Basic <base64>".
'auth' => [
    'type'     => 'basic',
    'username' => 'your-username',
    'password' => 'your-password',
],

// Mutual TLS — SDK xuất trình chứng thư client trong quá trình bắt tay TLS.
'auth' => [
    'type'       => 'mTLS',
    'cert'       => '/path/to/client.crt',
    'key'        => '/path/to/client.key',
    'caCert'     => '/path/to/ca.crt',   // tuỳ chọn: dùng để xác minh chứng thư của server
    'passphrase' => 'key-passphrase',    // tuỳ chọn: khi private key được mã hoá
],
```

Với mTLS, SDK luôn bật xác minh peer và host (`CURLOPT_SSL_VERIFYPEER`, `CURLOPT_SSL_VERIFYHOST = 2`). Đường dẫn chứng thư được kiểm tra ngay lúc khởi tạo SDK; file không tồn tại sẽ ném `ConfigException`.

---

## 6. Xử lý lỗi

Mọi exception của SDK đều kế thừa `Tracing\Sdk\Exception\TracingSdkException` (bản thân nó kế thừa `RuntimeException`), nên một khối `catch` là đủ để bắt tất cả.

| Exception | Xảy ra khi |
| --- | --- |
| `ConfigException` | Cấu hình thiếu/không hợp lệ, `dataType`, `auth.type` hoặc `mode` của `verify()` không được hỗ trợ, `timeoutMs` bằng `0` hoặc âm, `rpcUrl` rỗng hoặc không có khi verify, thiếu `signingTime`, bản ghi trong batch thiếu `rawData`/`signingTime`, `hash` rỗng, hoặc `dataHash`/`proof` không phải chuỗi hex 32 byte. |
| `CanonicalizationException` | Không chuẩn hoá được `rawData` theo kiểu dữ liệu đã chọn (ví dụ JSON/XML sai định dạng). |
| `TransportException` | Request HTTP thất bại (lỗi mạng, hoặc vượt quá `timeoutMs` — mặc định 10 giây), Indexer trả về status ngoài dải 2xx, hoặc node RPC báo lỗi / không biết giao dịch dùng làm proof. |

`ConfigException` và `CanonicalizationException` được ném **trước khi** bất cứ dữ liệu nào rời khỏi tiến trình, nên một lần gọi bị từ chối sẽ không bao giờ gửi được nửa batch.

```php
use Tracing\Sdk\Exception\CanonicalizationException;
use Tracing\Sdk\Exception\ConfigException;
use Tracing\Sdk\Exception\TransportException;

try {
    $result = $sdk->send($rawData, time());
} catch (ConfigException | CanonicalizationException $e) {
    // Lỗi đầu vào — gửi lại y nguyên payload cũng không giải quyết được.
    fwrite(STDERR, '[input] ' . $e->getMessage() . PHP_EOL);
} catch (TransportException $e) {
    // Lỗi tạm thời — có thể thử lại hoặc xếp hàng gửi sau.
    fwrite(STDERR, '[transport] ' . $e->getMessage() . PHP_EOL);
}
```

### Thử lại (retry)

SDK không tự retry. Vì việc băm là tất định và `send()` chỉ là một request POST thuần, gửi lại cùng payload sẽ gửi lại đúng hash đó:

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
        sleep(2 ** $attempt); // backoff đơn giản
    }
}
```

---

## 7. Query anchor theo hash

`queryByHash()` tra cứu hash của một bản ghi để lấy danh sách giao dịch blockchain đã anchor nó, qua `GET {endpoint}/api/anchors?hash=<hash>`. SDK tự URL-encode hash, và áp dụng cấu hình xác thực giống hệt như khi gửi.

```php
use Tracing\Sdk\Exception\TransportException;

try {
    $anchor = $sdk->queryByHash('0x1c8a…');
    // ['hash' => '0x1c8a…', 'txHashes' => ['0x9f42…', '0x3b07…']]

    foreach ($anchor['txHashes'] as $txHash) {
        echo $txHash, PHP_EOL;
    }
} catch (TransportException $e) {
    // Hash chưa được anchor, hoặc không kết nối được tới Indexer.
    echo $e->getMessage();
}
```

Cấu trúc trả về:

| Khoá | Mô tả |
| --- | --- |
| `hash` | Hash của bản ghi đã tra cứu, đúng như Indexer trả lại. |
| `txHashes` | Mọi giao dịch blockchain đã anchor bản ghi. Một bản ghi có thể được anchor nhiều lần, nên hãy luôn lặp qua danh sách thay vì giả định chỉ có một phần tử. |

Lỗi: `ConfigException` khi `$hash` rỗng; `TransportException` khi request thất bại, status ngoài dải 2xx (bao gồm `404` cho hash chưa từng được anchor), hoặc body không phải object `{ hash, txHashes }`. Vì vậy hash chưa được anchor là một exception, không phải giá trị `null` — hãy bọc trong `try`/`catch` nếu "chưa có" là kết quả bình thường với bạn.

Việc băm là tất định nên cùng một bản ghi luôn cho ra cùng một hash: hãy lưu lại hash mà `send()`/`sendBatch()` trả về, hoặc tính lại bất cứ lúc nào từ bản ghi gốc bằng `$sdk->hash($rawData)`, rồi query khi cần.

---

## 8. Xác minh anchor trên blockchain

`queryByHash()` cho bạn biết Indexer *nói* gì. `verify()` đối chiếu lời khẳng định đó với chính blockchain, qua một endpoint RPC do **bạn** chọn — nhờ vậy một Indexer bị chiếm quyền hoặc trả sai không thể tự bảo chứng cho mình.

```php
use Tracing\Sdk\Exception\TransportException;
use Tracing\Sdk\SendOptions;
use Tracing\Sdk\TracingSDK;

$sdk = new TracingSDK([
    'endpoint' => 'https://indexer.example.com',
    'options'  => new SendOptions('json', 5000, 'https://rpc.example.com'),
    'auth'     => ['type' => 'apiToken', 'token' => 'your-api-token'],
]);

$hash   = $sdk->hash($rawData);            // hoặc hash do send() trả về
$anchor = $sdk->queryByHash($hash);        // ['hash' => …, 'txHashes' => [...]]

foreach ($anchor['txHashes'] as $txHash) {
    if ($sdk->verify($hash, $txHash)) {
        echo "đã được anchor trên chain trong {$txHash}", PHP_EOL;
        break;
    }
}
```

### Cách hoạt động

1. Gọi `eth_getTransactionReceipt` với hash giao dịch proof tới `rpcUrl` đã cấu hình — chỉ là một POST JSON-RPC 2.0 thuần, không gắn kèm thông tin xác thực của Indexer.
2. Duyệt các log trong receipt và giữ lại những log có `topics[0]` bằng `keccak256("Anchored(bytes32,uint64)")`.
3. ABI-decode từng log đó theo `Anchored(bytes32 dataHash, uint64 signingTime)`.
4. Trả về `true` ngay khi có một `dataHash` giải mã được trùng với hash bạn truyền vào, `false` nếu không có log nào trùng.

Cả hai cách khai báo event đều giải mã được: `bytes32` có `indexed` được đọc từ `topics[1]`, không `indexed` thì đọc từ phần data của log. Việc so sánh dùng hash đã chuẩn hoá, nên chữ hoa/thường hay thiếu tiền tố `0x` không ảnh hưởng. Log của các event khác trong cùng giao dịch bị bỏ qua.

### Chữ ký hàm

```php
verify(
    string $dataHash,                                  // hash của bản ghi
    string $proof,                                     // bằng chứng trên chain
    string $mode = TracingSDK::MODE_TRANSACTION_HASH,   // cách hiểu proof
    ?SendOptions $options = null                        // rpcUrl / timeoutMs theo lần gọi
): bool
```

| Tham số | Mô tả |
| --- | --- |
| `$dataHash` | Hash Keccak-256 của bản ghi — từ `hash()`, `send()`, hoặc `queryByHash()`. Phải là chuỗi hex 32 byte. |
| `$proof` | Bằng chứng trên chain để đối chiếu. Với mode hiện tại, đây là một hash giao dịch, ví dụ một phần tử trong `txHashes` của `queryByHash()`. |
| `$mode` | Cách hiểu `$proof`. Hiện chỉ có `TracingSDK::MODE_TRANSACTION_HASH` (`'transactionHash'`); tham số này tồn tại để sau này thêm loại bằng chứng khác mà không phải đổi chữ ký hàm. Giá trị khác sẽ ném `ConfigException`. |
| `$options` | `rpcUrl` và `timeoutMs` cho riêng lần gọi này. Nếu không truyền thì lấy từ `options` trong config. |

### `true`, `false`, hay exception

| Kết quả | Ý nghĩa |
| --- | --- |
| `true` | Giao dịch thực sự chứa event `Anchored` mang đúng data hash này. |
| `false` | Giao dịch tồn tại, nhưng không có event `Anchored` nào trong đó mang hash này — bản ghi không được anchor bởi giao dịch này. |
| `ConfigException` | Sai đầu vào hoặc cấu hình: `dataHash`/`proof` sai định dạng, `$mode` không được hỗ trợ, hoặc không có `rpcUrl` ở đâu cả. |
| `TransportException` | Không kết nối được endpoint RPC, endpoint trả về status ngoài dải 2xx hoặc lỗi JSON-RPC, hoặc node không biết giao dịch (hash chưa được mine, đã bị drop, hoặc thuộc chain khác — node trả receipt `null`). |

`false` là câu trả lời thật về một giao dịch thật; còn giao dịch không tồn tại là exception, vì "node chưa từng thấy nó" không nói lên được bản ghi đã được anchor hay chưa.

```php
try {
    $verified = $sdk->verify($hash, $txHash);
} catch (TransportException $e) {
    // RPC không truy cập được, hoặc giao dịch chưa được mine — hãy thử lại sau,
    // đừng coi đây là "chưa được anchor".
    $verified = null;
}
```

### Lưu ý

- Hãy chọn node RPC trên đúng chain mà Indexer anchor lên; một node khoẻ nhưng sai chain đơn giản là không biết giao dịch đó, và lỗi sẽ hiện ra dưới dạng `TransportException`.
- Dùng node có đủ dữ liệu lịch sử cho block cần kiểm tra. Một số endpoint công khai xoá bớt (prune) receipt cũ.
- `timeoutMs` áp dụng cho request RPC giống như với request tới Indexer, mặc định 10 000 ms.
- `rpcUrl` có thể chứa API key của nhà cung cấp; nó chỉ được gửi tới URL đó, không bao giờ gửi tới Indexer.

---

## 9. Ví dụ chạy được

Thư mục `example/php/` chứa các script hoàn chỉnh:

| File | Minh hoạ |
| --- | --- |
| [`single-send-example.php`](../example/php/single-send-example.php) | Một bản ghi JSON với `send()` |
| [`example.php`](../example/php/example.php) | Nhiều bản ghi JSON trong một request với `sendBatch()` |
| [`xml-example.php`](../example/php/xml-example.php) | Luồng batch với `SendOptions::dataType('xml')` |
| [`query-example.php`](../example/php/query-example.php) | Gửi một bản ghi rồi tra cứu anchor bằng `queryByHash()` |
| [`verify-example.php`](../example/php/verify-example.php) | Query rồi `verify()` từng giao dịch proof với node RPC |

Mỗi script trỏ tới `http://localhost:3000` với token giả — sửa `endpoint` và `auth` ở đầu file (thêm `rpcUrl` với ví dụ verify), rồi chạy:

```bash
composer install
php example/php/single-send-example.php
```

---

## 10. Tham chiếu API

```php
new TracingSDK(array $config)

send(string $rawData, mixed $signingTime, ?SendOptions $options = null): array
    // ['hash' => string, 'response' => ['statusCode' => int, 'body' => mixed, 'recordCount' => int]]

sendBatch(array $records, ?SendOptions $options = null): array
    // [['hash' => string, 'response' => [...]], ...] — một phần tử cho mỗi bản ghi đầu vào

hash(string $rawData, ?SendOptions $options = null): string
    // '0x…' hex Keccak-256

queryByHash(string $hash, ?SendOptions $options = null): array
    // ['hash' => string, 'txHashes' => string[]]

verify(string $dataHash, string $proof, string $mode = TracingSDK::MODE_TRANSACTION_HASH, ?SendOptions $options = null): bool
    // true khi log của $proof chứa Anchored(bytes32,uint64) với $dataHash

TracingSDK::MODE_TRANSACTION_HASH   // 'transactionHash' — mode verify duy nhất hiện nay
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
$options->withDataType(?string $dataType): SendOptions   // trả về bản sao
$options->withTimeoutMs(?int $timeoutMs): SendOptions     // trả về bản sao
$options->withRpcUrl(?string $rpcUrl): SendOptions        // trả về bản sao
```

Các endpoint Indexer được dùng: `POST /api/anchors`, `POST /api/anchors/batch`, `GET /api/anchors?hash=…`. Ngoài ra `verify()` gọi `eth_getTransactionReceipt` trên `rpcUrl`. Timeout của request là `timeoutMs`, mặc định `CurlHttpTransport::DEFAULT_TIMEOUT_MS` (10 000 ms).
