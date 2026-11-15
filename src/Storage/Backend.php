<?php

declare(strict_types=1);

namespace PrivConvert\Storage;

use PrivConvert\Exception\StorageException;

/** Reads and writes single files in one cloud. */
interface Backend
{
    /** The largest file any plan accepts. Checked before the object is read. */
    public const MAX_OBJECT_BYTES = 500 * 1024 * 1024;

    /** @throws StorageException */
    public function exists(Location $loc): bool;

    /** @throws StorageException */
    public function read(Location $loc): string;

    /**
     * @param array<string, mixed> $uploadArgs provider-specific upload options
     * @throws StorageException
     */
    public function write(Location $loc, string $data, ?string $contentType, bool $overwrite, array $uploadArgs): void;
}
