<?php

declare(strict_types=1);

namespace PrivConvert\Exception;

/** 5xx: a problem on the server side. Safe to retry. */
class ServerException extends PrivConvertException
{
}
