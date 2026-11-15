<?php

declare(strict_types=1);

namespace PrivConvert\Storage;

use PrivConvert\Exception\StorageException;

/** Amazon S3 and S3-compatible storage (Backblaze B2, Cloudflare R2, ...), through aws/aws-sdk-php. */
final class S3Backend implements Backend
{
    /** @param \Aws\S3\S3ClientInterface $client */
    public function __construct(private readonly object $client)
    {
    }

    public static function default(): self
    {
        if (!class_exists(\Aws\S3\S3Client::class)) {
            throw new StorageException('Amazon S3 support needs the AWS SDK: ' . Storage::CLOUD_INSTALL);
        }
        $region = getenv('AWS_REGION') ?: getenv('AWS_DEFAULT_REGION') ?: 'us-east-1';
        return new self(new \Aws\S3\S3Client(['region' => $region]));
    }

    public function exists(Location $loc): bool
    {
        try {
            $this->client->headObject(['Bucket' => $loc->bucket, 'Key' => $loc->key]);
            return true;
        } catch (\Throwable $e) {
            if (in_array(self::code($e), ['404', 'NoSuchKey', 'NotFound'], true)) {
                return false;
            }
            throw self::error('check', $loc, $e);
        }
    }

    public function read(Location $loc): string
    {
        try {
            $resp = $this->client->getObject([
                'Bucket' => $loc->bucket,
                'Key' => $loc->key,
                '@http' => ['stream' => true],
            ]);
        } catch (\Throwable $e) {
            throw self::error('read', $loc, $e);
        }
        $body = $resp['Body'];
        try {
            Storage::checkSize($loc, $resp['ContentLength'] ?? null);
            $data = '';
            while (!$body->eof() && strlen($data) <= self::MAX_OBJECT_BYTES) {
                $data .= $body->read(1024 * 1024);
            }
        } catch (StorageException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw self::error('read', $loc, $e);
        } finally {
            $body->close();
        }
        if (strlen($data) > self::MAX_OBJECT_BYTES) {
            throw Storage::tooBig($loc);
        }
        return $data;
    }

    public function write(Location $loc, string $data, ?string $contentType, bool $overwrite, array $uploadArgs): void
    {
        $args = $uploadArgs;
        if ($contentType !== null && $contentType !== '' && !isset($args['ContentType'])) {
            $args['ContentType'] = $contentType;
        }
        $args = array_merge($args, ['Bucket' => $loc->bucket, 'Key' => $loc->key, 'Body' => $data]);
        try {
            if ($overwrite) {
                $this->client->putObject($args);
                return;
            }
            try {
                $this->client->putObject($args + ['IfNoneMatch' => '*']);
            } catch (\Throwable $e) {
                if (in_array(self::code($e), ['PreconditionFailed', 'ConditionalRequestConflict'], true)) {
                    throw $e;
                }
                // Some S3-compatible services (Backblaze B2 among them) reject the
                // condition itself, so fall back to checking just before writing.
                if ($this->exists($loc)) {
                    throw new StorageException("{$loc} already exists.", errorCode: 'DestinationExists');
                }
                $this->client->putObject($args);
            }
        } catch (StorageException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw self::error('write', $loc, $e);
        }
    }

    private static function code(\Throwable $e): ?string
    {
        if (method_exists($e, 'getAwsErrorCode') && $e->getAwsErrorCode()) {
            return (string) $e->getAwsErrorCode();
        }
        if (method_exists($e, 'getStatusCode') && $e->getStatusCode()) {
            return (string) $e->getStatusCode();
        }
        return null;
    }

    private static function error(string $action, Location $loc, \Throwable $e): StorageException
    {
        $code = self::code($e);
        return new StorageException("Could not {$action} {$loc}: " . ($code ?? (new \ReflectionClass($e))->getShortName()), errorCode: $code);
    }
}
