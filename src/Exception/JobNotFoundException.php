<?php

declare(strict_types=1);

namespace PrivConvert\Exception;

/** 404 for a job: deleted, never existed, or lost in a service restart. */
class JobNotFoundException extends PrivConvertException
{
}
