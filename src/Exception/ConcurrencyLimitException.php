<?php

declare(strict_types=1);

namespace PrivConvert\Exception;

/** 429 concurrency_limited: too many conversions running at once. */
class ConcurrencyLimitException extends RateLimitException
{
}
