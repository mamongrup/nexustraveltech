<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WebhooksTest extends TestCase
{
    public function testSignatureMatchesHmacSha256(): void
    {
        $body = '{"booking_reference":"NXR-TEST"}';
        $this->assertSame(hash_hmac('sha256', $body, 'secret'), webhook_signature('secret', $body));
    }

    public function testSignatureChangesWithBody(): void
    {
        $a = webhook_signature('secret', '{"a":1}');
        $b = webhook_signature('secret', '{"a":2}');
        $this->assertNotSame($a, $b);
    }

    public function testSignatureChangesWithSecret(): void
    {
        $a = webhook_signature('secret-1', '{"a":1}');
        $b = webhook_signature('secret-2', '{"a":1}');
        $this->assertNotSame($a, $b);
    }

    public function testEventMatches(): void
    {
        $this->assertTrue(webhook_event_matches(['booking.created', 'booking.cancelled'], 'booking.created'));
        $this->assertFalse(webhook_event_matches(['booking.created'], 'booking.cancelled'));
        $this->assertFalse(webhook_event_matches([], 'booking.created'));
    }
}
