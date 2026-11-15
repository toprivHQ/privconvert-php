<?php

declare(strict_types=1);

namespace PrivConvert\Exception;

/** Reading from or writing to your cloud storage failed. $errorCode holds the provider's code when there is one, e.g. NoSuchKey or AccessDenied. */
class StorageException extends PrivConvertException
{
}
