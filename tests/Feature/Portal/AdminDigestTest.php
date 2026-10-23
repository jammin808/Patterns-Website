<?php

declare(strict_types=1);

namespace Psc\Tests\Feature\Portal;

use Psc\Controllers\Admin\AdminDigestController;
use Psc\Http\Response;
use Psc\Mail\Delivery;
use Psc\News\Digest\Composer;
use Psc\News\Digest\DigestMail;
use Psc\News\Digest\Digests;
use Psc\News\Digest\Sender;
use Psc\News\Digest\Sends;
use Psc\News\Digest\Subscribers;
use Psc\News\Digest\Tokens;
use Psc\News\Digest\Week;
use Psc\News\Settings;
use Psc\Tests\Fakes\FakeCourier;
use Psc\Tests\TestCase;

/**
 * The weekly digest on the desk (docs/NEWS.md §14.12): who may open it, its state for each reason, the counts and the
 * day's budget with the warning at 400, the next digest and its empty line, the preview as its own document, pausing
 * and continuing, the test to the administrator's own address only, unsubscribing by address or by a pasted subject
 * line, the past digests and one digest's page of sends, and the digest's fields on the desk's settings page.
 */
final class AdminDigestTest extends TestCase
{
    private const BASE = '/portal/admin/news/desk/digest/';
    private const MAILTO = 'unsubscribe@example.test';

    private FakeCourier $courier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->boot();
    }

    protected function tearDown(): void
    {
        DigestMail::useCourier(null);
        parent::tearDown();
    }

    /** @param array<string, mixed> $mail */
    private function boot(array $mail = []): void
    {
        $this->bootApp(['mail' => $mail + ['unsubscribe_to' => self::MAILTO], 'news' => ['api_key' => 'sk-test']]);
        $this->courier = new FakeCourier($this->db);
        DigestMail::useCourier($this->courier);
    }

    private function beat(int $ago = 0): void
    {
        (new Settings($this->app))->heartbeat(['run' => null, 'status' => 'idle']);
        if ($ago > 0) {
            $this->db->exec("UPDATE news_settings SET value = ? WHERE name = 'heartbeat'", [json_encode(['t' => time() - $ago, 'run' => null, 'status' => 'idle'])]);
        }
    }

    /** @return array{type: string, message: string} */
    private static function lastFlash(): array
    {
        $all = array_values($_SESSION['_flash'] ?? []);
        self::assertNotSame([], $all, 'a flash message was queued');
        return end($all);
    }

    private function mainOf(Response $r): string
    {
        $start = strpos($r->body, '<main class="pt-main"');
        $end = strpos($r->body, '</main>');
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        return substr($r->body, (int) $start, (int) $end - (int) $start);
    }

    /** @return list<array<string, mixed>> */
    private function auditRows(string $action): array
    {
        return $this->db->all('SELECT * FROM audit_log WHERE action = ? ORDER BY id', [$action]);
    }

    /** A confirmed subscriber from the page; its row. */
    private function subscriber(string $email): array
    {
        $s = new Subscribers($this->app);
        $row = $s->request($email, 'c', 'p', time() - 3600)['subscriber'];
        self::assertSame('confirmed', $s->confirm((string) $row['selector'], 0, time() - 1800));
        return (array) $s->find((int) $row['id']);
    }

    /** A digest row, sent, with its window and one article. */
    private function digest(string $week, int $sendAt, string $state = 'sent', ?string $note = null, array $articles = [], int $recipients = 0): int
    {
        return $this->db->insert('news_digests', ['week' => $week, 'send_at' => $sendAt, 'window_start' => $sendAt - 604800, 'window_end' => $sendAt, 'articles' => json_encode($articles),
            'left_out' => 0, 'state' => $state, 'note' => $note, 'recipients' => $recipients, 'composed_at' => $sendAt + 60, 'finished_at' => $state === 'sending' ? null : $sendAt + 120]);
    }

    /** "2026-10-23 09:00" London as a timestamp. */
    private static function ts(string $london): int
    {
        return (new \DateTimeImmutable($london, new \DateTimeZone('Europe/London')))->getTimestamp();
    }

    /** A digest page's sends: its stats as "value label" and its table's State column, in order. */
    private function sendsOf(int $digest): array
    {
        $page = $this->request('GET', self::BASE . $digest . '/')->body;
        preg_match_all('#<span class="pt-stat-value">([0-9,]+)</span> <span class="pt-stat-label">([^<]+)</span>#', $page, $stats, PREG_SET_ORDER);
        preg_match_all('#<td data-label="State">([^<]*)#', $page, $states);
        preg_match('#<p class="pt-lede"><span class="pt-badge[^"]*">([^<]*)</span>#', $page, $badge);
        return ['badge' => $badge[1] ?? null, 'stats' => array_map(static fn (array $m): string => $m[1] . ' ' . $m[2], $stats), 'states' => $states[1]];
    }

    /** The past digests' Sent and Not sent for a digest, from the main page. */
    private function pastOf(int $digest): array
    {
        $index = $this->request('GET', self::BASE)->body;
        $start = strpos($index, '<a href="' . self::BASE . $digest . '/">');
        self::assertNotFalse($start, 'the digest is among the past digests');
        $row = substr($index, (int) $start, (int) strpos($index, '</tr>', (int) $start) - (int) $start);
        preg_match('#data-label="Sent">([0-9,]+)<#', $row, $sent);
        preg_match('#data-label="Not sent">([0-9,]+)<#', $row, $unsent);
        return ['sent' => $sent[1] ?? null, 'not_sent' => $unsent[1] ?? null];
    }

    public function testOnlyAdministratorsReachTheDigest(): void
    {
        foreach ([self::BASE, self::BASE . 'preview/', self::BASE . '1/'] as $path) {
            $r = $this->request('GET', $path);
            self::assertSame(302, $r->status, $path);
            self::assertSame('/portal/login/?next=' . rawurlencode($path), $r->getHeader('Location'));
        }
        $this->signInClient();
        foreach ([self::BASE, self::BASE . 'preview/', self::BASE . '1/'] as $path) {
            self::assertSame(403, $this->request('GET', $path)->status, $path);
        }
        self::assertSame(403, $this->post(self::BASE, ['action' => 'pause'])->status);
        self::assertTrue((new Settings($this->app))->get('digest_enabled'));

        // A fresh browser for the administrator.
        $_SESSION = [];
        $this->jar = [];
        $this->signInAdmin();
        self::assertSame(419, $this->post(self::BASE, ['action' => 'pause'], false)->status);
        self::assertTrue((new Settings($this->app))->get('digest_enabled'), 'no change without the form\'s token');
        $this->post(self::BASE, ['action' => 'nonsense']);
        self::assertSame(['danger', 'That action is not available. Use one of the buttons on this page.'], array_values(self::lastFlash()));
        self::assertSame(404, $this->request('GET', self::BASE . '999/')->status);
    }

    public function testTheStateSaysTheFirstReasonThatApplies(): void
    {
        $cases = [
            ['mail_driver', ['driver' => 'mail'], 60, 'Stopped: PHP mail()'],
            ['no_mailto', ['unsubscribe_to' => ''], 60, 'Stopped: no unsubscribe address'],
            ['no_mailto', ['unsubscribe_to' => 'Unsubscribe <unsubscribe@example.test>'], 60, 'Stopped: no unsubscribe address'],
            ['no_tick', [], -1, 'Stopped: no tick yet'],
            ['stopped', [], 36 * 60, 'Stopped: cron not ticking'],
            ['on', [], 60, 'On'],
        ];
        foreach ($cases as [$key, $mail, $ago, $label]) {
            $this->boot($mail);
            if ($ago >= 0) {
                $this->beat($ago);
            }
            $this->signInAdmin();
            $page = $this->request('GET', self::BASE)->body;
            self::assertStringContainsString('>' . $label . '</span>', $page, $key);
            self::assertSame($key, \Psc\Controllers\Admin\AdminDigestController::state($this->app)['key'], $key);
        }
        self::assertStringContainsString('The next digest goes out ', $page);
        self::assertStringContainsString('This server writes emails to files (mail.driver is log), so nothing reaches anyone\'s inbox.', $page);
        self::assertStringContainsString('Pause the digest', $page);

        (new Settings($this->app))->save(['digest_enabled' => false], null);
        $page = $this->request('GET', self::BASE)->body;
        self::assertStringContainsString('>Paused</span> No digest is composed or sent until you continue it.', $page);
        self::assertStringContainsString('Continue the digest', $page);

        $this->boot(['driver' => 'smtp']);
        $this->beat();
        $this->signInAdmin();
        self::assertStringNotContainsString('mail.driver is log', $this->request('GET', self::BASE)->body);
    }

    public function testTheCountsTheBudgetAndTheWarningAt400(): void
    {
        $this->signInAdmin();
        $a = $this->subscriber('a@example.test');
        $this->subscriber('b@example.test');
        (new Subscribers($this->app))->request('waiting@example.test', 'c', 'p', time());
        (new Subscribers($this->app))->unsubscribe((string) $a['selector'], 'one_click', time());
        $digest = $this->digest('2026-w40', time() - 7200);
        $this->db->insert('news_digest_sends', ['digest_id' => $digest, 'subscriber_id' => $a['id'], 'state' => 'sent', 'attempts' => 1, 'claimed_at' => time() - 3600, 'sent_at' => time() - 3600]);
        $page = $this->mainOf($this->request('GET', self::BASE));
        foreach (['1</span> <span class="pt-stat-label">Getting the digest', '1</span> <span class="pt-stat-label">Waiting to confirm', '2</span> <span class="pt-stat-label">Joined in the last 7 days',
            '1</span> <span class="pt-stat-label">Left in the last 7 days', '1</span> <span class="pt-stat-label">Unsubscribed, kept so they are never sent it again'] as $stat) {
            self::assertStringContainsString($stat, str_replace('<span class="pt-stat-value">', '', $page), $stat);
        }
        self::assertStringContainsString('Sent in the last 24 hours: 1 of the 500 the settings allow.', $page);
        self::assertStringNotContainsString('nearing what one mailbox can send', $page);

        $tokens = Tokens::fromApp($this->app);
        for ($i = 0; $i < 399; $i++) {
            $this->db->insert('news_digest_subscribers', ['email' => 'r' . $i . '@example.test', 'email_hash' => $tokens->emailHash('r' . $i . '@example.test'), 'state' => 'confirmed', 'source' => 'page', 'selector' => Tokens::selector(),
                'consent_version' => 'c', 'privacy_version' => 'p', 'requested_at' => time() - 30 * 86400, 'confirmed_at' => time() - 30 * 86400, 'created_at' => time(), 'updated_at' => time()]);
        }
        $page = $this->request('GET', self::BASE)->body;
        self::assertStringContainsString('The list is nearing what one mailbox can send in a day beside the site\'s other email.', $page, 'at 400 confirmed');
        self::assertStringContainsString('needs a change to the site first, as docs/DEPLOY.md says, so ask for it now.', $page, 'nothing the owner cannot do by settings alone');
    }

    public function testTheNextDigestItsEmptyLineAndThePlainText(): void
    {
        $this->signInAdmin();
        $page = $this->request('GET', self::BASE)->body;
        self::assertStringContainsString('No article has gone live since the last digest yet, so nothing would be sent.', $page);
        self::assertMatchesRegularExpression('#Week of \d{1,2} [A-Z][a-z]+ \d{4}, going out [A-Z][a-z]+day \d{1,2} [A-Z][a-z]+ \d{4} at 09:00\.#', $page);

        // Live inside the next digest's window whatever the clock says (on a Friday after 09:00 the next digest is
        // the one due now, whose window ended at 09:00).
        $next = Week::next(new \DateTimeImmutable('now'), new Settings($this->app), []);
        $window = (new Composer($this->app))->window($next['send_at']);
        $article = $this->makeArticle(['title' => 'A headline for the next digest to carry', 'published_at' => $window['start'] + 60]);
        $page = $this->request('GET', self::BASE)->body;
        self::assertStringNotContainsString('No article has gone live since the last digest yet', $page);
        self::assertStringContainsString('<a href="/portal/admin/news/' . $article . '/">A headline for the next digest to carry</a>', $page);
        self::assertStringContainsString('<summary>The plain-text part</summary>', $page);
        self::assertStringContainsString('A headline for the next digest to carry', (string) preg_replace('#.*<pre class="pt-mono pt-desk-rules">#s', '', $page));
        self::assertStringContainsString('href="' . self::BASE . 'preview/" target="_blank"', $page);
        self::assertStringContainsString('Sends the next digest as it stands to admin@example.test, your own address, marked as a test.', $page);
        self::assertStringContainsString('No digest has gone out yet.', $page);
    }

    public function testThePreviewIsTheEmailAsItsOwnDocument(): void
    {
        $this->signInAdmin();
        $this->makeArticle(['title' => 'The article the preview shows first']);
        $r = $this->request('GET', self::BASE . 'preview/');
        self::assertSame(200, $r->status);
        self::assertSame("default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'", $r->getHeader('Content-Security-Policy'));
        self::assertStringContainsString('no-store', (string) $r->getHeader('Cache-Control'));
        self::assertStringContainsString('private', (string) $r->getHeader('Cache-Control'));
        self::assertStringContainsString('noindex', (string) $r->getHeader('X-Robots-Tag'));
        self::assertStringContainsString('The article the preview shows first', $r->body);
        self::assertStringContainsString(Tokens::TEST_SELECTOR, $r->body, 'the preview\'s links carry the test selector');
        self::assertStringNotContainsString('pt-shell', $r->body, 'outside the admin shell');
        self::assertStringNotContainsString('pt-main', $r->body);
    }

    public function testPauseAndContinueAreAudited(): void
    {
        $this->signInAdmin();
        $this->post(self::BASE, ['action' => 'resume']);
        self::assertSame(['info', 'The digest is already on.'], array_values(self::lastFlash()));
        $this->post(self::BASE, ['action' => 'pause']);
        self::assertSame(['success', 'The digest is paused. Nothing is composed or sent until you continue it.'], array_values(self::lastFlash()));
        self::assertFalse((new Settings($this->app))->get('digest_enabled'));
        self::assertTrue((new Settings($this->app))->get('enabled'), 'the desk\'s own pause is apart');
        $this->post(self::BASE, ['action' => 'pause']);
        self::assertSame(['info', 'The digest is already paused.'], array_values(self::lastFlash()));
        $this->post(self::BASE, ['action' => 'resume']);
        self::assertSame('success', self::lastFlash()['type']);
        self::assertMatchesRegularExpression('/^The digest is on again\. The next one goes out [A-Z][a-z]+day \d{1,2} [A-Z][a-z]+ \d{4} at 09:00\.$/', self::lastFlash()['message']);
        self::assertTrue((new Settings($this->app))->get('digest_enabled'));
        self::assertCount(1, $this->auditRows('digest.paused'));
        self::assertCount(1, $this->auditRows('digest.resumed'));
    }

    public function testATestGoesOnlyToTheAdministratorsOwnAddress(): void
    {
        $this->signInAdmin();
        $this->makeArticle(['title' => 'An article for the test digest']);
        $r = $this->post(self::BASE, ['action' => 'test', 'to' => 'someone@example.test', 'email' => 'someone@example.test']);
        self::assertSame(303, $r->status);
        self::assertSame(['success', 'A test digest is on its way to admin@example.test.'], array_values(self::lastFlash()));
        self::assertSame([['test', 'admin@example.test']], array_map(static fn (array $c): array => [$c['kind'], $c['to']], $this->courier->calls));
        self::assertSame('An article for the test digest', $this->courier->calls[0]['digest']['articles'][0]['title']);
        $audit = $this->auditRows('digest.test');
        self::assertCount(1, $audit);
        self::assertStringNotContainsString('@', (string) $audit[0]['meta']);
        self::assertStringContainsString('"week":"', (string) $audit[0]['meta']);

        $refused = new Delivery(false, 'auth', 535, '5.7.8');
        $this->courier->tests = [$refused];
        $this->post(self::BASE, ['action' => 'test']);
        self::assertSame(['danger', 'The test was not sent: the mail server answered ' . $refused->summary() . '. The site log has more.'], array_values(self::lastFlash()));

        for ($i = 0; $i < 3; $i++) {
            $this->post(self::BASE, ['action' => 'test']);
        }
        self::assertCount(5, $this->courier->calls);
        $this->post(self::BASE, ['action' => 'test']);
        self::assertSame(['danger', 'You have sent 5 tests in the last hour. Please wait a while, then try again.'], array_values(self::lastFlash()));
        self::assertCount(5, $this->courier->calls, 'the sixth in the hour is not sent');
    }

    /** A failed test says who stopped it: the mail server only when it answered, else the site. */
    public function testAFailedTestSaysWhoStoppedIt(): void
    {
        $this->signInAdmin();
        $this->makeArticle(['title' => 'An article for the test digest']);
        $this->courier->tests = [new Delivery(false, 'refused'), new Delivery(false, 'connect'), new Delivery(false, 'body', 0, '', true), new \RuntimeException('the template failed'), new Delivery(false, 'rcpt', 550, '5.7.1')];
        foreach ([
            'the site could not build the email',
            'the site could not reach the mail server',
            'the mail server did not reply (body timeout)',
            'the site could not build the email',
            'the mail server answered rcpt 550 5.7.1',
        ] as $why) {
            $this->post(self::BASE, ['action' => 'test']);
            self::assertSame(['danger', 'The test was not sent: ' . $why . '. The site log has more.'], array_values(self::lastFlash()));
        }
        self::assertCount(5, $this->courier->calls);
        self::assertSame(['{"week":"' . $this->courier->calls[0]['digest']['week'] . '","sent":false}'], array_unique(array_column($this->auditRows('digest.test'), 'meta')));
    }

    /**
     * A STARTTLS the server answered with 220 that the site could not finish (a handshake that failed, most often over
     * the certificate's name) is the site's: the server did reply. Only a reply that never came says it did not.
     */
    public function testATlsHandshakeThatFailsIsTheSitesNotTheServersSilence(): void
    {
        $this->signInAdmin();
        $this->makeArticle(['title' => 'An article for the test digest']);
        $this->courier->tests = [new Delivery(false, 'tls'), new Delivery(false, 'tls', 0, '', true), new Delivery(false, 'tls', 454, '4.7.0')];
        foreach ([
            'the site could not set up a secure connection to the mail server',
            'the mail server did not reply (tls timeout)',
            'the mail server answered tls 454 4.7.0',
        ] as $why) {
            $this->post(self::BASE, ['action' => 'test']);
            self::assertSame(['danger', 'The test was not sent: ' . $why . '. The site log has more.'], array_values(self::lastFlash()));
        }
        self::assertCount(3, $this->courier->calls);
    }

    public function testATestIsRefusedWhileSendingIsBlocked(): void
    {
        $this->boot(['driver' => 'mail']);
        $this->signInAdmin();
        $this->post(self::BASE, ['action' => 'test']);
        self::assertSame('danger', self::lastFlash()['type']);
        self::assertStringStartsWith('The site\'s mail goes through PHP mail(), which Hostinger caps at 100 a day.', self::lastFlash()['message']);
        self::assertSame([], $this->courier->calls);
    }

    public function testUnsubscribingSomeoneByAddressOrByAPastedSubjectLine(): void
    {
        $this->signInAdmin();
        $one = $this->subscriber('one@example.test');
        $two = $this->subscriber('two@example.test');
        $s = new Subscribers($this->app);

        $this->post(self::BASE, ['action' => 'unsubscribe', 'unsubscribe_who' => ' ONE@example.test ', 'unsubscribe_method' => 'admin']);
        self::assertSame(['success', 'That address is unsubscribed and gets no more digests.'], array_values(self::lastFlash()));
        self::assertSame(['unsubscribed', 'admin'], [$s->find($one['id'])['state'], $s->find($one['id'])['unsubscribe_method']]);

        $token = Tokens::fromApp($this->app)->make(Tokens::UNSUBSCRIBE, (string) $two['selector']);
        $this->post(self::BASE, ['action' => 'unsubscribe', 'unsubscribe_who' => 'Fwd: Unsubscribe ' . $token, 'unsubscribe_method' => 'mailto']);
        self::assertSame('success', self::lastFlash()['type']);
        self::assertSame(['unsubscribed', 'mailto'], [$s->find($two['id'])['state'], $s->find($two['id'])['unsubscribe_method']]);

        $this->post(self::BASE, ['action' => 'unsubscribe', 'unsubscribe_who' => 'one@example.test', 'unsubscribe_method' => 'mailto']);
        self::assertSame(['info', 'That address was already unsubscribed. Nothing changed.'], array_values(self::lastFlash()));
        $this->post(self::BASE, ['action' => 'unsubscribe', 'unsubscribe_who' => 'nobody@example.test']);
        self::assertSame(['warning', 'No subscription matches that address or token. Nothing changed.'], array_values(self::lastFlash()));
        // A token that is not one of ours (a forged mac) matches nothing.
        $forged = substr($token, 0, 25) . str_repeat('A', 43);
        $three = $this->subscriber('three@example.test');
        $this->post(self::BASE, ['action' => 'unsubscribe', 'unsubscribe_who' => (string) $three['selector'] . '.' . str_repeat('A', 43) . ' ' . $forged]);
        self::assertSame('warning', self::lastFlash()['type']);
        self::assertSame('confirmed', $s->find($three['id'])['state']);

        foreach (['', 'not an address'] as $who) {
            $r = $this->post(self::BASE, ['action' => 'unsubscribe', 'unsubscribe_who' => $who]);
            self::assertSame(self::BASE . '#unsubscribe-summary', $r->getHeader('Location'), 'to the summary itself, which takes focus');
            $page = $this->request('GET', self::BASE)->body;
            self::assertStringContainsString('<a href="#f-unsubscribe-who">Enter an email address, or paste the token from the unsubscribe email.</a>', $page, $who);
            self::assertMatchesRegularExpression('/<div[^>]*id="unsubscribe-summary"[^>]*data-error-summary|<div[^>]*data-error-summary[^>]*id="unsubscribe-summary"/', $page, 'the fragment is the summary\'s own id');
        }
        $page = $this->request('GET', self::BASE)->body;
        self::assertStringContainsString('By email to ' . self::MAILTO, $page);
        self::assertStringContainsString('Requests to ' . self::MAILTO . ' arrive wherever that address delivers (docs/DEPLOY.md, section 8): act on each within 2 days.', $page);
        self::assertStringNotContainsString('the admin@ mailbox', $page, 'the desk names no mailbox the deploy guide never makes');

        $audit = $this->auditRows('digest.unsubscribed');
        self::assertCount(2, $audit);
        self::assertSame(['{"method":"admin"}', '{"method":"mailto"}'], array_column($audit, 'meta'));
    }

    public function testThePastDigestsAndADigestsPageOfSends(): void
    {
        $this->signInAdmin();
        $article = $this->makeArticle(['title' => 'The article this digest carried']);
        $down = $this->makeArticle(['title' => 'An article taken down since']);
        $this->db->exec("UPDATE news_articles SET status = 'draft' WHERE id = ?", [$down]);
        $sendAt = (new \DateTimeImmutable('2026-10-23 09:00', new \DateTimeZone('Europe/London')))->getTimestamp();
        $digest = $this->digest('2026-w43', $sendAt, 'sending', null, [$article, $down]);
        $this->digest('2026-w42', $sendAt - 604800, 'stopped', 'Stopped: every article in it was taken down.');
        $this->digest('2026-w41', $sendAt - 2 * 604800, 'skipped', 'Skipped: the digest was paused at its time.');
        $tokens = Tokens::fromApp($this->app);
        $states = ['sent', 'sent', 'failed', 'bounced', 'retry', 'claimed'];
        for ($i = 0; $i < 51; $i++) {
            $email = sprintf('reader%02d@example.test', $i);
            $sub = $this->db->insert('news_digest_subscribers', ['email' => $email, 'email_hash' => $tokens->emailHash($email), 'state' => 'confirmed', 'source' => 'page', 'selector' => Tokens::selector(),
                'consent_version' => 'c', 'privacy_version' => 'p', 'requested_at' => $sendAt - 86400, 'confirmed_at' => $sendAt - 86400, 'created_at' => $sendAt, 'updated_at' => $sendAt]);
            $state = $states[$i % count($states)];
            $this->db->insert('news_digest_sends', ['digest_id' => $digest, 'subscriber_id' => $sub, 'state' => $state, 'attempts' => 1, 'claimed_at' => $sendAt + 60 + $i,
                'sent_at' => $state === 'sent' ? $sendAt + 61 + $i : null, 'reply' => $state === 'bounced' ? 'rcpt 550 5.1.1' : null]);
        }
        // The first reader unsubscribed since: the row stays, without an address.
        (new Subscribers($this->app))->unsubscribeEmail('reader00@example.test', 'one_click', time());

        $index = $this->request('GET', self::BASE)->body;
        self::assertStringContainsString('<caption>The last 12 digests</caption>', $index);
        self::assertStringContainsString('<a href="' . self::BASE . $digest . '/">Week of 19 October 2026</a>', $index);
        self::assertStringContainsString('<span class="pt-badge pt-badge--in_progress">Sending</span>', $index);
        self::assertStringContainsString('<span class="pt-badge pt-badge--declined">Stopped</span>', $index);
        self::assertStringContainsString('<span class="pt-badge pt-badge--pending">Skipped</span>', $index);

        $r = $this->request('GET', self::BASE . $digest . '/');
        self::assertSame(200, $r->status);
        $page = $r->body;
        self::assertStringContainsString('<h1 class="pt-title">Digest for the week of 19 October 2026</h1>', $page);
        self::assertStringContainsString('<title>Digest for the week of 19 October 2026 | Client portal', $page);
        self::assertStringContainsString('2026-w43</span> <span class="pt-muted">(also its campaign tag)', $page);
        self::assertStringContainsString('The article this digest carried', $page);
        self::assertStringContainsString('Taken down since: left out of the messages sent after that', $page);
        self::assertSame(1, substr_count($page, 'Taken down since'), 'only the one taken down');
        $stats = str_replace('<span class="pt-stat-value">', '', $page);
        foreach (['18</span> <span class="pt-stat-label">Sent', '8</span> <span class="pt-stat-label">Waiting to retry', '8</span> <span class="pt-stat-label">Bounced', '9</span> <span class="pt-stat-label">Failed', '8</span> <span class="pt-stat-label">Unknown'] as $stat) {
            self::assertStringContainsString($stat, $stats, $stat);
        }
        self::assertStringContainsString('<caption>Who it was sent to, 50 a page</caption>', $page);
        self::assertSame(50, substr_count($page, '<th scope="row">'));
        self::assertStringContainsString('Unsubscribed since', $page);
        self::assertStringNotContainsString('reader00@example.test', $page);
        self::assertStringContainsString('<bdi>reader01@example.test</bdi>', $page);
        self::assertStringContainsString('rcpt 550 5.1.1', $page);
        self::assertStringContainsString('<a rel="next" href="' . self::BASE . $digest . '/?page=2">Next</a>', $page);
        self::assertStringContainsString('aria-current="true">Weekly digest</a>', $page, 'its tab is marked');

        $second = $this->request('GET', self::BASE . $digest . '/?page=2')->body;
        self::assertSame(1, substr_count($second, '<th scope="row">'));
        self::assertStringContainsString('reader50@example.test', $second);
    }

    /**
     * A stopped digest is never tried again: the sends it left to retry, and the recipients it never reached, read
     * "Not sent" on its page and in the past digests' count, never "Waiting to retry".
     */
    public function testAStoppedDigestsLeftoversReadNotSent(): void
    {
        $this->signInAdmin();
        $sendAt = (new \DateTimeImmutable('2026-10-23 09:00', new \DateTimeZone('Europe/London')))->getTimestamp();
        $digest = $this->digest('2026-w43', $sendAt, 'stopped', 'Stopped: it could not reach everyone within 48 hours of its time, so the rest were not sent.', [$this->makeArticle()], 4);
        $tokens = Tokens::fromApp($this->app);
        $ids = [];
        for ($i = 0; $i < 4; $i++) {
            $email = 'left' . $i . '@example.test';
            $ids[] = $this->db->insert('news_digest_subscribers', ['email' => $email, 'email_hash' => $tokens->emailHash($email), 'state' => 'confirmed', 'source' => 'page', 'selector' => Tokens::selector(),
                'consent_version' => 'c', 'privacy_version' => 'p', 'requested_at' => $sendAt - 86400, 'confirmed_at' => $sendAt - 86400, 'created_at' => $sendAt, 'updated_at' => $sendAt]);
        }
        // The mailbox refused the first at connect, over and over, so its claim was given back each time.
        $this->db->insert('news_digest_sends', ['digest_id' => $digest, 'subscriber_id' => $ids[0], 'state' => 'retry', 'attempts' => 0, 'claimed_at' => $sendAt + 60, 'reply' => 'connect']);

        $page = $this->request('GET', self::BASE . $digest . '/')->body;
        $stats = str_replace('<span class="pt-stat-value">', '', $page);
        self::assertStringContainsString('0</span> <span class="pt-stat-label">Sent', $stats);
        self::assertStringContainsString('4</span> <span class="pt-stat-label">Not sent: the digest stopped', $stats);
        self::assertStringNotContainsString('Waiting to retry', $page, 'a stopped digest waits for nothing');
        self::assertStringNotContainsString('Still to send', $page);
        self::assertStringContainsString('<td data-label="State">Not sent: the digest stopped</td>', $page);

        $index = $this->request('GET', self::BASE)->body;
        self::assertStringContainsString('<td class="pt-num" data-label="Sent">0</td>', $index);
        self::assertStringContainsString('<td class="pt-num" data-label="Not sent">4</td>', $index, 'the retry and the three never tried');

        // While a digest is still sending, its retries wait and nobody is counted as not sent.
        $this->db->exec("UPDATE news_digests SET state = 'sending', finished_at = NULL WHERE id = ?", [$digest]);
        $page = $this->request('GET', self::BASE . $digest . '/')->body;
        self::assertStringContainsString('<td data-label="State">Waiting to retry</td>', $page);
        self::assertStringContainsString('4</span> <span class="pt-stat-label">Still to send', str_replace('<span class="pt-stat-value">', '', $page));
        self::assertStringContainsString('<td class="pt-num" data-label="Not sent">0</td>', $this->request('GET', self::BASE)->body);
    }

    /**
     * A stopped digest's "Not sent" is read from its own record (its recipients when composed, less every send with an
     * outcome), so it holds when those it never reached leave the list later, and every row it counts still reads so.
     */
    public function testAStoppedDigestsNotSentHoldsWhenThoseItNeverReachedLeaveLater(): void
    {
        $this->signInAdmin();
        $s = new Subscribers($this->app);
        foreach (['reader1', 'reader2', 'reader3', 'reader4'] as $name) {
            $row = $s->request($name . '@example.test', 'c', 'p', self::ts('2026-10-20 12:00'))['subscriber'];
            self::assertSame('confirmed', $s->confirm((string) $row['selector'], 0, self::ts('2026-10-20 12:00')));
        }
        $this->makeArticle(['published_at' => self::ts('2026-10-22 12:00')]);
        (new Settings($this->app))->save(['digest_per_tick' => '1'], null);
        $this->courier->script = [new Delivery(false, 'rcpt', 451, '4.7.1')];
        $sender = new Sender($this->app, $this->courier);
        self::assertSame(['sending', 1], array_values(array_intersect_key($sender->tick(new \DateTimeImmutable('@' . self::ts('2026-10-23 09:05'))), ['status' => 0, 'retry' => 0])));
        $id = (int) (new Digests($this->app))->forWeek('2026-w43')['id'];
        self::assertSame(['status' => 'stopped', 'left' => 4], array_intersect_key($sender->tick(new \DateTimeImmutable('@' . self::ts('2026-10-25 09:05'))), ['status' => 0, 'left' => 0]), 'past 48 hours');

        $before = ['badge' => 'Stopped', 'stats' => ['0 Sent', '4 ' . AdminDigestController::NOT_SENT, '0 Bounced', '0 Failed', '0 Unknown (the tick stopped while sending)'], 'states' => [AdminDigestController::NOT_SENT]];
        self::assertSame($before, $this->sendsOf($id));
        self::assertSame(['sent' => '0', 'not_sent' => '4'], $this->pastOf($id));
        // The retry's subscriber and one never tried leave after it stopped; another confirms again since.
        $s->unsubscribeEmail('reader1@example.test', 'one_click', self::ts('2026-10-26 09:00'));
        $s->unsubscribeEmail('reader2@example.test', 'one_click', self::ts('2026-10-26 09:00'));
        $s->unsubscribeEmail('reader3@example.test', 'one_click', self::ts('2026-10-26 09:00'));
        $again = $s->request('reader3@example.test', 'c', 'p', self::ts('2026-10-26 10:00'))['subscriber'];
        self::assertSame('confirmed', $s->confirm((string) $again['selector'], 1, self::ts('2026-10-26 10:00')));
        self::assertSame(1, (new Sends($this->app))->countDue((array) (new Digests($this->app))->find($id)), 'of the four, only reader4 is still on the list as it was');
        self::assertSame($before, $this->sendsOf($id), 'the page reads the same');
        self::assertSame(['sent' => '0', 'not_sent' => '4'], $this->pastOf($id));
    }

    /**
     * A retry waits only while the digest is still due to its subscriber: one who has unsubscribed since is never tried
     * again, so its row reads "Not sent: unsubscribed since" and is not counted as waiting, while the digest sends and
     * once it has finished as sent.
     */
    public function testARetryOfSomeoneWhoLeftSinceIsNotWaiting(): void
    {
        $this->signInAdmin();
        $s = new Subscribers($this->app);
        foreach (['reader1', 'reader2', 'reader3'] as $name) {
            $row = $s->request($name . '@example.test', 'c', 'p', self::ts('2026-10-20 12:00'))['subscriber'];
            self::assertSame('confirmed', $s->confirm((string) $row['selector'], 0, self::ts('2026-10-20 12:00')));
        }
        $this->makeArticle(['published_at' => self::ts('2026-10-22 12:00')]);
        $this->courier->script = [new Delivery(false, 'rcpt', 451, '4.7.1')];
        $sender = new Sender($this->app, $this->courier);
        self::assertSame(['status' => 'sending', 'retry' => 1, 'left' => 3], array_intersect_key($sender->tick(new \DateTimeImmutable('@' . self::ts('2026-10-23 09:05'))), ['status' => 0, 'retry' => 0, 'left' => 0]));
        $id = (int) (new Digests($this->app))->forWeek('2026-w43')['id'];
        $stats = static fn (int $left, int $waiting, int $sent): array => [$sent . ' Sent', $left . ' Still to send', $waiting . ' Waiting to retry', '0 Bounced', '0 Failed', '0 Unknown (the tick stopped while sending)'];
        self::assertSame(['badge' => 'Sending', 'stats' => $stats(3, 1, 0), 'states' => ['Waiting to retry']], $this->sendsOf($id));

        $s->unsubscribeEmail('reader1@example.test', 'one_click', self::ts('2026-10-23 09:10'));
        self::assertSame(['badge' => 'Sending', 'stats' => $stats(2, 0, 0), 'states' => [AdminDigestController::UNSUBSCRIBED]], $this->sendsOf($id));

        self::assertSame(['status' => 'sent', 'sent' => 2, 'left' => 0], array_intersect_key($sender->tick(new \DateTimeImmutable('@' . self::ts('2026-10-23 09:20'))), ['status' => 0, 'sent' => 0, 'left' => 0]));
        self::assertSame('retry', $this->db->value('SELECT state FROM news_digest_sends WHERE digest_id = ? ORDER BY id LIMIT 1', [$id]), 'the row is left as it was');
        self::assertSame(['badge' => 'Sent', 'stats' => $stats(0, 0, 2), 'states' => [AdminDigestController::UNSUBSCRIBED, 'Sent', 'Sent']], $this->sendsOf($id));
        self::assertSame(['sent' => '2', 'not_sent' => '0'], $this->pastOf($id));
    }

    public function testTheDigestsFieldsOnTheSettingsPage(): void
    {
        $this->signInAdmin();
        $page = $this->request('GET', '/portal/admin/news/desk/settings/')->body;
        self::assertStringContainsString('<h2 class="pt-h2" id="desk-digest">Weekly digest</h2>', $page);
        self::assertMatchesRegularExpression('#<option value="5" selected>Friday</option>#', $page);
        self::assertMatchesRegularExpression('#<input type="time" id="f-digest-time" name="digest_time"[^>]*value="09:00">#', $page);
        self::assertStringContainsString('The no-reply mailbox can send 1,000 emails in any 24 hours, shared with confirmations, password resets and ticket emails. 500 leaves them half.', $page);

        $this->post('/portal/admin/news/desk/settings/', $this->settingsForm($page, ['digest_weekday' => '1', 'digest_time' => '07:30', 'digest_per_tick' => '10']));
        self::assertSame(['success', 'The settings are saved. The next digest uses them.'], array_values(self::lastFlash()));
        $s = new Settings($this->app);
        self::assertSame([1, '07:30', 10], [$s->get('digest_weekday'), $s->get('digest_time'), $s->get('digest_per_tick')]);

        $page = $this->request('GET', '/portal/admin/news/desk/settings/')->body;
        $this->post('/portal/admin/news/desk/settings/', $this->settingsForm($page, ['digest_per_day' => '20', 'max_per_session' => '2']));
        self::assertSame(['success', 'The settings are saved. The next session uses them.'], array_values(self::lastFlash()), 'any other change keeps the desk\'s words');

        $page = $this->request('GET', '/portal/admin/news/desk/settings/')->body;
        $this->post('/portal/admin/news/desk/settings/', $this->settingsForm($page, ['digest_weekday' => '9', 'digest_time' => '25:00', 'digest_per_tick' => '51', 'digest_per_hour' => '0', 'digest_per_day' => '1000']));
        self::assertSame('danger', self::lastFlash()['type']);
        $refused = $this->request('GET', '/portal/admin/news/desk/settings/')->body;
        foreach ([
            'f-digest-weekday">Choose the day the digest goes out.',
            'f-digest-time">Enter the digest&#039;s time as HH:MM, from 00:00 to 23:59.',
            'f-digest-per-tick">Enter a number of digests per tick from 1 to 50.',
            'f-digest-per-hour">Enter a number of digests per hour from 1 to 200.',
            'f-digest-per-day">Enter a number of digests per 24 hours from 1 to 900.',
        ] as $error) {
            self::assertStringContainsString('<a href="#' . $error . '</a>', $refused, $error);
        }
        self::assertMatchesRegularExpression('#id="f-digest-per-tick" name="digest_per_tick"[^>]*value="51"#', $refused, 'kept as typed');
        self::assertSame([1, '07:30', 10, 100, 20], [(new Settings($this->app))->get('digest_weekday'), (new Settings($this->app))->get('digest_time'), (new Settings($this->app))->get('digest_per_tick'), (new Settings($this->app))->get('digest_per_hour'), (new Settings($this->app))->get('digest_per_day')]);

        // A form drawn before someone else changed the digest's time is refused for that setting.
        $stale = $this->request('GET', '/portal/admin/news/desk/settings/')->body;
        (new Settings($this->app))->save(['digest_time' => '08:00'], null);
        $this->post('/portal/admin/news/desk/settings/', $this->settingsForm($stale, ['digest_per_tick' => '12']));
        self::assertSame('danger', self::lastFlash()['type']);
        self::assertSame(['digest_time'], array_keys($_SESSION['_errors'] ?? []));
        self::assertSame(['08:00', 10], [(new Settings($this->app))->get('digest_time'), (new Settings($this->app))->get('digest_per_tick')]);
    }

    /**
     * The settings form as the page drew it (every setting's stored value and its fingerprint), with changes.
     *
     * @param array<string, string> $over
     * @return array<string, mixed>
     */
    private function settingsForm(string $page, array $over): array
    {
        preg_match_all('#name="loaded\[([a-z_]+)\]" value="([0-9a-f]{16})"#', $page, $m);
        $s = (new Settings($this->app))->all();
        $slots = [];
        foreach ($s['slots'] as $i => $slot) {
            $slots[$i] = ['time' => $slot['time'], 'days' => array_map('strval', $slot['days'])];
        }
        return $over + [
            'max_per_session' => (string) $s['max_per_session'], 'min_importance' => (string) $s['min_importance'], 'auto_publish' => $s['auto_publish'] ? '1' : '',
            'research_model' => $s['research_model'], 'compose_model' => $s['compose_model'], 'max_searches' => (string) $s['max_searches'], 'max_fetches' => (string) $s['max_fetches'],
            'monthly_budget_usd' => (string) $s['monthly_budget_usd'], 'watch_urls' => implode("\n", $s['watch_urls']), 'banned_domains' => implode("\n", $s['banned_domains']),
            'banned_topics' => implode("\n", $s['banned_topics']), 'avoid_phrases' => implode("\n", $s['avoid_phrases']), 'ai_note' => $s['ai_note'], 'comments' => $s['comments'], 'slots' => $slots,
            'digest_weekday' => (string) $s['digest_weekday'], 'digest_time' => $s['digest_time'], 'digest_per_tick' => (string) $s['digest_per_tick'],
            'digest_per_hour' => (string) $s['digest_per_hour'], 'digest_per_day' => (string) $s['digest_per_day'],
            'loaded' => array_combine($m[1], $m[2]),
        ];
    }
}
