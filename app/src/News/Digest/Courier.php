<?php

declare(strict_types=1);

namespace Psc\News\Digest;

use Psc\Mail\Delivery;

/**
 * The digest's emails (docs/NEWS.md §14.3): DigestMail sends them, tests/Fakes/FakeCourier stands in for it. Every
 * caller takes the courier from DigestMail::courier($app).
 *
 * $subscriber is a news_digest_subscribers row (email, selector, source and confirmed_at are read). $digest is the
 * digest shape: {id: ?int, week: string, send_at: int, window_start: int, window_end: int, articles: list<{id: int,
 * title: string, dek: string, category: string, category_name: string, path: string}>, left_out: int, sample: bool}.
 */
interface Courier
{
    /** @param array<string, mixed> $subscriber the confirmation email to the row's address; whether it was taken */
    public function confirmation(array $subscriber): bool;

    /**
     * The digest to one subscriber, delivered in this call, with its outcome.
     *
     * @param array<string, mixed> $digest
     * @param array<string, mixed> $subscriber
     */
    public function digest(array $digest, array $subscriber): Delivery;

    /**
     * The digest to $to as a test: the test selector in its links and headers, so they change nothing.
     *
     * @param array<string, mixed> $digest
     */
    public function test(array $digest, string $to): Delivery;

    /**
     * The message as it would be sent to $subscriber; with null, the desk's preview (the test selector and the
     * footer's sample words).
     *
     * @param array<string, mixed> $digest
     * @param array<string, mixed>|null $subscriber
     * @return array{subject: string, preheader: string, html: string, text: string, headers: array<string, string>}
     */
    public function render(array $digest, ?array $subscriber): array;
}
