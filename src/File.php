<?php

declare(strict_types=1);

namespace PrivConvert;

use PrivConvert\Exception\PrivConvertException;

/** A file held in memory, ready to upload. */
final class File
{
    public function __construct(
        public readonly string $content,
        public readonly string $filename = 'file',
    ) {
    }

    /** Data you already have in memory. */
    public static function fromString(string $content, string $filename = 'file'): self
    {
        return new self($content, $filename !== '' ? $filename : 'file');
    }

    public static function fromPath(string $path, ?string $filename = null): self
    {
        if ($path === '' || str_contains($path, "\0") || !is_file($path)) {
            throw new PrivConvertException(
                'File not found. Pass a path, an open stream, or File::fromString() for data in memory.'
            );
        }
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new PrivConvertException('Could not read the file at the given path.');
        }
        return new self($data, $filename ?? (basename($path) ?: 'file'));
    }

    /** @param resource $stream */
    public static function fromStream($stream, ?string $filename = null): self
    {
        $data = stream_get_contents($stream);
        if ($data === false) {
            throw new PrivConvertException('Could not read from the stream.');
        }
        if ($filename === null) {
            $uri = stream_get_meta_data($stream)['uri'] ?? '';
            $filename = is_string($uri) && $uri !== '' && !str_contains($uri, '://') ? basename($uri) : 'file';
        }
        return new self($data, $filename !== '' ? $filename : 'file');
    }

    /**
     * @param string|resource|File $file a path, an open stream, or a File
     * @internal
     */
    public static function from(mixed $file, ?string $filename = null): self
    {
        if ($file instanceof self) {
            return $filename === null ? $file : new self($file->content, $filename);
        }
        if (is_resource($file)) {
            return self::fromStream($file, $filename);
        }
        if (is_string($file)) {
            return self::fromPath($file, $filename);
        }
        throw new PrivConvertException('file must be a path, an open stream, or a PrivConvert\File.');
    }

    public function __debugInfo(): array
    {
        return ['filename' => $this->filename, 'size' => strlen($this->content)];
    }
}
