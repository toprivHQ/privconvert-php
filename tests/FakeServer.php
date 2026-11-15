<?php

declare(strict_types=1);

namespace PrivConvert\Tests;

/** Runs tests/fixtures/server.php under PHP's built-in web server. */
final class FakeServer
{
    private static $proc = null;
    private static int $port = 0;
    public static string $log = '';

    public static function start(): string
    {
        if (self::$proc === null) {
            self::$log = tempnam(sys_get_temp_dir(), 'pc-log-');
            for ($try = 0; $try < 5 && self::$proc === null; $try++) {
                $port = random_int(20000, 60000);
                $cmd = [PHP_BINARY, '-d', 'enable_post_data_reading=0', '-S', "127.0.0.1:{$port}", __DIR__ . '/fixtures/server.php'];
                $proc = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['PC_TEST_LOG' => self::$log]);
                for ($i = 0; $i < 50; $i++) {
                    $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                    if ($s) {
                        fclose($s);
                        self::$proc = $proc;
                        self::$port = $port;
                        break;
                    }
                    usleep(50000);
                }
                if (self::$proc === null) {
                    proc_terminate($proc);
                }
            }
            register_shutdown_function(static function () {
                if (self::$proc) {
                    proc_terminate(self::$proc);
                }
                @unlink(self::$log);
            });
        }
        return 'http://127.0.0.1:' . self::$port . '/api';
    }

    /** @return list<array<string, mixed>> */
    public static function calls(): array
    {
        return array_map(static fn ($l) => json_decode($l, true), file(self::$log, FILE_IGNORE_NEW_LINES) ?: []);
    }

    /** @return array<string, mixed> */
    public static function lastCall(): array
    {
        $c = self::calls();
        return end($c);
    }
}
