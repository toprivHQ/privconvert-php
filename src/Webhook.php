<?php

declare(strict_types=1);

namespace PrivConvert;

use PrivConvert\Exception\WebhookVerificationException;

final class Webhook
{
    /**
     * Check an X-PrivConvert-Signature header against the raw request body.
     *
     * $rawBody must be the exact bytes received (file_get_contents('php://input')),
     * not re-encoded JSON. $jobKey is the one-time key returned when the job was
     * submitted. Deliveries older than $tolerance seconds are rejected as replays.
     */
    public static function verify(
        #[\SensitiveParameter] ?string $jobKey,
        ?string $signature,
        string $rawBody,
        int $tolerance = 300,
        ?int $now = null,
        bool $throw = false,
    ): bool {
        $fail = static function (string $reason) use ($throw): bool {
            if ($throw) {
                throw new WebhookVerificationException($reason);
            }
            return false;
        };
        if ($jobKey === null || $jobKey === '') {
            return $fail('missing job key');
        }
        if ($signature === null || $signature === '') {
            return $fail('missing signature header');
        }
        if (!preg_match('/^t=(\d{1,12}),v1=([0-9a-f]{64})$/D', trim($signature), $m)) {
            return $fail('malformed signature header');
        }
        $ts = (int) $m[1];
        if (abs(($now ?? time()) - $ts) > $tolerance) {
            return $fail('timestamp outside tolerance');
        }
        $sigKey = hash('sha256', 'pc-webhook-sig-v1|' . $jobKey, true);
        $expected = hash_hmac('sha256', $ts . '.' . $rawBody, $sigKey);
        if (!hash_equals($expected, $m[2])) {
            return $fail('signature mismatch');
        }
        return true;
    }
}
