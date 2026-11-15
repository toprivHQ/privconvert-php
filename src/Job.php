<?php

declare(strict_types=1);

namespace PrivConvert;

/** A submitted async job. The key is returned exactly once: store it. */
final class Job
{
    public function __construct(
        public readonly string $id,
        #[\SensitiveParameter] public readonly string $key,
        public readonly string $status = 'processing',
    ) {
    }

    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'status' => $this->status];
    }
}
