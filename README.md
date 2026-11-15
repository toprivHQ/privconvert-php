# PrivConvert for PHP

Official PHP client for the [PrivConvert API](https://privconvert.com/developers/): file conversion for your app with zero data retention. Every file is processed in memory on our side and never written to disk.

- No dependencies beyond the curl and json extensions (cloud storage libraries are optional)
- PHP 8.1+
- Large files (above 90 MB) are uploaded in parts automatically
- Async jobs, webhook signature verification and typed exceptions included

## Install

```bash
composer require privconvert/privconvert
```

## Quick start

Create a key on your [account page](https://privconvert.com/account/#apikeys) (new accounts can start a free trial of 25 conversions) and set it as an environment variable:

```bash
export PRIVCONVERT_API_KEY=pk_live_...
```

```php
use PrivConvert\PrivConvert;

$pc = new PrivConvert();

$result = $pc->convert('word-to-pdf', 'contract.docx');
$result->save('contract.pdf');
echo $result->creditsRemaining();
```

The file can be a path, an open stream, or data you already have in memory. Tool options go in the third argument:

```php
use PrivConvert\File;

$pc->convert('compress-image', fopen('photo.png', 'rb'), ['quality' => 70])->save('photo-small.png');
$pc->convert('resize-image', File::fromString($imageBytes, 'in.jpg'), ['width' => 800]);
```

The tool id is the same slug as on the site. List them with `$pc->tools()` or `$pc->tools('document')`.

## Many files at once

Up to 20 files in one request, returned as a ZIP. A batch costs one slot of your per-minute rate limit.

```php
$zip = $pc->convertBatch('png-to-jpg', ['a.png', 'b.png', 'c.png']);
$zip->save('converted.zip');
if ($zip->isPartial()) {
    echo $zip->failedCount(), " files were skipped, see PRIVCONVERT-ERRORS.txt in the ZIP\n";
}
```

## Files in cloud storage

Convert a file in Amazon S3, Google Cloud Storage or Azure Blob Storage and write the result back:

```php
$pc->convertStorage('word-to-pdf', 's3://my-bucket/input.docx', 's3://my-bucket/output.pdf');
$pc->convertStorage('word-to-pdf', 'gs://my-bucket/input.docx', 'gs://my-bucket/output.pdf');
$pc->convertStorage('word-to-pdf', 'az://myaccount/my-container/input.docx', 'az://myaccount/my-container/output.pdf');
```

The cloud is picked from the address. Azure also accepts the blob URL from the portal (`https://myaccount.blob.core.windows.net/my-container/input.docx`). Source and destination can be in different clouds.

The file moves between your storage and PrivConvert from wherever this code runs, using your own credentials. Your credentials are never sent to PrivConvert, and we never get access to your storage. Run it in the same region as the bucket and the transfer is fast and cheap.

**Libraries.** Azure support is built in. For Amazon S3 and Google Cloud Storage, the official libraries are used if they are installed. This adds both:

```bash
composer require aws/aws-sdk-php google/cloud-storage
```

**Credentials.** For S3 the AWS SDK's default chain (environment, profile or IAM role; set `AWS_REGION` to your bucket's region), for Google Application Default Credentials, and for Azure the `AZURE_STORAGE_CONNECTION_STRING` environment variable. To control it yourself, pass a client:

```php
use Aws\S3\S3Client;
use Google\Cloud\Storage\StorageClient;
use PrivConvert\Storage\AzureBlobClient;

$pc->convertStorage('word-to-pdf', 's3://in-bucket/a.docx', 'gs://out-bucket/a.pdf', [
    's3Client' => new S3Client(['region' => 'eu-west-1']),
    'gcsClient' => new StorageClient(['keyFilePath' => '/secrets/gcs.json']),
]);

// Azure: a connection string, a SAS token, or a Microsoft Entra token
$azure = AzureBlobClient::fromConnectionString(getenv('MY_AZURE_CONNECTION'));
$azure = AzureBlobClient::withSas('myaccount', $sasToken);
$azure = AzureBlobClient::withToken('myaccount', fn () => $myTokenCache->get('https://storage.azure.com/'));
$pc->convertStorage('word-to-pdf', 'az://myaccount/docs/a.docx', 'az://myaccount/docs/a.pdf', ['azureClient' => $azure]);
```

**Cloudflare R2, Backblaze B2 and other S3-compatible storage.** Use an `s3://` address and an S3 client pointed at the provider.

### Cloudflare R2

Convert files in an R2 bucket and write the result back to R2. Create an R2 API token with read and write access to the bucket, and use your Cloudflare account ID in the endpoint:

```php
use Aws\S3\S3Client;
use PrivConvert\PrivConvert;

$r2 = new S3Client([
    'region' => 'auto',
    'endpoint' => 'https://' . getenv('R2_ACCOUNT_ID') . '.r2.cloudflarestorage.com',
    'credentials' => ['key' => getenv('R2_ACCESS_KEY_ID'), 'secret' => getenv('R2_SECRET_ACCESS_KEY')],
]);

$pc = new PrivConvert();
$pc->convertStorage('word-to-pdf', 's3://my-bucket/contracts/input.docx', 's3://my-bucket/contracts/output.pdf', [
    's3Client' => $r2,
    'overwrite' => false,
]);
```

The file goes from R2 to PrivConvert and back from wherever this code runs. Your R2 keys stay with you.

### Backblaze B2

```php
$b2 = new S3Client([
    'region' => 'us-east-005',
    'endpoint' => 'https://s3.us-east-005.backblazeb2.com',
    'credentials' => ['key' => getenv('B2_KEY_ID'), 'secret' => getenv('B2_APP_KEY')],
]);
$pc->convertStorage('word-to-pdf', 's3://my-bucket/input.docx', 's3://my-bucket/output.pdf', ['s3Client' => $b2]);
```

### Options

```php
$pc->convertStorage('word-to-pdf', 'gs://in-bucket/contract.docx', 's3://out-bucket/contract.pdf', [
    'uploadArgs' => ['ServerSideEncryption' => 'aws:kms'],  // passed to the destination's upload call
    'overwrite' => false,                                   // leave an existing file alone, before any credit is spent
]);
```

`uploadArgs` are `putObject` arguments for S3, `Bucket::upload` options for Google, and extra request headers for Azure (for example `['x-ms-access-tier' => 'Cool']`).

Storage problems throw `StorageException`, with the provider's code in `$e->errorCode` (for example `NoSuchKey`, `NotFound`, `BlobNotFound` or `AccessDenied`).

## Async jobs

```php
$job = $pc->submitJob('pptx-to-pdf', 'deck.pptx');
// $job->key is returned once. Keep it if you need to download later or verify webhooks.
$result = $pc->wait($job);          // polls, downloads, then deletes the stored result
$result->save('deck.pdf');
```

With a webhook instead of polling:

```php
$job = $pc->submitJob('pptx-to-pdf', 'deck.pptx', webhookUrl: 'https://example.com/hooks/privconvert');
saveSomewhere($job->id, $job->key);
// later, when the webhook arrives:
$result = $pc->downloadJob($job);
```

## Verifying webhooks

Pass the raw request body, not decoded JSON:

```php
use PrivConvert\Webhook;

$raw = file_get_contents('php://input');
$jobKey = loadJobKey(json_decode($raw, true)['id'] ?? '');
if (!Webhook::verify($jobKey, $_SERVER['HTTP_X_PRIVCONVERT_SIGNATURE'] ?? null, $raw)) {
    http_response_code(400);
    exit;
}
```

Deliveries older than 5 minutes are rejected as replays (`tolerance:` to change it).

## Errors

Every API error is a `PrivConvert\Exception\PrivConvertException` with `$status`, `$errorCode`, `$retryAfter` and `getMessage()`:

| Exception | When |
| --- | --- |
| `AuthenticationException` | Missing or invalid key (401) |
| `BadRequestException` | Bad request or unreadable input (400) |
| `FileTooLargeException` | Above your plan's size limit (413) |
| `UnsupportedTypeException` | Wrong input type for the tool (415) |
| `ConversionFailedException` | File read but could not be converted (422) |
| `RateLimitException` | Per-minute rate or monthly quota exceeded (429) |
| `ConcurrencyLimitException` | Too many conversions at once (429) |
| `ServerException` | Problem on our side (5xx) |
| `StorageException` | Reading from or writing to your cloud storage failed |

Short 429, 502, 503 and 504 responses are retried automatically (`maxRetries: 2`), honouring `Retry-After`. A long `Retry-After`, which means the monthly quota is used up, throws at once instead of sleeping.

## Usage and limits

```php
$pc->me();
// ['tier' => 'growth', 'quota' => 25000, 'used' => 1234, 'remaining' => 23766, 'rpm' => 120, ...]
```

## Large files

Files are held in memory. For files of tens of megabytes, raise PHP's `memory_limit` to about three times the file size.

## Security notes

- The key is read from `PRIVCONVERT_API_KEY` or passed explicitly, and never appears in `var_dump()`, `print_r()` or stack traces.
- Requests go over HTTPS only. A plain `http://` base URL is refused.
- This package writes nothing to disk except where you call `Result::save()`.
- `convertStorage` keeps the file in memory between your storage and the API. Your cloud credentials stay with you.

## Links

- [API documentation](https://privconvert.com/docs/)
- [Pricing and free trial](https://privconvert.com/developers/)
- Support: contact@topriv.com

MIT License
