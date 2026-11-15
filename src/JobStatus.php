<?php

declare(strict_types=1);

namespace PrivConvert;

final class JobStatus
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly array $raw = [],
    ) {
    }

    public function isDone(): bool
    {
        return $this->status === 'finished' || $this->status === 'failed';
    }

    /** @return array<string, mixed>|null */
    public function error(): ?array
    {
        $e = $this->raw['error'] ?? null;
        return is_array($e) ? $e : null;
    }

    public function expiresAt(): ?int
    {
        $v = $this->raw['expires_at'] ?? null;
        return is_int($v) ? $v : null;
    }
}
