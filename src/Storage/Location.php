<?php

declare(strict_types=1);

namespace PrivConvert\Storage;

use PrivConvert\Exception\StorageException;

/** A single file in cloud storage. */
final class Location
{
    private const BUCKET = '([a-z0-9][a-z0-9._-]{1,220}[a-z0-9])';
    private const ACCOUNT = '([a-z0-9]{3,24})';
    private const CONTAINER = '([a-z0-9](?:[a-z0-9-]{1,61}[a-z0-9])?)';

    /**
     * @param string $provider "s3", "gcs" or "azure"
     * @param string $bucket S3 / GCS bucket, or Azure container
     * @param string|null $account Azure storage account
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $bucket,
        public readonly string $key,
        public readonly ?string $account = null,
    ) {
    }

    /**
     * Parse s3://bucket/key, gs://bucket/key, az://account/container/blob or
     * https://account.blob.core.windows.net/container/blob.
     */
    public static function parse(string $url): self
    {
        if (preg_match('~^s3://' . self::BUCKET . '/(.+)$~D', $url, $m)) {
            $loc = new self('s3', $m[1], $m[2]);
        } elseif (preg_match('~^gs://' . self::BUCKET . '/(.+)$~D', $url, $m)) {
            $loc = new self('gcs', $m[1], $m[2]);
        } elseif (
            preg_match('~^az://' . self::ACCOUNT . '/' . self::CONTAINER . '/(.+)$~D', $url, $m)
            || preg_match('~^https://' . self::ACCOUNT . '\.blob\.core\.windows\.net/' . self::CONTAINER . '/(.+)$~D', $url, $m)
        ) {
            $loc = new self('azure', $m[2], $m[3], $m[1]);
        } else {
            throw new StorageException(
                'Expected a location like s3://bucket/file, gs://bucket/file, '
                . 'az://account/container/file or https://account.blob.core.windows.net/container/file.'
            );
        }
        if (str_ends_with($loc->key, '/') || str_contains($loc->key, '?') || str_contains($loc->key, '#')) {
            throw new StorageException("{$loc} must point to a single file.");
        }
        return $loc;
    }

    public function __toString(): string
    {
        return match ($this->provider) {
            's3' => "s3://{$this->bucket}/{$this->key}",
            'gcs' => "gs://{$this->bucket}/{$this->key}",
            default => "az://{$this->account}/{$this->bucket}/{$this->key}",
        };
    }
}
