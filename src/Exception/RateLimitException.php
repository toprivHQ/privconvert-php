<?php

declare(strict_types=1);

namespace PrivConvert\Exception;

/** 429 rate_limited: per-minute rate or monthly quota exceeded. */
class RateLimitException extends PrivConvertException
{
}
