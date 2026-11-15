<?php

declare(strict_types=1);

namespace PrivConvert\Exception;

/** Thrown by Webhook::verify(..., throw: true) when a delivery is not authentic. */
class WebhookVerificationException extends \RuntimeException
{
}
