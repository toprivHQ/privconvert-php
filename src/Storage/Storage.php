<?php

declare(strict_types=1);

namespace PrivConvert\Storage;

use PrivConvert\Exception\StorageException;

/** @internal */
final class Storage
{
    public const CLOUD_INSTALL = 'composer require aws/aws-sdk-php google/cloud-storage';

    /**
     * The backend for a location, using the given client or the provider's default credentials.
     * A client may also be your own Backend implementation.
     */
    public static function backend(Location $loc, ?object $s3Client = null, ?object $gcsClient = null, ?object $azureClient = null): Backend
    {
        $client = match ($loc->provider) {
            's3' => $s3Client,
            'gcs' => $gcsClient,
            default => $azureClient,
        };
        if ($client instanceof Backend) {
            return $client;
        }
        return match ($loc->provider) {
            's3' => $client !== null ? new S3Backend($client) : S3Backend::default(),
            'gcs' => $client !== null ? new GcsBackend($client) : GcsBackend::default(),
            default => match (true) {
                $client instanceof AzureBlobClient => new AzureBackend($client),
                $client === null => AzureBackend::default(),
                default => throw new StorageException('azureClient must be a PrivConvert\Storage\AzureBlobClient.'),
            },
        };
    }

    public static function checkSize(Location $loc, mixed $size): void
    {
        if (is_numeric($size) && (int) $size > Backend::MAX_OBJECT_BYTES) {
            throw self::tooBig($loc);
        }
    }

    public static function tooBig(Location $loc): StorageException
    {
        return new StorageException("{$loc} is larger than " . intdiv(Backend::MAX_OBJECT_BYTES, 1024 * 1024) . ' MB.');
    }
}
