<?php

declare(strict_types=1);

namespace Psc\Tests\Fakes;

use Psc\Db\Database;
use Psc\Mail\Delivery;
use Psc\News\Digest\Courier;
use Psc\News\Digest\Tokens;

/**
 * The tests' stand-in for the digest's emails (docs/NEWS.md §14.3 Courier): it sends nothing and records every call.
 * digest() answers the next scripted outcome (a Delivery, or a Throwable it throws), Delivery::sent() when the script
 * is empty, and first checks that the recipient's send row is already claimed in the database, so a test proves the
 * claim was written before the message went.
 */
final class FakeCourier implements Courier
{
    /** @var list<array{kind: string, to: string, digest: array<string, mixed>|null, subscriber: array<string, mixed>|null, answer: Delivery|\Throwable|null}> */
    public array $calls = [];

    /** @var list<Delivery|\Throwable> the outcomes of the next digest() calls, in order */
    public array $script = [];

    /** @var list<Delivery|\Throwable> the outcomes of the next test() calls, in order */
    public array $tests = [];

    /** Runs before each digest() answers (a test's clock moves on here). */
    public ?\Closure $onDigest = null;

    public function __construct(private readonly ?Database $db = null)
    {
    }

    public function confirmation(array $subscriber): bool
    {
        $this->calls[] = ['kind' => 'confirmation', 'to' => (string) ($subscriber['email'] ?? ''), 'digest' => null, 'subscriber' => $subscriber, 'answer' => null];
        return true;
    }

    public function digest(array $digest, array $subscriber): Delivery
    {
        if ($this->db !== null) {
            $state = $this->db->value('SELECT state FROM news_digest_sends WHERE digest_id = ? AND subscriber_id = ?', [(int) $digest['id'], (int) $subscriber['id']]);
            if ($state !== 'claimed') {
                throw new \LogicException('A digest went to a recipient whose send row was not claimed first');
            }
        }
        $next = array_shift($this->script) ?? Delivery::sent();
        $this->calls[] = ['kind' => 'digest', 'to' => (string) $subscriber['email'], 'digest' => $digest, 'subscriber' => $subscriber, 'answer' => $next];
        if ($this->onDigest !== null) {
            ($this->onDigest)();
        }
        return self::answer($next);
    }

    public function test(array $digest, string $to): Delivery
    {
        $next = array_shift($this->tests) ?? Delivery::sent();
        $this->calls[] = ['kind' => 'test', 'to' => $to, 'digest' => $digest, 'subscriber' => null, 'answer' => $next];
        return self::answer($next);
    }

    public function render(array $digest, ?array $subscriber): array
    {
        $selector = $subscriber !== null ? (string) $subscriber['selector'] : Tokens::TEST_SELECTOR;
        $titles = array_map(static fn (array $a): string => (string) $a['title'], $digest['articles']);
        $html = '<!doctype html><html lang="en-GB"><head><meta charset="utf-8"><title>Digest</title><style>p { margin: 0; }</style></head><body>'
            . '<h1>The week in Industry Updates</h1>';
        foreach ($titles as $title) {
            $html .= '<h2>' . htmlspecialchars($title, ENT_QUOTES) . '</h2>';
        }
        $html .= '<p><a href="https://patternsshowcontrol.test/digest/unsubscribe/?token=' . $selector . '.fake">open this link and press Unsubscribe</a></p></body></html>';
        return [
            'subject' => 'This week in Industry Updates: ' . ($titles[0] ?? ''),
            'preheader' => count($titles) . ' new articles this week on Industry Updates, the newest first.',
            'html' => $html,
            'text' => "The week in Industry Updates\n\n" . implode("\n", $titles) . "\n\nUnsubscribe: https://patternsshowcontrol.test/digest/unsubscribe/?token=" . $selector . '.fake',
            'headers' => [],
        ];
    }

    /** @return list<string> the addresses digest() was called for, in order */
    public function digestsTo(): array
    {
        $out = [];
        foreach ($this->calls as $call) {
            if ($call['kind'] === 'digest') {
                $out[] = $call['to'];
            }
        }
        return $out;
    }

    private static function answer(Delivery|\Throwable $next): Delivery
    {
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    }
}
