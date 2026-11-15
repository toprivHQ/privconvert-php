<?php

declare(strict_types=1);

namespace PrivConvert\Storage;

use PrivConvert\Exception\StorageException;
use PrivConvert\Internal\Http;
use PrivConvert\Internal\TransportException;

/**
 * A small client for Azure Blob Storage over its REST API, with no dependencies.
 * Signs requests with an account key, a SAS token, or a Microsoft Entra token.
 */
final class AzureBlobClient
{
    private const API_VERSION = '2023-11-03';

    /** @var (\Closure(): string)|null */
    private ?\Closure $tokenProvider;

    private function __construct(
        public readonly string $accountName,
        private readonly string $endpoint,
        #[\SensitiveParameter] private readonly ?string $accountKey = null,
        #[\SensitiveParameter] private readonly ?string $sas = null,
        ?callable $tokenProvider = null,
        private readonly float $timeout = 300.0,
    ) {
        $this->tokenProvider = $tokenProvider === null ? null : \Closure::fromCallable($tokenProvider);
    }

    /** From a connection string, as shown under "Access keys" or "Shared access signature" in the portal. */
    public static function fromConnectionString(#[\SensitiveParameter] string $connectionString): self
    {
        $parts = [];
        foreach (explode(';', trim($connectionString)) as $pair) {
            $p = strpos($pair, '=');
            if ($p !== false) {
                $parts[strtolower(trim(substr($pair, 0, $p)))] = trim(substr($pair, $p + 1));
            }
        }
        if (strtolower($parts['usedevelopmentstorage'] ?? '') === 'true') {
            $parts += [
                'accountname' => 'devstoreaccount1',
                'accountkey' => 'Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==',
                'blobendpoint' => 'http://127.0.0.1:10000/devstoreaccount1',
            ];
        }
        $account = $parts['accountname'] ?? '';
        $endpoint = $parts['blobendpoint'] ?? '';
        if ($endpoint === '' && $account !== '') {
            $protocol = $parts['defaultendpointsprotocol'] ?? 'https';
            $suffix = $parts['endpointsuffix'] ?? 'core.windows.net';
            $endpoint = "{$protocol}://{$account}.blob.{$suffix}";
        }
        if ($account === '' && $endpoint !== '') {
            $host = (string) parse_url($endpoint, PHP_URL_HOST);
            $account = explode('.', $host)[0];
        }
        $key = $parts['accountkey'] ?? null;
        $sas = $parts['sharedaccesssignature'] ?? null;
        if ($account === '' || $endpoint === '' || ($key === null && $sas === null)) {
            throw new StorageException('The Azure connection string needs AccountName with AccountKey, or SharedAccessSignature.');
        }
        return new self($account, self::checkEndpoint($endpoint), $key, $sas !== null ? ltrim($sas, '?') : null);
    }

    public static function withAccountKey(string $account, #[\SensitiveParameter] string $accountKey, ?string $endpoint = null): self
    {
        return new self($account, self::checkEndpoint($endpoint ?? "https://{$account}.blob.core.windows.net"), accountKey: $accountKey);
    }

    public static function withSas(string $account, #[\SensitiveParameter] string $sas, ?string $endpoint = null): self
    {
        return new self($account, self::checkEndpoint($endpoint ?? "https://{$account}.blob.core.windows.net"), sas: ltrim($sas, '?'));
    }

    /**
     * Sign in with Microsoft Entra ID. $tokenProvider returns a bearer token for
     * https://storage.azure.com/ and is called before each request, so it can cache and refresh.
     */
    public static function withToken(string $account, callable $tokenProvider, ?string $endpoint = null): self
    {
        return new self($account, self::checkEndpoint($endpoint ?? "https://{$account}.blob.core.windows.net"), tokenProvider: $tokenProvider);
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: int, 1: array<string, string>, 2: string}
     * @throws TransportException
     * @internal
     */
    public function request(string $method, string $container, string $blob, array $headers = [], ?string $body = null, ?int $maxBytes = null): array
    {
        $path = '/' . rawurlencode($container) . '/' . implode('/', array_map('rawurlencode', explode('/', $blob)));
        $url = $this->endpoint . $path;
        $headers['x-ms-date'] = gmdate('D, d M Y H:i:s') . ' GMT';
        $headers['x-ms-version'] = self::API_VERSION;
        if ($body !== null) {
            $headers['Content-Length'] = (string) strlen($body);
        }
        if ($this->accountKey !== null) {
            $headers['Authorization'] = 'SharedKey ' . $this->accountName . ':' . $this->sign($method, (string) parse_url($url, PHP_URL_PATH), $headers);
        } elseif ($this->tokenProvider !== null) {
            $headers['Authorization'] = 'Bearer ' . ($this->tokenProvider)();
        } elseif ($this->sas !== null) {
            $url .= '?' . $this->sas;
        }
        return Http::send($method, $url, $headers, $body, $this->timeout, $maxBytes);
    }

    public function __debugInfo(): array
    {
        return ['accountName' => $this->accountName, 'endpoint' => $this->endpoint];
    }

    /** @param array<string, string> $headers */
    private function sign(string $method, string $path, array $headers): string
    {
        $h = array_change_key_case($headers, CASE_LOWER);
        $length = $h['content-length'] ?? '';
        $standard = [
            $method,
            $h['content-encoding'] ?? '',
            $h['content-language'] ?? '',
            $length === '0' ? '' : $length,
            $h['content-md5'] ?? '',
            $h['content-type'] ?? '',
            '',
            $h['if-modified-since'] ?? '',
            $h['if-match'] ?? '',
            $h['if-none-match'] ?? '',
            $h['if-unmodified-since'] ?? '',
            $h['range'] ?? '',
        ];
        $ms = array_filter($h, static fn ($k) => str_starts_with((string) $k, 'x-ms-'), ARRAY_FILTER_USE_KEY);
        ksort($ms, SORT_STRING);
        $canonHeaders = '';
        foreach ($ms as $k => $v) {
            $canonHeaders .= $k . ':' . trim((string) $v) . "\n";
        }
        $toSign = implode("\n", $standard) . "\n" . $canonHeaders . '/' . $this->accountName . $path;
        $key = base64_decode((string) $this->accountKey, true);
        if ($key === false) {
            throw new StorageException('The Azure account key is not valid base64.');
        }
        return base64_encode(hash_hmac('sha256', $toSign, $key, true));
    }

    private static function checkEndpoint(string $endpoint): string
    {
        $endpoint = rtrim($endpoint, '/');
        $scheme = parse_url($endpoint, PHP_URL_SCHEME);
        $host = parse_url($endpoint, PHP_URL_HOST);
        if ($scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true))) {
            return $endpoint;
        }
        throw new StorageException('The Azure endpoint must use https://.');
    }
}
