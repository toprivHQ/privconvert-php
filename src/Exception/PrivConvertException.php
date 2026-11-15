<?php

declare(strict_types=1);

namespace PrivConvert\Exception;

/** Base class for every error thrown by this package. */
class PrivConvertException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $errorCode = null,
        public readonly ?float $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    /**
     * The exception for an API error response.
     *
     * @internal
     */
    public static function fromResponse(int $status, string $message, ?string $code, ?float $retryAfter, bool $job = false): self
    {
        if ($status === 429) {
            $class = $code === 'concurrency_limited' ? ConcurrencyLimitException::class : RateLimitException::class;
        } elseif ($status === 404 && $job) {
            $class = JobNotFoundException::class;
        } elseif ($status >= 500) {
            $class = ServerException::class;
        } else {
            $class = match ($status) {
                400 => BadRequestException::class,
                401, 403 => AuthenticationException::class,
                410 => JobExpiredException::class,
                413 => FileTooLargeException::class,
                415 => UnsupportedTypeException::class,
                422 => ConversionFailedException::class,
                default => self::class,
            };
        }
        return new $class($message, $status, $code, $retryAfter);
    }
}
