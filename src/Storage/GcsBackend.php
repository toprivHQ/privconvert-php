<?php

declare(strict_types=1);

namespace PrivConvert\Storage;

use PrivConvert\Exception\StorageException;

/** Google Cloud Storage, through google/cloud-storage. */
final class GcsBackend implements Backend
{
    /** @param \Google\Cloud\Storage\StorageClient $client */
    public function __construct(private readonly object $client)
    {
    }

    public static function default(): self
    {
        if (!class_exists(\Google\Cloud\Storage\StorageClient::class)) {
            throw new StorageException('Google Cloud Storage support needs google/cloud-storage: ' . Storage::CLOUD_INSTALL);
        }
        return new self(new \Google\Cloud\Storage\StorageClient());
    }

    public function exists(Location $loc): bool
    {
        try {
            return (bool) $this->object($loc)->exists();
        } catch (\Throwable $e) {
            throw self::error('check', $loc, $e);
        }
    }

    public function read(Location $loc): string
    {
        try {
            $object = $this->object($loc);
            $info = $object->info();
            Storage::checkSize($loc, $info['size'] ?? null);
            // Pinning the generation makes the size check hold for the bytes read.
            $stream = $object->downloadAsStream(['generation' => $info['generation'] ?? null]);
            $data = '';
            while (!$stream->eof() && strlen($data) <= self::MAX_OBJECT_BYTES) {
                $data .= $stream->read(1024 * 1024);
            }
            $stream->close();
        } catch (StorageException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw self::error('read', $loc, $e);
        }
        if (strlen($data) > self::MAX_OBJECT_BYTES) {
            throw Storage::tooBig($loc);
        }
        return $data;
    }

    public function write(Location $loc, string $data, ?string $contentType, bool $overwrite, array $uploadArgs): void
    {
        $args = $uploadArgs;
        $args['metadata'] = ($args['metadata'] ?? []) + ['contentType' => $contentType ?: 'application/octet-stream'];
        $args['name'] = $loc->key;
        $args['resumable'] ??= false;
        if (!$overwrite) {
            $args['ifGenerationMatch'] = 0;
        }
        try {
            $this->client->bucket($loc->bucket)->upload($data, $args);
        } catch (\Throwable $e) {
            throw self::error('write', $loc, $e);
        }
    }

    /** @return \Google\Cloud\Storage\StorageObject */
    private function object(Location $loc): object
    {
        return $this->client->bucket($loc->bucket)->object($loc->key);
    }

    private static function code(\Throwable $e): ?string
    {
        $status = $e->getCode();
        if (!is_int($status) || $status === 0) {
            return null;
        }
        return [403 => 'Forbidden', 404 => 'NotFound', 412 => 'PreconditionFailed'][$status] ?? (string) $status;
    }

    private static function error(string $action, Location $loc, \Throwable $e): StorageException
    {
        $code = self::code($e);
        return new StorageException("Could not {$action} {$loc}: " . ($code ?? (new \ReflectionClass($e))->getShortName()), errorCode: $code);
    }
}
