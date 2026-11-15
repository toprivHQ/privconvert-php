<?php

declare(strict_types=1);

namespace PrivConvert\Tests;

use PHPUnit\Framework\TestCase;
use PrivConvert\Exception;
use PrivConvert\File;
use PrivConvert\Job;
use PrivConvert\PrivConvert;

final class ClientTest extends TestCase
{
    private static PrivConvert $pc;

    public static function setUpBeforeClass(): void
    {
        self::$pc = new PrivConvert('pk_live_test', baseUrl: FakeServer::start());
    }

    public function testRequiresKey(): void
    {
        $this->expectException(Exception\PrivConvertException::class);
        new PrivConvert('');
    }

    public function testRejectsKeyWithWhitespace(): void
    {
        $this->expectException(Exception\PrivConvertException::class);
        new PrivConvert("pk_live_a\nb");
    }

    public function testRefusesPlainHttp(): void
    {
        $this->expectException(Exception\PrivConvertException::class);
        new PrivConvert('pk_live_x', baseUrl: 'http://example.com/api');
    }

    public function testAllowsLocalHttp(): void
    {
        $this->assertSame('http://127.0.0.1:9/api', (new PrivConvert('pk_live_x', baseUrl: 'http://127.0.0.1:9/api/'))->baseUrl);
    }

    public function testKeyNotInDumps(): void
    {
        $pc = new PrivConvert('pk_live_supersecret');
        $this->assertStringNotContainsString('supersecret', print_r($pc, true));
        ob_start();
        var_dump($pc);
        $this->assertStringNotContainsString('supersecret', (string) ob_get_clean());
        $this->assertStringNotContainsString('pjk_secret', print_r(new Job('job_1', 'pjk_secret'), true));
    }

    public function testBadTool(): void
    {
        foreach (['../me', 'a/b', '', 'Word To PDF'] as $tool) {
            try {
                self::$pc->convert($tool, File::fromString('x'));
                $this->fail("accepted {$tool}");
            } catch (Exception\PrivConvertException $e) {
                $this->assertStringStartsWith('Invalid tool id', $e->getMessage());
            }
        }
    }

    public function testMissingPathDoesNotEchoInput(): void
    {
        try {
            self::$pc->convert('word-to-pdf', 'secret file content, not a path');
            $this->fail('accepted');
        } catch (Exception\PrivConvertException $e) {
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function testMultipartHeaderInjection(): void
    {
        [$body] = PrivConvert::multipart([], [['file', "evil\"\r\nX-Injected: 1", 'data']]);
        $this->assertStringNotContainsString("\r\nX-Injected", $body);
    }

    public function testFilenameParse(): void
    {
        $this->assertSame('a.pdf', PrivConvert::filenameFrom('attachment; filename="a.pdf"'));
        $this->assertSame("\u{05d0}.pdf", PrivConvert::filenameFrom("attachment; filename*=UTF-8''%D7%90.pdf"));
        $this->assertSame('x', PrivConvert::filenameFrom('attachment; filename="../../etc/x"'));
    }

    public function testConvert(): void
    {
        $r = self::$pc->convert('word-to-pdf', File::fromString('hello', 'a.docx'), ['quality' => 80, 'flat' => true, 'skip' => null]);
        $this->assertSame('%PDF-1.4', $r->content);
        $this->assertSame('a.pdf', $r->filename);
        $this->assertSame('application/pdf', $r->contentType);
        $this->assertSame(41, $r->creditsRemaining());
        $call = FakeServer::lastCall();
        $this->assertSame('Bearer pk_live_test', $call['auth']);
        $this->assertSame('privconvert-php/' . PrivConvert::VERSION, $call['ua']);
        $this->assertSame(['quality' => '80', 'flat' => 'true'], $call['post']);
    }

    public function testConvertFromPathAndStream(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pc');
        rename($tmp, $tmp . '.docx');
        file_put_contents($tmp . '.docx', 'hi');
        try {
            $this->assertSame(basename($tmp) . '.pdf', self::$pc->convert('word-to-pdf', $tmp . '.docx')->filename);
            $fh = fopen($tmp . '.docx', 'rb');
            $this->assertSame(basename($tmp) . '.pdf', self::$pc->convert('word-to-pdf', $fh)->filename);
            fclose($fh);
        } finally {
            unlink($tmp . '.docx');
        }
    }

    public function testBatch(): void
    {
        $r = self::$pc->convertBatch('json-to-csv', [File::fromString('[1]'), File::fromString('[2]', 'two.json')]);
        $this->assertTrue($r->isPartial());
        $this->assertSame(1, $r->failedCount());
        $this->assertSame(2, $r->convertedCount());
        $call = FakeServer::lastCall();
        $this->assertSame('json-to-csv', $call['post']['tool']);
        $this->assertSame(['file1', 'two.json'], $call['files']['files']);
    }

    public function testBatchLimits(): void
    {
        $this->expectException(Exception\PrivConvertException::class);
        self::$pc->convertBatch('json-to-csv', array_fill(0, 21, File::fromString('x')));
    }

    public function testRetriesBusy(): void
    {
        $this->assertSame('OK', self::$pc->convert('busy', File::fromString('x'))->content);
    }

    public function testNoLongSleepOnQuota(): void
    {
        $t = microtime(true);
        try {
            self::$pc->convert('quota', File::fromString('x'));
            $this->fail('no exception');
        } catch (Exception\RateLimitException $e) {
            $this->assertSame(86400.0, $e->retryAfter);
            $this->assertSame('quota used', $e->getMessage());
            $this->assertSame(429, $e->status);
        }
        $this->assertLessThan(5, microtime(true) - $t);
    }

    public function testErrorTypes(): void
    {
        try {
            self::$pc->convert('conc', File::fromString('x'));
            $this->fail('no exception');
        } catch (Exception\ConcurrencyLimitException $e) {
            $this->assertInstanceOf(Exception\RateLimitException::class, $e);
            $this->assertSame('concurrency_limited', $e->errorCode);
        }
        $this->expectException(Exception\ConversionFailedException::class);
        self::$pc->convert('bad', File::fromString('x'));
    }

    public function testMeAndTools(): void
    {
        $this->assertSame('growth', self::$pc->me()['tier']);
        $tools = self::$pc->tools('data');
        $this->assertSame('json-to-csv', $tools[0]['id']);
        $this->assertSame('data', $tools[0]['category']);
        $this->assertNull(FakeServer::lastCall()['auth']);
    }

    public function testJobFlow(): void
    {
        $job = self::$pc->submitJob('json-to-csv', File::fromString('[]'), webhookUrl: 'https://example.com/hook');
        $this->assertSame('job_abc', $job->id);
        $this->assertSame('https://example.com/hook', FakeServer::lastCall()['post']['webhook_url']);
        $r = self::$pc->wait($job, pollInterval: 0.5);
        $this->assertSame('a,b', $r->content);
        $this->assertSame("\u{05d0}.csv", $r->filename);
        $this->assertSame('/api/jobs/job_abc', FakeServer::lastCall()['path']);
    }

    public function testFailedJobRaisesTypedError(): void
    {
        $this->expectException(Exception\UnsupportedTypeException::class);
        self::$pc->wait(new Job('job_fail', 'k'));
    }

    public function testWebhookUrlMustBeHttps(): void
    {
        $this->expectException(Exception\PrivConvertException::class);
        self::$pc->submitJob('json-to-csv', File::fromString('[]'), webhookUrl: 'http://example.com/hook');
    }
}
