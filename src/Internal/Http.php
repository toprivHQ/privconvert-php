<?php

declare(strict_types=1);

namespace PrivConvert\Internal;

/**
 * A minimal cURL wrapper that keeps request and response bodies in memory.
 *
 * @internal
 */
final class Http
{
    /**
     * @param array<string, string> $headers
     * @return array{0: int, 1: array<string, string>, 2: string}
     * @throws TransportException
     * @throws BodyTooLargeException when $maxBytes is set and the body is larger
     */
    public static function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeout,
        ?int $maxBytes = null,
    ): array {
        $ch = curl_init();
        if ($ch === false) {
            throw new TransportException('Could not start a cURL session.');
        }
        $lines = ['Expect:'];
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        $respHeaders = [];
        $buffer = '';
        $tooBig = false;
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT_MS => (int) round($timeout * 1000),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $respHeaders = [];
                } elseif (($p = strpos($line, ':')) !== false) {
                    $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer, &$tooBig, $maxBytes): int {
                if ($maxBytes !== null && strlen($buffer) + strlen($chunk) > $maxBytes) {
                    $tooBig = true;
                    return -1;
                }
                $buffer .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        } elseif ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($tooBig) {
            throw new BodyTooLargeException('The response body is larger than allowed.');
        }
        if ($ok === false) {
            throw new TransportException($error !== '' ? $error : 'request failed');
        }
        return [$status, $respHeaders, $buffer];
    }
}
