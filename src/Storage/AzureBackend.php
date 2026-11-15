<?php

declare(strict_types=1);

namespace PrivConvert\Storage;

use PrivConvert\Exception\StorageException;
use PrivConvert\Internal\BodyTooLargeException;
use PrivConvert\Internal\TransportException;

/** Azure Blob Storage, through the built-in AzureBlobClient. */
final class AzureBackend implements Backend
{
    public function __construct(private readonly AzureBlobClient $client)
    {
    }

    public static function default(): self
    {
        $conn = getenv('AZURE_STORAGE_CONNECTION_STRING');
        if (is_string($conn) && $conn !== '') {
            return new self(AzureBlobClient::fromConnectionString($conn));
        }
        throw new StorageException(
            'Azure Blob Storage needs AZURE_STORAGE_CONNECTION_STRING, or pass azureClient: '
            . 'AzureBlobClient::fromConnectionString(), withSas() or withToken().'
        );
    }

    public function exists(Location $loc): bool
    {
        [$status, $headers] = $this->send('check', $loc, 'HEAD');
        if ($status === 200) {
            return true;
        }
        if ($status === 404) {
            return false;
        }
        throw self::error('check', $loc, $status, $headers, '');
    }

    public function read(Location $loc): string
    {
        [$status, $headers] = $this->send('read', $loc, 'HEAD');
        if ($status !== 200) {
            throw self::error('read', $loc, $status, $headers, '');
        }
        Storage::checkSize($loc, $headers['content-length'] ?? null);
        // Pinning the ETag makes the size check hold for the bytes read.
        $cond = isset($headers['etag']) ? ['If-Match' => $headers['etag']] : [];
        [$status, $headers, $body] = $this->send('read', $loc, 'GET', $cond, null, self::MAX_OBJECT_BYTES);
        if ($status !== 200) {
            throw self::error('read', $loc, $status, $headers, $body);
        }
        return $body;
    }

    /** @param array<string, string> $uploadArgs extra request headers, e.g. ['x-ms-access-tier' => 'Cool'] */
    public function write(Location $loc, string $data, ?string $contentType, bool $overwrite, array $uploadArgs): void
    {
        $headers = [];
        foreach ($uploadArgs as $k => $v) {
            $headers[(string) $k] = (string) $v;
        }
        $type = $contentType ?: 'application/octet-stream';
        $headers += ['x-ms-blob-content-type' => $type];
        $headers['Content-Type'] = $type;
        $headers['x-ms-blob-type'] = 'BlockBlob';
        if (!$overwrite) {
            $headers['If-None-Match'] = '*';
        }
        [$status, $respHeaders, $body] = $this->send('write', $loc, 'PUT', $headers, $data);
        if ($status !== 201) {
            throw self::error('write', $loc, $status, $respHeaders, $body);
        }
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function send(string $action, Location $loc, string $method, array $headers = [], ?string $body = null, ?int $maxBytes = null): array
    {
        if ($loc->account !== null && $this->client->accountName !== $loc->account) {
            throw new StorageException("azureClient is for account '{$this->client->accountName}', but {$loc} is in '{$loc->account}'.");
        }
        try {
            return $this->client->request($method, $loc->bucket, $loc->key, $headers, $body, $maxBytes);
        } catch (BodyTooLargeException) {
            throw Storage::tooBig($loc);
        } catch (TransportException $e) {
            throw new StorageException("Could not {$action} {$loc}: " . $e->getMessage());
        }
    }

    /** @param array<string, string> $headers */
    private static function error(string $action, Location $loc, int $status, array $headers, string $body): StorageException
    {
        $code = $headers['x-ms-error-code'] ?? null;
        if ($code === null && preg_match('~<Code>([^<]{1,100})</Code>~', $body, $m)) {
            $code = $m[1];
        }
        $code ??= $status === 404 ? 'BlobNotFound' : (string) $status;
        return new StorageException("Could not {$action} {$loc}: {$code}", errorCode: $code);
    }
}
