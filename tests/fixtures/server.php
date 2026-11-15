<?php

// Router for `php -S`: a stand-in for the API used by the offline tests.

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$log = getenv('PC_TEST_LOG') ?: sys_get_temp_dir() . '/pc-test-log';

// Started with enable_post_data_reading=0 so repeated file fields are all visible.
$post = [];
$files = [];
if (preg_match('/boundary=(.+)$/', $_SERVER['CONTENT_TYPE'] ?? '', $bm)) {
    foreach (explode('--' . $bm[1], (string) file_get_contents('php://input')) as $part) {
        if (!preg_match('/name="([^"]*)"(?:; filename="([^"]*)")?\r\n(?:[^\r\n]+\r\n)*\r\n(.*)\r\n$/s', $part, $pm)) {
            continue;
        }
        if (($pm[2] ?? '') !== '') {
            $files[$pm[1]][] = $pm[2];
        } else {
            $post[$pm[1]] = $pm[3];
        }
    }
}
file_put_contents($log, json_encode([
    'path' => $path,
    'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    'post' => $post,
    'files' => $files,
]) . "\n", FILE_APPEND);

function reply(int $status, string $body, array $headers = []): void
{
    http_response_code($status);
    foreach ($headers as $k => $v) {
        header("{$k}: {$v}");
    }
    echo $body;
}

function calls(string $path, string $log): int
{
    $n = 0;
    foreach (file($log) ?: [] as $line) {
        $n += (json_decode($line, true)['path'] ?? '') === $path ? 1 : 0;
    }
    return $n;
}

switch ($path) {
    case '/api/convert/busy':
        if (calls($path, $log) < 2) {
            reply(503, '{"error":{"code":"server_busy","message":"busy"}}', ['Retry-After' => '0', 'Content-Type' => 'application/json']);
        } else {
            reply(200, 'OK', ['Content-Disposition' => 'attachment; filename="out.txt"']);
        }
        break;
    case '/api/convert/quota':
        reply(429, '{"detail":"quota used"}', ['Retry-After' => '86400', 'Content-Type' => 'application/json']);
        break;
    case '/api/convert/conc':
        reply(429, '{"error":{"code":"concurrency_limited","message":"slow"}}', ['Retry-After' => '0']);
        break;
    case '/api/convert/bad':
        reply(422, '{"error":{"code":"conversion_failed","message":"could not convert"}}');
        break;
    case '/api/convert-batch':
        reply(200, 'PK', [
            'Content-Type' => 'application/zip',
            'X-Converted-Count' => '2',
            'X-Failed-Count' => '1',
            'X-Partial-Success' => 'true',
        ]);
        break;
    case '/api/me':
        reply(200, '{"tier":"growth","remaining":5}', ['Content-Type' => 'application/json']);
        break;
    case '/api/tools':
        reply(200, '{"tools":[{"id":"json-to-csv","category":"' . ($_GET['category'] ?? '') . '"}]}');
        break;
    case '/api/jobs/json-to-csv':
        reply(200, '{"id":"job_abc","key":"pjk_secret","status":"processing"}');
        break;
    case '/api/jobs/job_abc':
        if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            reply(204, '');
        } elseif (calls($path, $log) < 2) {
            reply(200, '{"status":"processing"}');
        } else {
            reply(200, '{"status":"finished"}');
        }
        break;
    case '/api/jobs/job_abc/download':
        if (($_SERVER['HTTP_X_JOB_KEY'] ?? '') !== 'pjk_secret') {
            reply(403, '{"detail":"bad key"}');
        } else {
            reply(200, 'a,b', ['Content-Disposition' => "attachment; filename*=UTF-8''%D7%90.csv"]);
        }
        break;
    case '/api/jobs/job_fail':
        reply(200, '{"status":"failed","error":{"code":"unsupported_type","message":"nope"}}');
        break;
    default:
        $name = $files['file'][0] ?? 'x';
        reply(200, '%PDF-1.4', [
            'Content-Disposition' => 'attachment; filename="' . pathinfo($name, PATHINFO_FILENAME) . '.pdf"',
            'Content-Type' => 'application/pdf',
            'X-Credits-Remaining' => '41',
        ]);
}
