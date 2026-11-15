<?php

declare(strict_types=1);

namespace PrivConvert\Tests;

use PHPUnit\Framework\TestCase;
use PrivConvert\Exception\WebhookVerificationException;
use PrivConvert\Webhook;

final class WebhookTest extends TestCase
{
    private const KEY = 'pjk_testvector_do_not_use_0000000000000000000';
    private const TS = 1760000000;
    private const BODY = '{"event":"job.finished","event_id":"evt_00000000000000000000000000000000",'
        . '"id":"job_000000000000000000000000","status":"finished","tool":"txt-to-pdf"}';
    private const SIG = 't=1760000000,v1=609fa0d5493200864ae983137780d6fa6cfd7946953052fcaf4f6d4b8792803f';

    public function testPublishedVector(): void
    {
        $this->assertTrue(Webhook::verify(self::KEY, self::SIG, self::BODY, now: self::TS));
    }

    public function testTamperedBody(): void
    {
        $this->assertFalse(Webhook::verify(self::KEY, self::SIG, self::BODY . ' ', now: self::TS));
    }

    public function testWrongKey(): void
    {
        $this->assertFalse(Webhook::verify(self::KEY . 'x', self::SIG, self::BODY, now: self::TS));
    }

    public function testStaleTimestamp(): void
    {
        $this->assertFalse(Webhook::verify(self::KEY, self::SIG, self::BODY, now: self::TS + 301));
    }

    public function testMalformed(): void
    {
        foreach (['', 't=1,v1=zz', 'v1=abc', null, 't=1760000000,v1=' . str_repeat('0', 63), self::SIG . "\nx"] as $sig) {
            $this->assertFalse(Webhook::verify(self::KEY, $sig, self::BODY, now: self::TS));
        }
    }

    public function testThrow(): void
    {
        $this->expectException(WebhookVerificationException::class);
        Webhook::verify(self::KEY, 'bad', self::BODY, throw: true);
    }
}
