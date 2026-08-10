# Tracing SDK

## Cấu trúc SDK

```
┌─ Trong SDK (local, không qua network) ─────────────────────────────────────┐
│                                                                            │
│  ┌──────────────┐    ┌───────────────┐    ┌─────────────┐    ┌──────────┐  │
│  │ Dữ liệu thô  │    │  Chuẩn hóa    │    │ Hash + Gắn  │    │  Bộ nhớ  │  │
│  │ XML / JSON   │──▶│ (canonicalize)│──▶│    Index    │──▶│  đệm     │  │
│  └──────────────┘    └───────────────┘    └─────────────┘    └────┬─────┘  │
│                                                                   │        │
│                                                      ┌────────────┴───────┐│
│                                                      │  Điều kiện gửi:    ││
│                                                      │ đủ số lượng N HOẶC ││
│                                                      │ đủ thời gian Δt    ││
│                                                      └────────────┬───────┘│
└───────────────────────────────────────────────────────────────────┼────────┘
                                                                    │
                                                                    ▼
                                                    ┌─ Bên ngoài SDK (qua network) ─┐
                                                    │            Indexer            │
                                                    │      HTTP POST /batch         │
                                                    └───────────────────────────────┘
```

Toàn bộ khối "Trong SDK" (nhận dữ liệu → chuẩn hóa → hash → gắn index → bộ nhớ đệm → kiểm tra điều kiện gửi) chạy hoàn toàn cục bộ trong tiến trình gọi SDK, không phát sinh request nào. Chỉ khi điều kiện gửi (đủ N bản ghi hoặc đủ Δt) được thỏa, SDK mới thực hiện lệnh gọi ra ngoài duy nhất: HTTP POST tới dịch vụ Indexer ở `endpoint` đã cấu hình — đây là điểm duy nhất SDK phụ thuộc vào một service bên ngoài.

### 1. Đầu vào

```
record = { data: "<raw xml|raw json>", signingTime: 1785997692 }
```
Các trường trong indexes sẽ được sử dụng để có thể truy vấn lại bằng chứng toàn vẹn dữ liệu.
`signingTime` ở dạng unix timestamp là thời điểm bản ghi được sử dụng để truy vấn.

### 2. Luồng xử lý (mỗi bản ghi)

```
thô ──chuẩn hóa──▶ hash ──▶ { hash, signingTime } ──▶ bộ nhớ đệm
```

Mỗi lần gọi `$sdk->index(rawData, signingTime)`, một bản ghi đi qua 4 bước sau trước khi vào vùng đệm:

**Bước 1 — Nhận đầu vào**
SDK nhận `rawData` (XML hoặc JSON, tùy `dataType` đã cấu hình) cùng `signingTime` do caller cung cấp. Ở bước này SDK chưa đọc/hiểu nội dung `rawData`, chỉ giữ nguyên như một khối dữ liệu để xử lý ở bước sau.

**Bước 2 — Chuẩn hóa (canonicalize)**
`rawData` được đưa về một dạng biểu diễn duy nhất, không phụ thuộc cách trình bày (thứ tự thuộc tính, khoảng trắng, encoding...). Mục đích: hai bản ghi có nội dung giống nhau — nhưng được serialize khác nhau — phải cho ra cùng một kết quả băm ở bước 3. Nếu không chuẩn hóa trước, chỉ một khác biệt định dạng nhỏ (ví dụ đổi thứ tự field trong JSON) cũng làm hash lệch, khiến việc tra cứu lại bằng chứng toàn vẹn ở bước sau bị sai lệch.

**Bước 3 — Hash **
Dữ liệu đã chuẩn hóa sẽ dùng để tạo ra `hash` — giá trị đại diện duy nhất cho nội dung bản ghi. `hash` này chính là "bằng chứng toàn vẹn": bất kỳ thay đổi nào trên dữ liệu gốc, dù nhỏ, đều làm `hash` thay đổi hoàn toàn.

**Bước 4 — Gắn metadata và đưa vào bộ nhớ đệm**
SDK ghép `hash` và `signingTime` (thời điểm xử lý, dạng Unix timestamp — số nguyên milliseconds kể từ epoch), tạo thành object `{ hash, signingTime }`. Mỗi bản ghi bắt buộc phải có `signingTime` — đây là mốc thời gian gắn với entry, dùng làm bằng chứng thời điểm khi tra cứu lại sau này. Object này được đẩy vào vùng đệm (buffer) trong bộ nhớ — bản ghi gốc (`rawData`) không được lưu lại trong buffer, chỉ có `hash`, `signingTime`

Toàn bộ 4 bước này chạy bất đồng bộ (`sdk.index()` không chờ gửi đi thật), nên caller có thể gọi liên tiếp nhiều bản ghi mà không bị block


### 3. Gửi dữ liệu

SDK sẽ gửi dữ liệu trong buffer ra ngoài khi thỏa một trong hai điều kiện:
- Đủ số lượng bản ghi N (`batchSize`) đã cấu hình.
- Đủ thời gian Δt (`flushInterval`) kể từ lần gửi trước, dù chưa đủ N bản ghi.

### 4. Authentication

SDK hỗ trợ ba phương thức xác thực với Indexer, cấu hình qua `auth`:

- **mTLS** — xác thực hai chiều ở tầng TLS: SDK trình chứng chỉ client (`cert`/`key`) khi bắt tay TLS, Indexer xác minh chứng chỉ đó trước khi chấp nhận kết nối. Cần cấu hình thêm `caCert` để SDK xác minh ngược lại chứng chỉ server.
- **basic** — xác thực bằng `username`/`password`, SDK tự gắn header `Authorization: Basic <base64(username:password)>`.
- **apiToken** — xác thực bằng token do Indexer cấp, SDK tự gắn header `Authorization: Bearer <token>`.

```php
$sdk = new TracingSDK([
    'endpoint'      => 'https://indexer.example.com',
    'batchSize'     => 20,          // gửi khi vùng đệm đạt N bản ghi, nếu batchSize = 0 thì gửi ngay lập tức
    'flushInterval' => 5000,        // gửi sau Δt ms không có dữ liệu mới, dù chưa đầy
    'dataType'      => 'json',      // 'json' | 'xml'
    'auth'          => [
        'type'    => 'mTLS',        // 'mTLS' | 'basic' | 'apiToken'
        'cert'    => '/path/to/client.crt', // dùng khi type = 'mTLS'
        'key'     => '/path/to/client.key', // dùng khi type = 'mTLS'
        'caCert'  => '/path/to/ca.crt',     // dùng khi type = 'mTLS', để xác minh chứng chỉ server
        // 'username' => 'your-username',   // dùng khi type = 'basic'
        // 'password' => 'your-password',   // dùng khi type = 'basic'
        // 'token'    => 'your-api-token',  // dùng khi type = 'apiToken'
    ],
]);

$sdk->index([
    ['rawData' => $rawData, 'signingTime' => $signingTime],
]); // đồng bộ hoặc bất đồng bộ tùy runtime, có thể thêm nhiều bản ghi trong cùng một lần gọi
$sdk->flush(); // ép gửi ngay lập tức
$sdk->on('sent', function ($result) { /* ... */ });
$sdk->on('error', function ($error) { /* ... */ });
```

## Indexer service

Indexer service là một service bên ngoài SDK, nhận các batch dữ liệu từ SDK, xử lý việc lưu trữ và truy vấn lại bằng chứng toàn vẹn dữ liệu.
