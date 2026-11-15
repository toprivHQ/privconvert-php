<?php

declare(strict_types=1);

namespace PrivConvert\Exception;

/** 410: the job result passed its retention window and was deleted. */
class JobExpiredException extends PrivConvertException
{
}
