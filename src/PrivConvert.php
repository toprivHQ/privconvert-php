<?php

declare(strict_types=1);

namespace PrivConvert;

use PrivConvert\Exception\PrivConvertException;
use PrivConvert\Exception\StorageException;
use PrivConvert\Internal\Http;
use PrivConvert\Internal\TransportException;
use PrivConvert\Storage\Location;
use PrivConvert\Storage\Storage;

/**
 * Client for the PrivConvert API.
 *
 * The key is read from $apiKey or the PRIVCONVERT_API_KEY environment variable.
 * Files are held in memory and never written to disk by this package; only
 * Result::save() writes, and only where you tell it to.
 */
final class PrivConvert
{
    public const VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://privconvert.com/api';

    // A single POST accepts up to 100 MB; above this the file goes through the
    // chunked upload flow. Parts stay well below the 95 MB per-part ceiling so a
    // slow link never holds one request open long enough to time out at the edge.
    private const DIRECT_LIMIT = 90 * 1024 * 1024;
    private const CHUNK_SIZE = 20 * 1024 * 1024;

    // A Retry-After longer than this means the monthly quota is used up, not a
    // momentary limit, so the call fails instead of sleeping.
    private const MAX_RETRY_WAIT_S = 60.0;

    private const STATUS_BY_CODE = [
        'bad_request' => 400,
        'unauthorized' => 401,
        'file_too_large' => 413,
        'unsupported_type' => 415,
        'conversion_failed' => 422,
        'rate_limited' => 429,
        'concurrency_limited' => 429,
        'server_error' => 500,
        'server_busy' => 503,
    ];

    private readonly string $apiKey;
    public readonly string $baseUrl;
    public readonly float $timeout;
    public readonly int $maxRetries;

    public function __construct(
        #[\SensitiveParameter] ?string $apiKey = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        float $timeout = 300.0,
        int $maxRetries = 2,
    ) {
        $key = trim($apiKey ?? (string) getenv('PRIVCONVERT_API_KEY'));
        if ($key === '') {
            throw new PrivConvertException('No API key. Pass $apiKey or set PRIVCONVERT_API_KEY.');
        }
        if (!preg_match('/^[\x21-\x7e]+$/D', $key)) {
            throw new PrivConvertException('The API key contains invalid characters.');
        }
        $this->apiKey = $key;
        $this->baseUrl = self::checkBaseUrl($baseUrl);
        $this->timeout = $timeout;
        $this->maxRetries = max(0, $maxRetries);
    }

    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl];
    }

    // ── conversions ──────────────────────────────────────────────────────

    /**
     * Convert one file. Files above 90 MB are uploaded in parts automatically.
     *
     * @param string|resource|File $file a path, an open stream, or File::fromString($data, 'name.ext')
     * @param array<string, mixed> $params tool options, e.g. ['quality' => 80]
     */
    public function convert(string $tool, mixed $file, array $params = [], ?string $filename = null): Result
    {
        $tool = self::checkTool($tool);
        $f = File::from($file, $filename);
        if (strlen($f->content) > self::DIRECT_LIMIT) {
            return $this->convertChunked($tool, $f, $params);
        }
        return $this->requestFile('POST', "/convert/{$tool}", self::formParams($params), [['file', $f->filename, $f->content]]);
    }

    /**
     * Run one tool over up to 20 files; the result is a ZIP.
     *
     * Check failedCount() / isPartial(): a batch where some files failed still
     * succeeds with the files that converted.
     *
     * @param iterable<string|resource|File> $files
     * @param array<string, mixed> $params
     */
    public function convertBatch(string $tool, iterable $files, array $params = []): Result
    {
        $tool = self::checkTool($tool);
        $parts = [];
        $i = 0;
        foreach ($files as $file) {
            $i++;
            if ($i > 20) {
                throw new PrivConvertException('convertBatch takes at most 20 files per request.');
            }
            $f = File::from($file);
            $parts[] = ['files', $f->filename === 'file' ? "file{$i}" : $f->filename, $f->content];
        }
        if ($parts === []) {
            throw new PrivConvertException('convertBatch needs at least one file.');
        }
        return $this->requestFile('POST', '/convert-batch', array_merge([['tool', $tool]], self::formParams($params)), $parts);
    }

    /**
     * Convert a file in cloud storage and write the result back to storage.
     *
     * Locations are s3://bucket/key, gs://bucket/key, az://account/container/blob
     * or https://account.blob.core.windows.net/container/blob. Source and
     * destination may be in different clouds. The file moves between your
     * storage and PrivConvert from wherever this code runs, using your own
     * credentials. Your credentials are never sent to PrivConvert.
     *
     * Options:
     * - params: tool options
     * - s3Client: an Aws\S3\S3Client (for Backblaze B2 or Cloudflare R2, one created with that provider's endpoint)
     * - gcsClient: a Google\Cloud\Storage\StorageClient
     * - azureClient: a PrivConvert\Storage\AzureBlobClient
     * - overwrite: false leaves an existing destination untouched and throws
     *   StorageException before any credit is spent (default true)
     * - uploadArgs: passed to the destination's upload call (putObject arguments,
     *   Bucket::upload options, or extra Azure request headers)
     *
     * @param array{params?: array<string, mixed>, s3Client?: object, gcsClient?: object, azureClient?: object, overwrite?: bool, uploadArgs?: array<string, mixed>} $options
     */
    public function convertStorage(string $tool, string $source, string $destination, array $options = []): Result
    {
        $tool = self::checkTool($tool);
        $src = Location::parse($source);
        $dst = Location::parse($destination);
        $clients = [$options['s3Client'] ?? null, $options['gcsClient'] ?? null, $options['azureClient'] ?? null];
        $reader = Storage::backend($src, ...$clients);
        $same = $src->provider === $dst->provider && $src->account === $dst->account;
        $writer = $same ? $reader : Storage::backend($dst, ...$clients);
        $overwrite = $options['overwrite'] ?? true;
        if (!$overwrite && $writer->exists($dst)) {
            throw new StorageException("{$dst} already exists.", errorCode: 'DestinationExists');
        }
        $data = $reader->read($src);
        $result = $this->convert($tool, File::fromString($data, (string) substr(strrchr('/' . $src->key, '/'), 1)), $options['params'] ?? []);
        $writer->write($dst, $result->content, $result->contentType, $overwrite, $options['uploadArgs'] ?? []);
        return $result;
    }

    // ── async jobs ───────────────────────────────────────────────────────

    /**
     * Submit an async job. Returns at once with the job id and one-time key.
     *
     * @param string|resource|File $file
     * @param array<string, mixed> $params
     */
    public function submitJob(string $tool, mixed $file, array $params = [], ?string $webhookUrl = null, ?string $filename = null): Job
    {
        $tool = self::checkTool($tool);
        $f = File::from($file, $filename);
        $fields = self::formParams($params);
        if ($webhookUrl !== null && $webhookUrl !== '') {
            if (!str_starts_with($webhookUrl, 'https://')) {
                throw new PrivConvertException('webhookUrl must be an https:// URL.');
            }
            $fields[] = ['webhook_url', $webhookUrl];
        }
        $body = $this->requestJson('POST', "/jobs/{$tool}", $fields, [['file', $f->filename, $f->content]]);
        if (!isset($body['id'], $body['key'])) {
            throw new PrivConvertException('Unexpected response when submitting the job.');
        }
        return new Job((string) $body['id'], (string) $body['key'], (string) ($body['status'] ?? 'processing'));
    }

    public function getJob(Job|string $job): JobStatus
    {
        $id = self::checkJobId($job);
        $body = $this->requestJson('GET', "/jobs/{$id}", job: true);
        return new JobStatus($id, (string) ($body['status'] ?? ''), $body);
    }

    public function downloadJob(Job $job): Result
    {
        $id = self::checkJobId($job);
        return $this->requestFile('GET', "/jobs/{$id}/download", extraHeaders: ['X-Job-Key' => $job->key], job: true);
    }

    public function deleteJob(Job|string $job): void
    {
        $id = self::checkJobId($job);
        $this->request('DELETE', "/jobs/{$id}", job: true);
    }

    /**
     * Poll until the job finishes, then download the result.
     *
     * Throws the same exception a synchronous call would have thrown if the job
     * failed. With $deleteAfter the stored result is wiped right after download
     * instead of waiting out the retention window.
     */
    public function wait(Job $job, float $pollInterval = 2.0, float $timeout = 1800.0, bool $deleteAfter = true): Result
    {
        $deadline = microtime(true) + $timeout;
        $interval = max(0.5, $pollInterval);
        while (true) {
            $st = $this->getJob($job);
            if ($st->status === 'finished') {
                break;
            }
            if ($st->status === 'failed') {
                $e = $st->error() ?? [];
                $code = isset($e['code']) ? (string) $e['code'] : null;
                throw PrivConvertException::fromResponse(
                    self::STATUS_BY_CODE[$code ?? ''] ?? 422,
                    (string) ($e['message'] ?? 'The conversion failed.'),
                    $code,
                    null,
                );
            }
            if (microtime(true) + $interval > $deadline) {
                throw new PrivConvertException(sprintf('Job %s did not finish within %ds.', $job->id, (int) $timeout));
            }
            usleep((int) ($interval * 1e6));
            $interval = min($interval * 1.5, 10.0);
        }
        $result = $this->downloadJob($job);
        if ($deleteAfter) {
            try {
                $this->deleteJob($job);
            } catch (PrivConvertException) {
            }
        }
        return $result;
    }

    // ── account & catalog ────────────────────────────────────────────────

    /**
     * Your plan, monthly quota and current usage.
     *
     * @return array<string, mixed>
     */
    public function me(): array
    {
        return $this->requestJson('GET', '/me');
    }

    /**
     * The public catalog of every tool, optionally filtered by category.
     *
     * @return list<array<string, mixed>>
     */
    public function tools(?string $category = null): array
    {
        $path = '/tools' . ($category !== null && $category !== '' ? '?' . http_build_query(['category' => $category]) : '');
        $body = $this->requestJson('GET', $path, auth: false);
        return is_array($body['tools'] ?? null) ? array_values($body['tools']) : [];
    }

    // ── internals ────────────────────────────────────────────────────────

    /** @param array<string, mixed> $params */
    private function convertChunked(string $tool, File $f, array $params): Result
    {
        $total = strlen($f->content);
        $count = (int) ceil($total / self::CHUNK_SIZE);
        $init = $this->requestJson('POST', '/upload-init', [
            ['total_size', (string) $total],
            ['total_chunks', (string) $count],
            ['filename', $f->filename],
            ['tool', $tool],
        ]);
        $uploadId = (string) ($init['upload_id'] ?? '');
        if ($uploadId === '') {
            throw new PrivConvertException('The server did not return an upload id.');
        }
        for ($i = 0; $i < $count; $i++) {
            $this->requestJson(
                'POST',
                '/upload-chunk',
                [['upload_id', $uploadId], ['chunk_index', (string) $i]],
                [['file', 'chunk', substr($f->content, $i * self::CHUNK_SIZE, self::CHUNK_SIZE)]],
            );
        }
        return $this->requestFile('POST', '/convert-chunked', array_merge([['upload_id', $uploadId]], self::formParams($params)));
    }

    /**
     * @param list<array{0: string, 1: string}>|null $fields
     * @param list<array{0: string, 1: string, 2: string}>|null $files
     * @param array<string, string> $extraHeaders
     */
    private function requestFile(string $method, string $path, ?array $fields = null, ?array $files = null, array $extraHeaders = [], bool $job = false): Result
    {
        [, $headers, $body] = $this->request($method, $path, $fields, $files, $extraHeaders, true, $job);
        return new Result($body, self::filenameFrom($headers['content-disposition'] ?? ''), $headers['content-type'] ?? null, $headers);
    }

    /**
     * @param list<array{0: string, 1: string}>|null $fields
     * @param list<array{0: string, 1: string, 2: string}>|null $files
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $path, ?array $fields = null, ?array $files = null, bool $auth = true, bool $job = false): array
    {
        [$status, , $body] = $this->request($method, $path, $fields, $files, [], $auth, $job);
        if ($body === '') {
            return [];
        }
        $out = json_decode($body, true);
        if ($out === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new PrivConvertException('The server returned a response that is not JSON.', $status);
        }
        return is_array($out) && ($out === [] || !array_is_list($out)) ? $out : ['data' => $out];
    }

    /**
     * @param list<array{0: string, 1: string}>|null $fields
     * @param list<array{0: string, 1: string, 2: string}>|null $files
     * @param array<string, string> $extraHeaders
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function request(string $method, string $path, ?array $fields = null, ?array $files = null, array $extraHeaders = [], bool $auth = true, bool $job = false): array
    {
        $headers = ['User-Agent' => 'privconvert-php/' . self::VERSION, 'Accept' => '*/*'];
        if ($auth) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }
        $headers = array_merge($headers, $extraHeaders);
        $body = null;
        if ($fields !== null || $files !== null) {
            [$body, $ctype] = self::multipart($fields ?? [], $files ?? []);
            $headers['Content-Type'] = $ctype;
        } elseif ($method === 'POST' || $method === 'PUT') {
            $body = '';
        }
        $attempt = 0;
        while (true) {
            try {
                [$status, $h, $raw] = Http::send($method, $this->baseUrl . $path, $headers, $body, $this->timeout);
            } catch (TransportException $e) {
                throw new PrivConvertException('Could not reach PrivConvert: ' . $e->getMessage());
            }
            if ($status >= 200 && $status < 300) {
                return [$status, $h, $raw];
            }
            [$message, $code] = self::parseError($raw, $status);
            $retryAfter = isset($h['retry-after']) && is_numeric($h['retry-after']) ? max(0.0, (float) $h['retry-after']) : null;
            $retryable = in_array($status, [429, 502, 503, 504], true);
            $wait = $retryAfter ?? min(2.0 * (2 ** $attempt), 20.0);
            if ($retryable && $attempt < $this->maxRetries && $wait <= self::MAX_RETRY_WAIT_S) {
                $attempt++;
                usleep((int) ($wait * 1e6));
                continue;
            }
            throw PrivConvertException::fromResponse($status, $message, $code, $retryAfter, $job);
        }
    }

    private static function checkBaseUrl(string $url): string
    {
        $url = rtrim($url, '/');
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        if (is_string($host) && $host !== '' && ($scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)))) {
            return $url;
        }
        throw new PrivConvertException('baseUrl must use https:// so the API key is never sent in clear text.');
    }

    private static function checkTool(string $tool): string
    {
        $t = strtolower(trim($tool));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D', $t)) {
            throw new PrivConvertException('Invalid tool id: ' . json_encode(substr($tool, 0, 80)));
        }
        return $t;
    }

    private static function checkJobId(Job|string $job): string
    {
        $id = $job instanceof Job ? $job->id : $job;
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $id)) {
            throw new PrivConvertException('Invalid job id.');
        }
        return $id;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array{0: string, 1: string}>
     */
    private static function formParams(array $params): array
    {
        $out = [];
        foreach ($params as $k => $v) {
            if ($v === null) {
                continue;
            }
            if (is_bool($v)) {
                $v = $v ? 'true' : 'false';
            } elseif (is_array($v) || is_object($v)) {
                $v = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
            $out[] = [(string) $k, (string) $v];
        }
        return $out;
    }

    private static function safeHeaderValue(string $s): string
    {
        $s = substr((string) preg_replace('/[\r\n"\\\\]/', '_', $s), 0, 255);
        return $s !== '' ? $s : 'file';
    }

    /**
     * @param list<array{0: string, 1: string}> $fields
     * @param list<array{0: string, 1: string, 2: string}> $files
     * @return array{0: string, 1: string}
     * @internal
     */
    public static function multipart(array $fields, array $files): array
    {
        $boundary = '----privconvert' . bin2hex(random_bytes(16));
        $body = '';
        foreach ($fields as [$name, $value]) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"" . self::safeHeaderValue($name) . "\"\r\n\r\n{$value}\r\n";
        }
        foreach ($files as [$name, $fname, $data]) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"" . self::safeHeaderValue($name)
                . '"; filename="' . self::safeHeaderValue($fname) . "\"\r\nContent-Type: application/octet-stream\r\n\r\n"
                . $data . "\r\n";
        }
        $body .= "--{$boundary}--\r\n";
        return [$body, "multipart/form-data; boundary={$boundary}"];
    }

    /** @return array{0: string, 1: ?string} */
    private static function parseError(string $raw, int $status): array
    {
        $fallback = ["HTTP {$status}", null];
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            return $fallback;
        }
        $err = $body['error'] ?? null;
        if (is_array($err)) {
            return [(string) ($err['message'] ?? "HTTP {$status}"), isset($err['code']) ? (string) $err['code'] : null];
        }
        $detail = $body['detail'] ?? null;
        if (is_string($detail)) {
            return [$detail, isset($body['code']) ? (string) $body['code'] : null];
        }
        if (is_array($detail)) {
            return [(string) ($detail['message'] ?? "HTTP {$status}"), isset($detail['code']) ? (string) $detail['code'] : null];
        }
        return $fallback;
    }

    /** @internal */
    public static function filenameFrom(string $cd): ?string
    {
        if (preg_match("/filename\\*=UTF-8''([^;]+)/i", $cd, $m)) {
            return self::baseName(rawurldecode(trim($m[1])));
        }
        if (preg_match('/filename="?([^";]+)"?/i', $cd, $m)) {
            return self::baseName($m[1]);
        }
        return null;
    }

    /** The part after the last slash; unlike basename() this is not locale dependent. */
    private static function baseName(string $path): ?string
    {
        $name = (string) preg_replace('~^.*[/\\\\]~s', '', $path);
        return $name !== '' ? $name : null;
    }
}
