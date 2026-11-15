<?php

declare(strict_types=1);

namespace PrivConvert;

use PrivConvert\Exception\PrivConvertException;

/** A converted file, held in memory. */
final class Result
{
    /** @param array<string, string> $headers lower-cased response headers */
    public function __construct(
        public readonly string $content,
        public readonly ?string $filename,
        public readonly ?string $contentType,
        public readonly array $headers = [],
    ) {
    }

    public function creditsRemaining(): ?int
    {
        return $this->intHeader('x-credits-remaining');
    }

    public function convertedCount(): ?int
    {
        return $this->intHeader('x-converted-count');
    }

    public function failedCount(): int
    {
        return $this->intHeader('x-failed-count') ?? 0;
    }

    /** True when a batch succeeded for only some of its files. */
    public function isPartial(): bool
    {
        return array_key_exists('x-partial-success', $this->headers);
    }

    /** @return array<string, mixed>|null */
    public function sanitizeReport(): ?array
    {
        $v = $this->headers['x-sanitize-report'] ?? '';
        if ($v === '') {
            return null;
        }
        $data = json_decode($v, true);
        return is_array($data) ? $data : null;
    }

    /** Write the result to $path and return the path. */
    public function save(string $path): string
    {
        if (@file_put_contents($path, $this->content) === false) {
            throw new PrivConvertException('Could not write the result to the given path.');
        }
        return $path;
    }

    public function __debugInfo(): array
    {
        return ['filename' => $this->filename, 'contentType' => $this->contentType, 'size' => strlen($this->content)];
    }

    private function intHeader(string $name): ?int
    {
        $v = $this->headers[$name] ?? '';
        return $v !== '' && ctype_digit($v) ? (int) $v : null;
    }
}
