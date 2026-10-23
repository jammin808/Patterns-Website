<?php

declare(strict_types=1);

namespace Psc\Tests\Feature\News\Digest;

use Psc\Content\Content;
use Psc\Controllers\DigestController;
use Psc\Http\Response;
use Psc\News\Digest\DigestMail;
use Psc\News\Digest\Links;
use Psc\News\Digest\Subscribers;
use Psc\News\Digest\Tokens;
use Psc\Tests\TestCase;

/**
 * The weekly digest's public pages (docs/NEWS.md §14.5, §14.6, §14.14): the signup page and its form, which answers
 * every address the same way; the confirm and unsubscribe pages, where a GET or HEAD never acts, only the button's
 * POST does, and a mail client's RFC 8058 one-click POST works with no cookie and no session; the links to the
 * signup page on the hubs and at an article's end; and the privacy and cookie notices. Rows and headers first, words
 * second.
 */
final class DigestPagesTest extends TestCase
{
    private const BASE = 'https://patternsshowcontrol.test';

    private function subscribers(): Subscribers
    {
        return new Subscribers($this->app);
    }

    private function tokens(): Tokens
    {
        return Tokens::fromApp($this->app);
    }

    /** @return array<string, mixed>|null the subscriber row for an address */
    private function row(string $email): ?array
    {
        return $this->db->one('SELECT * FROM news_digest_subscribers WHERE email_hash = ?', [$this->tokens()->emailHash($email)]);
    }

    /** @return array<string, mixed> a pending row made as the signup page makes one */
    private function pending(string $email): array
    {
        $result = $this->subscribers()->request($email, '2026-10-23', '2026-10-23', time());
        self::assertNotNull($result['subscriber']);
        return $result['subscriber'];
    }

    /** @return array<string, mixed> a confirmed row */
    private function confirmed(string $email): array
    {
        $row = $this->pending($email);
        self::assertSame('confirmed', $this->subscribers()->confirm((string) $row['selector'], (int) $row['generation'], time()));
        return (array) $this->subscribers()->bySelector((string) $row['selector']);
    }

    /** @param array<string, mixed> $extra */
    private function signUp(string $email, array $extra = []): Response
    {
        return $this->post(Links::SIGNUP, $extra + ['email' => $email] + $this->filledIn('digest'));
    }

    /** @return list<array<string, mixed>> the confirmation emails sent to an address */
    private function confirmations(string $email): array
    {
        return array_values(array_filter($this->mails(), static fn (array $m): bool => $m['template'] === 'digest-confirm' && $m['to'] === $email));
    }

    private static function tokenOf(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        return is_string($query['token'] ?? null) ? $query['token'] : '';
    }

    private function confirmUri(array $row): string
    {
        return Links::confirm($this->tokens()->make(Tokens::CONFIRM, (string) $row['selector'], (int) ($row['generation'] ?? 0)));
    }

    private function unsubscribeUri(array $row): string
    {
        return Links::unsubscribe($this->tokens()->make(Tokens::UNSUBSCRIBE, (string) $row['selector']));
    }

    /** A request as a mail client or a link scanner makes it: no cookie, no session. */
    private function bare(string $method, string $uri, array $post = [], array $headers = []): Response
    {
        $this->jar = [];
        $_SESSION = [];
        $r = $this->request($method, $uri, $post, $headers);
        self::assertSame([], $r->cookies(), $method . ' ' . $uri . ' sets no cookie');
        self::assertFalse($this->app->session()->isStarted(), $method . ' ' . $uri . ' starts no session');
        self::assertFalse($r->hasHeader('Location'), $method . ' ' . $uri . ' is never a redirect');
        self::assertNotSame(3, intdiv($r->status, 100), $method . ' ' . $uri . ' is never a redirect');
        return $r;
    }

    /** The confirm and unsubscribe pages' head: never stored, never indexed, no canonical, no referrer. */
    private static function assertTokenPageHead(Response $r): void
    {
        self::assertSame('private, no-store', $r->getHeader('Cache-Control'));
        self::assertSame('noindex, nofollow', $r->getHeader('X-Robots-Tag'));
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $r->body);
        self::assertStringContainsString('<meta name="referrer" content="no-referrer">', $r->body);
        self::assertStringNotContainsString('rel="canonical"', $r->body);
        self::assertStringNotContainsString('application/ld+json', $r->body, 'no structured data on a page never indexed');
        self::assertSame(1, substr_count($r->body, '<h1'), 'one h1');
        self::assertStringNotContainsString('name="_csrf"', $r->body, 'no CSRF token: the token in the address is the only authority');
    }

    private static function digestWords(): array
    {
        return Content::get('news')['digest'];
    }

    // ===== The signup page =====

    public function testTheSignupPageIsAnIndexableFormOutsideTheNewsSection(): void
    {
        $r = $this->request('GET', Links::SIGNUP);
        self::assertSame(200, $r->status);
        self::assertSame('private, no-store', $r->getHeader('Cache-Control'), 'a form token: never cached');
        self::assertSame('index, follow', $r->getHeader('X-Robots-Tag'));
        self::assertMatchesRegularExpression('#<meta name="robots" content="index, follow[^"]*">#', $r->body);
        self::assertStringContainsString('<link rel="canonical" href="' . self::BASE . '/digest/">', $r->body);
        self::assertNotEmpty($_SESSION['_csrf'] ?? null, 'a session, for the form');
        self::assertStringContainsString('name="_csrf"', $r->body);
        self::assertStringContainsString('name="_started"', $r->body, 'FormGuard\'s signed time');
        self::assertStringContainsString('name="website"', $r->body, 'FormGuard\'s honeypot');
        self::assertSame(1, substr_count($r->body, '<h1'), 'one h1');
        self::assertStringContainsString('<h1 class="c-hero-title" id="page-title">The weekly digest</h1>', $r->body);
        self::assertStringContainsString('every Friday from 09:00 UK time.', $r->body, 'the desk\'s day and time');
        self::assertStringContainsString('id="digest-email" name="email" type="email" autocomplete="email" maxlength="190"', $r->body);
        self::assertStringContainsString('<form class="c-form c-form--panel" id="form" method="post" action="/digest/#form" novalidate>', $r->body);
        self::assertStringNotContainsString('type="checkbox"', $r->body, 'no tick box: pressing the button after the consent words is the request');
        foreach (self::digestWords()['page']['what'] as $line) {
            self::assertStringContainsString(e($line), $r->body);
        }
        self::assertStringContainsString(e(self::digestWords()['page']['consent']), $r->body);
        self::assertStringContainsString('<a href="/legal/privacy/">How we use your address</a>', $r->body);
        self::assertStringContainsString('<a href="/portal/account/#details">', $r->body);
        self::assertStringContainsString('<a href="/news/feed.xml" type="application/rss+xml">', $r->body);
        self::assertStringNotContainsString('id="sent"', $r->body, 'nothing sent yet');
        // The header marks News; the trail is Home, Industry Updates, Weekly digest.
        self::assertMatchesRegularExpression('#<a[^>]*href="/news/"[^>]*aria-current#', $r->body);
        self::assertStringContainsString('"name":"Weekly digest"', $r->body);

        $page = Content::page(Links::SIGNUP);
        self::assertNotNull($page);
        self::assertLessThanOrEqual(60, mb_strlen((string) $page['title']));
        self::assertGreaterThanOrEqual(50, mb_strlen((string) $page['description']));
        self::assertLessThanOrEqual(155, mb_strlen((string) $page['description']));
        self::assertStringContainsString('<title>' . e((string) $page['title']) . '</title>', $r->body);

        // The page's markup carries no inline style or script.
        $template = (string) file_get_contents(PSC_ROOT . '/templates/news/digest.php');
        self::assertStringNotContainsString('style=', $template);
        self::assertStringNotContainsString('<script', $template);

        $sitemap = $this->request('GET', '/sitemap.xml')->body;
        self::assertStringContainsString("<loc>" . self::BASE . "/digest/</loc>\n    <lastmod>2026-10-23</lastmod>", $sitemap);
        self::assertStringNotContainsString('/digest/confirm/', $sitemap);
        self::assertStringNotContainsString('/digest/unsubscribe/', $sitemap);
        $llms = $this->request('GET', '/llms.txt')->body;
        self::assertStringContainsString("\n- [Weekly digest](" . self::BASE . "/digest/): " . $page['description'] . "\n", $llms);
        self::assertStringNotContainsString('/digest/confirm/', $llms);
        self::assertStringNotContainsString('/digest/unsubscribe/', $llms);
    }

    public function testTheVersionsAreTheSignupWordsAndThePrivacyNoticesDate(): void
    {
        self::assertSame(['consent' => '2026-10-23', 'privacy' => (string) Content::page('/legal/privacy/')['updated']], DigestController::versions($this->app));
    }

    public function testANewAddressIsStoredPendingAndSentOneConfirmation(): void
    {
        $r = $this->signUp('  New.Reader@Example.test ');
        self::assertSame(303, $r->status);
        self::assertSame('/digest/#sent', $r->getHeader('Location'));
        $row = $this->row('new.reader@example.test');
        self::assertNotNull($row);
        self::assertSame('pending', $row['state']);
        self::assertSame('new.reader@example.test', $row['email'], 'normalised');
        self::assertSame('page', $row['source']);
        self::assertNull($row['user_id'], 'the public page never ties a row to an account');
        self::assertSame(DigestController::versions($this->app), ['consent' => $row['consent_version'], 'privacy' => $row['privacy_version']]);
        self::assertNull($row['confirmed_at'], 'nothing is confirmed by the form');

        $mails = $this->confirmations('new.reader@example.test');
        self::assertCount(1, $mails);
        self::assertSame('Confirm your Industry Updates digest', $mails[0]['subject']);
        $url = (string) $mails[0]['data']['url'];
        self::assertStringStartsWith(self::BASE . '/digest/confirm/?token=', $url);
        self::assertSame($row['selector'], $this->tokens()->read(Tokens::CONFIRM, self::tokenOf($url)), 'the link carries the row\'s confirm token');
        self::assertNull($this->tokens()->read(Tokens::UNSUBSCRIBE, self::tokenOf($url)), 'a confirm token never unsubscribes');

        $page = $this->request('GET', Links::SIGNUP);
        self::assertStringContainsString('<div class="c-callout c-callout--success" id="sent" role="status"', $page->body);
        self::assertStringContainsString('Check your inbox', $page->body);
        self::assertStringContainsString(e(self::digestWords()['page']['sent']), $page->body);
        self::assertStringNotContainsString('id="sent"', $this->request('GET', Links::SIGNUP)->body, 'said once');
    }

    /**
     * A fence: a new address, one already waiting, one already subscribed and one that had unsubscribed are answered
     * with the same status, the same address and the same page, word for word but for the form's signed time, so
     * the form never says whether an address gets the digest.
     */
    public function testEveryAddressIsAnsweredTheSameWhateverItsState(): void
    {
        $this->confirmed('subscribed@example.test');
        $gone = $this->confirmed('returning@example.test');
        self::assertSame('done', $this->subscribers()->unsubscribe((string) $gone['selector'], 'page', time()));
        $answers = [];
        $pages = [];
        foreach (['new' => 'fresh@example.test', 'again' => 'fresh@example.test', 'confirmed' => 'subscribed@example.test', 'returning' => 'returning@example.test'] as $kind => $email) {
            $r = $this->signUp($email);
            $answers[$kind] = [$r->status, $r->getHeader('Location'), $r->body];
            $pages[$kind] = self::withoutFormTimes($this->request('GET', Links::SIGNUP)->body);
        }
        self::assertSame([$answers['new']], array_values(array_unique($answers, SORT_REGULAR)), 'the same answer');
        self::assertSame([303, '/digest/#sent'], array_slice($answers['new'], 0, 2));
        self::assertCount(1, array_unique($pages), 'the same page, but for the form\'s time');
        self::assertStringContainsString('Check your inbox', $pages['confirmed']);

        self::assertCount(2, $this->confirmations('fresh@example.test'), 'new, then asked again: sent again');
        self::assertSame([], $this->confirmations('subscribed@example.test'), 'already subscribed: nothing sent');
        self::assertCount(1, $this->confirmations('returning@example.test'), 'unsubscribed, then asked again: a confirmation');
        self::assertSame('confirmed', $this->row('subscribed@example.test')['state'] ?? null, 'nothing changed');
        $returning = $this->row('returning@example.test');
        self::assertSame('pending', $returning['state'] ?? null, 'waiting for the button again');
        self::assertSame('returning@example.test', $returning['email'] ?? null);
    }

    public function testEachFieldErrorComesBackWithTheInputKept(): void
    {
        $errors = self::digestWords()['errors'];
        $cases = [
            '' => $errors['email_empty'],
            str_repeat('a', 180) . '@example.test' => $errors['email_long'],
            'not an address' => $errors['email_format'],
            'someone@deleted.invalid' => $errors['email_format'],
        ];
        foreach ($cases as $email => $message) {
            $r = $this->signUp($email);
            self::assertSame(303, $r->status, $email);
            self::assertSame('/digest/#form', $r->getHeader('Location'), $email);
            $page = $this->request('GET', Links::SIGNUP)->body;
            self::assertStringContainsString('<p class="c-error-msg" id="digest-email-err">' . e($message) . '</p>', $page, $email);
            self::assertStringContainsString('aria-invalid="true" aria-describedby="digest-email-help digest-email-err"', $page, $email);
            self::assertStringContainsString('value="' . e($email) . '"', $page, 'the input kept: ' . $email);
            self::assertStringContainsString('id="form"', $page, 'the summary where the redirect lands');
            self::assertStringNotContainsString('id="sent"', $page);
        }
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM news_digest_subscribers'));
        self::assertSame([], $this->mails());
    }

    public function testAFilledHoneypotIsAnsweredAsSentAndNothingIsStoredOrSent(): void
    {
        $r = $this->signUp('bot@example.test', ['website' => 'https://spam.example']);
        self::assertSame(303, $r->status);
        self::assertSame('/digest/#sent', $r->getHeader('Location'));
        self::assertNull($this->row('bot@example.test'));
        self::assertSame([], $this->mails());
        self::assertStringContainsString('Check your inbox', $this->request('GET', Links::SIGNUP)->body);
    }

    public function testAQuickSendComesBackWithTheAddressKeptAndNothingSent(): void
    {
        $r = $this->post(Links::SIGNUP, ['email' => 'quick@example.test'] + $this->filledIn('digest', 0));
        self::assertSame(303, $r->status);
        self::assertSame('/digest/#form', $r->getHeader('Location'));
        self::assertNull($this->row('quick@example.test'));
        self::assertSame([], $this->mails());
        $page = $this->request('GET', Links::SIGNUP)->body;
        self::assertStringContainsString(e(self::digestWords()['errors']['quick']), $page);
        self::assertStringContainsString('value="quick@example.test"', $page);
        self::assertSame(2, \Psc\Support\FormGuard::MIN_SECONDS['digest']);
    }

    public function testOneNetworkCanSendTenSignUpsAnHour(): void
    {
        for ($i = 1; $i <= DigestController::PER_IP_PER_HOUR; $i++) {
            self::assertSame('/digest/#sent', $this->signUp('reader' . $i . '@example.test')->getHeader('Location'));
        }
        $r = $this->signUp('one-more@example.test');
        self::assertSame('/digest/#form', $r->getHeader('Location'));
        self::assertNull($this->row('one-more@example.test'), 'nothing stored');
        self::assertSame([], $this->confirmations('one-more@example.test'), 'nothing sent');
        $page = $this->request('GET', Links::SIGNUP)->body;
        self::assertStringContainsString(e(self::digestWords()['errors']['limited']), $page);
        self::assertStringContainsString('value="one-more@example.test"', $page);
    }

    public function testAnAddressIsSentAtMostThreeConfirmationsADayAndTheFourthSendLooksTheSame(): void
    {
        $answers = [];
        for ($i = 1; $i <= DigestController::CONFIRMATIONS_PER_DAY + 1; $i++) {
            $r = $this->signUp('inbox@example.test');
            $answers[] = [$r->status, $r->getHeader('Location')];
        }
        self::assertSame(array_fill(0, 4, [303, '/digest/#sent']), $answers);
        self::assertCount(DigestController::CONFIRMATIONS_PER_DAY, $this->confirmations('inbox@example.test'), 'no fourth email');
        self::assertStringContainsString('Check your inbox', $this->request('GET', Links::SIGNUP)->body);
    }

    /** Sub-addresses (and Gmail's dots) reach one mailbox, so they share its daily confirmations. */
    public function testSubAddressesOfOneMailboxShareItsConfirmations(): void
    {
        foreach (['victim+1@example.test', 'victim+2@example.test', 'Victim+3@Example.test', 'victim+4@example.test'] as $email) {
            self::assertSame('/digest/#sent', $this->signUp($email)->getHeader('Location'), $email);
        }
        $sent = array_filter($this->mails(), static fn (array $m): bool => $m['template'] === 'digest-confirm');
        self::assertCount(DigestController::CONFIRMATIONS_PER_DAY, $sent, 'one mailbox, one allowance');
        foreach (['j.doe@gmail.com', 'jdoe@googlemail.com', 'j.d.o.e+news@gmail.com', 'jdoe@gmail.com'] as $email) {
            $this->signUp($email);
        }
        $gmail = array_filter($this->mails(), static fn (array $m): bool => $m['template'] === 'digest-confirm' && str_contains((string) $m['to'], 'mail.com'));
        self::assertCount(DigestController::CONFIRMATIONS_PER_DAY, $gmail);
    }

    /** Quoting a local part (RFC 5322) spells the same mailbox, so every spelling shares its daily confirmations. */
    public function testQuotedSpellingsOfOneMailboxShareItsConfirmations(): void
    {
        foreach (['"jdoe"@example.org', '"\\j\\doe"@example.org', '"j\\doe"@example.org', '"\\jdoe"@example.org', 'jdoe@example.org'] as $email) {
            self::assertSame('/digest/#sent', $this->signUp($email)->getHeader('Location'), $email);
        }
        $sent = array_filter($this->mails(), static fn (array $m): bool => $m['template'] === 'digest-confirm');
        self::assertCount(DigestController::CONFIRMATIONS_PER_DAY, $sent, 'one mailbox, one allowance');
    }

    /**
     * The one answer every sign-up gets ("Check your inbox") stays true when nothing was sent: for an address that
     * already gets the digest, and for one past its daily confirmations, the words name both.
     */
    public function testTheAnswerHoldsWhenNoConfirmationWasSent(): void
    {
        $words = self::digestWords()['page']['sent'];
        $this->confirmed('subscribed@example.test');
        $this->signUp('subscribed@example.test');
        self::assertSame([], $this->confirmations('subscribed@example.test'), 'already subscribed: nothing sent');
        self::assertStringContainsString(e($words), $this->request('GET', Links::SIGNUP)->body);
        self::assertStringStartsWith('Unless that address already gets the digest, or has had ' . ['one', 'two', 'three', 'four', 'five'][DigestController::CONFIRMATIONS_PER_DAY - 1] . ' of these emails in the last day, we have sent it an email', $words);
    }

    public function testASendWithoutTheFormsTokenIsRefused(): void
    {
        $this->request('GET', Links::SIGNUP);
        $r = $this->post(Links::SIGNUP, ['email' => 'csrf@example.test'] + $this->filledIn('digest'), false);
        self::assertSame(419, $r->status);
        self::assertNull($this->row('csrf@example.test'));
    }

    public function testADatabaseFailureBringsTheFormBackWithTheAddressKept(): void
    {
        $this->subscribers();
        $this->refuseWrites('digest_refused', 'INSERT', 'news_digest_subscribers');
        $r = $this->signUp('later@example.test');
        self::assertSame('/digest/#form', $r->getHeader('Location'));
        $page = $this->request('GET', Links::SIGNUP)->body;
        self::assertStringContainsString(e(self::digestWords()['errors']['unavailable']), $page);
        self::assertStringContainsString('value="later@example.test"', $page);
        self::assertSame([], $this->mails());
    }

    // ===== The confirm page =====

    public function testAGetOrHeadOfTheConfirmLinkChangesNothing(): void
    {
        $row = $this->pending('confirm-me@example.test');
        foreach (['GET', 'HEAD'] as $method) {
            $r = $this->bare($method, $this->confirmUri($row));
            self::assertSame(200, $r->status, $method);
            self::assertTokenPageHead($r);
            self::assertSame('pending', $this->row('confirm-me@example.test')['state'], $method . ' confirms nothing');
        }
        self::assertStringContainsString('<h1 class="c-hero-title" id="page-title">Confirm your digest</h1>', $r->body);
        self::assertStringContainsString('<title>Confirm your digest | Industry Updates</title>', $r->body);
        self::assertStringContainsString('at confirm-me@example.test.', $r->body, 'the address, on the ask page');
        self::assertStringContainsString('<form method="post" action="' . e($this->confirmUri($row)) . '">', $r->body, 'the button posts to the page\'s own address');
        self::assertStringContainsString('>Start my digest</button>', $r->body);
        self::assertStringNotContainsString('<input', $r->body, 'a button only, no field');
        self::assertStringNotContainsString('/digest/confirm/?token=', (string) preg_replace('#<form method="post" action="[^"]*">#', '', $r->body), 'the token is nowhere else on the page');
    }

    public function testTheConfirmButtonConfirmsAndOffersTheWayOut(): void
    {
        $row = $this->pending('confirm-me@example.test');
        $r = $this->bare('POST', $this->confirmUri($row));
        self::assertSame(200, $r->status);
        self::assertTokenPageHead($r);
        $after = $this->row('confirm-me@example.test');
        self::assertSame('confirmed', $after['state']);
        self::assertNotNull($after['confirmed_at']);
        self::assertStringContainsString('<h1 class="c-hero-title" id="page-title">You are subscribed</h1>', $r->body);
        self::assertStringContainsString('will come to confirm-me@example.test, every Friday from 09:00 UK time.', $r->body);
        self::assertSame(1, preg_match('#<a href="(/digest/unsubscribe/\?token=[^"]+)">Changed your mind\? Unsubscribe</a>#', $r->body, $m));
        self::assertSame($row['selector'], $this->tokens()->read(Tokens::UNSUBSCRIBE, self::tokenOf(html_entity_decode($m[1]))), 'the row\'s own unsubscribe link');

        $again = $this->bare('POST', $this->confirmUri($row));
        self::assertSame(200, $again->status);
        self::assertStringContainsString('You are already subscribed', $again->body);
        self::assertStringContainsString('You are already subscribed', $this->bare('GET', $this->confirmUri($row))->body);
    }

    public function testAConfirmLinkExpiresSevenDaysAfterItWasAskedFor(): void
    {
        $row = $this->pending('late@example.test');
        // A second inside the 7 days the page still asks; at 7 days the page and its button agree it has expired.
        $this->db->exec('UPDATE news_digest_subscribers SET requested_at = ? WHERE id = ?', [time() - Tokens::CONFIRM_SECONDS + 5, (int) $row['id']]);
        self::assertStringContainsString('>Start my digest</button>', $this->bare('GET', $this->confirmUri($row))->body);
        $this->db->exec('UPDATE news_digest_subscribers SET requested_at = ? WHERE id = ?', [time() - Tokens::CONFIRM_SECONDS, (int) $row['id']]);
        foreach (['GET', 'POST'] as $method) {
            $r = $this->bare($method, $this->confirmUri($row));
            self::assertSame(200, $r->status, $method);
            self::assertStringContainsString('<h1 class="c-hero-title" id="page-title">This link has expired</h1>', $r->body, $method);
            self::assertStringContainsString('<a class="c-btn c-btn--primary" href="/digest/">Sign up again</a>', $r->body);
        }
        self::assertSame('pending', $this->row('late@example.test')['state'], 'not confirmed');

        // Housekeeping removed the row: the link has expired too.
        $gone = $this->pending('removed@example.test');
        $this->db->exec('DELETE FROM news_digest_subscribers WHERE id = ?', [(int) $gone['id']]);
        foreach (['GET', 'POST'] as $method) {
            self::assertStringContainsString('This link has expired', $this->bare($method, $this->confirmUri($gone))->body, $method);
        }
    }

    public function testAConfirmLinkForAnAddressThatUnsubscribedSinceNoLongerWorks(): void
    {
        $row = $this->confirmed('left@example.test');
        $this->subscribers()->unsubscribe((string) $row['selector'], 'page', time());
        foreach (['GET', 'POST'] as $method) {
            $r = $this->bare($method, $this->confirmUri($row));
            self::assertSame(200, $r->status);
            self::assertStringContainsString('<h1 class="c-hero-title" id="page-title">This link no longer works</h1>', $r->body, $method);
        }
        self::assertSame('unsubscribed', $this->db->value('SELECT state FROM news_digest_subscribers WHERE id = ?', [(int) $row['id']]));
    }

    /**
     * Whoever still holds a confirmation from before an unsubscribe (a forwarded email, a shared browser's history)
     * can never use it to confirm a later request, whoever made that one: the generation moved on.
     */
    public function testAConfirmLinkFromBeforeAWithdrawalNeverConfirmsALaterRequest(): void
    {
        $this->signUp('kept@example.test');
        $old = (string) $this->confirmations('kept@example.test')[0]['data']['url'];
        $path = (string) parse_url($old, PHP_URL_PATH) . '?' . (string) parse_url($old, PHP_URL_QUERY);
        self::assertStringContainsString('You are subscribed', $this->bare('POST', $path)->body);
        $row = $this->row('kept@example.test');
        self::assertNotNull($row);
        $this->bare('POST', $this->unsubscribeUri($row), ['List-Unsubscribe' => 'One-Click']);

        // Someone types the address into the form again: a new confirmation, with a new link.
        $this->jar = [];
        $_SESSION = [];
        $this->signUp('kept@example.test');
        $new = (string) $this->confirmations('kept@example.test')[1]['data']['url'];
        self::assertNotSame(self::tokenOf($old), self::tokenOf($new), 'a new request, a new link');
        foreach (['GET', 'POST'] as $method) {
            $r = $this->bare($method, $path);
            self::assertSame(200, $r->status, $method);
            self::assertStringContainsString('<h1 class="c-hero-title" id="page-title">This link no longer works</h1>', $r->body, $method);
        }
        $after = $this->row('kept@example.test');
        self::assertSame(['pending', 1], [$after['state'], (int) $after['generation']], 'the old link confirmed nothing');
        self::assertNotNull($after['unsubscribed_at'], 'the withdrawal still stands');

        $newPath = (string) parse_url($new, PHP_URL_PATH) . '?' . (string) parse_url($new, PHP_URL_QUERY);
        self::assertStringContainsString('You are subscribed', $this->bare('POST', $newPath)->body, 'the newest link works');
        self::assertSame('confirmed', $this->row('kept@example.test')['state']);
    }

    public function testATokenThatIsNotOursIsANotFoundPageAndThirtyAnHourAreLimited(): void
    {
        $row = $this->pending('real@example.test');
        $good = $this->tokens()->make(Tokens::CONFIRM, (string) $row['selector']);
        $changed = substr($good, 0, -1) . (str_ends_with($good, 'A') ? 'B' : 'A');
        $bad = [
            '/digest/confirm/',
            '/digest/confirm/?token=',
            '/digest/confirm/?token=nonsense',
            Links::confirm($this->tokens()->make(Tokens::UNSUBSCRIBE, (string) $row['selector'])),
            '/digest/unsubscribe/?token=' . rawurlencode($this->tokens()->make(Tokens::CONFIRM, (string) $row['selector'])),
            Links::confirm($changed),
        ];
        $n = 0;
        foreach ($bad as $uri) {
            foreach (['GET', 'POST'] as $method) {
                $r = $this->bare($method, $uri);
                $n++;
                self::assertSame(404, $r->status, $method . ' ' . $uri);
                self::assertTokenPageHead($r);
                self::assertStringContainsString('<h1 class="c-hero-title" id="page-title">This link does not work</h1>', $r->body);
            }
        }
        self::assertSame('pending', $this->row('real@example.test')['state'], 'nothing acted');
        while ($n < DigestController::BAD_LINKS_PER_HOUR) {
            self::assertSame(404, $this->bare('GET', '/digest/confirm/?token=x' . $n)->status);
            $n++;
        }
        $limited = $this->bare('GET', '/digest/unsubscribe/?token=again');
        self::assertSame(429, $limited->status, 'the thirty-first in the hour');
        self::assertSame('3600', $limited->getHeader('Retry-After'));
        // A good token is never limited: a mail provider's one-click POSTs come from a few addresses.
        self::assertSame(200, $this->bare('GET', $this->confirmUri($row))->status);
        self::assertSame(200, $this->bare('POST', $this->unsubscribeUri($row), ['List-Unsubscribe' => 'One-Click'])->status);
    }

    public function testTheTokenPagesCarryNoAnalytics(): void
    {
        $this->bootApp(['analytics' => ['ga_id' => 'G-TEST1234']]);
        $row = $this->pending('quiet@example.test');
        self::assertStringContainsString('G-TEST1234', $this->request('GET', Links::SIGNUP)->body, 'the signup page has the site\'s tag');
        foreach ([$this->confirmUri($row), $this->unsubscribeUri($row)] as $uri) {
            self::assertStringNotContainsString('G-TEST1234', $this->bare('GET', $uri)->body, 'the token never reaches Google: ' . $uri);
        }
    }

    // ===== The unsubscribe page =====

    public function testAGetOfTheUnsubscribeLinkChangesNothing(): void
    {
        $row = $this->confirmed('stay@example.test');
        foreach (['GET', 'HEAD'] as $method) {
            $r = $this->bare($method, $this->unsubscribeUri($row));
            self::assertSame(200, $r->status);
            self::assertTokenPageHead($r);
            self::assertSame('confirmed', $this->row('stay@example.test')['state'], $method . ' unsubscribes nobody');
        }
        self::assertStringContainsString('<h1 class="c-hero-title" id="page-title">Unsubscribe from the digest</h1>', $r->body);
        self::assertStringContainsString('at stay@example.test.', $r->body);
        self::assertStringContainsString('<form method="post" action="' . e($this->unsubscribeUri($row)) . '">', $r->body);
        self::assertStringContainsString('>Unsubscribe</button>', $r->body);
        // A pending row can be stopped too.
        $pending = $this->pending('waiting@example.test');
        self::assertStringContainsString('at waiting@example.test.', $this->bare('GET', $this->unsubscribeUri($pending))->body);
    }

    public function testThePagesButtonUnsubscribesAtOnce(): void
    {
        $row = $this->confirmed('leave@example.test');
        $r = $this->bare('POST', $this->unsubscribeUri($row));
        self::assertSame(200, $r->status);
        self::assertTokenPageHead($r);
        $after = $this->db->one('SELECT * FROM news_digest_subscribers WHERE id = ?', [(int) $row['id']]);
        self::assertSame('unsubscribed', $after['state']);
        self::assertSame('page', $after['unsubscribe_method']);
        self::assertNull($after['email'], 'the address deleted there and then');
        self::assertNotNull($after['unsubscribed_at']);
        self::assertStringContainsString('<h1 class="c-hero-title" id="page-title">You are unsubscribed</h1>', $r->body);
        self::assertStringContainsString('<a href="/digest/">Changed your mind? Sign up again</a>', $r->body);
        self::assertStringNotContainsString('leave@example.test', $r->body);
        self::assertSame([], $this->mails(), 'no "you have unsubscribed" email');

        $again = $this->bare('POST', $this->unsubscribeUri($row));
        self::assertSame(200, $again->status);
        self::assertStringContainsString('You are not subscribed', $again->body);
        self::assertStringContainsString('You are not subscribed', $this->bare('GET', $this->unsubscribeUri($row))->body);
    }

    /**
     * RFC 8058: the mail client's POST of List-Unsubscribe=One-Click, as multipart/form-data and as urlencoded, with
     * no cookie, to the exact address the digest's List-Unsubscribe header names: 200 with the page, never a redirect,
     * no cookie set, and the row unsubscribed by one_click with its address gone.
     */
    public function testAMailClientsOneClickPostUnsubscribesWithNoCookie(): void
    {
        $types = ['multipart/form-data; boundary=psc-test-boundary', 'application/x-www-form-urlencoded'];
        foreach ($types as $i => $type) {
            $row = $this->confirmed('one-click-' . $i . '@example.test');
            $header = DigestMail::headers($this->app, $this->tokens()->make(Tokens::UNSUBSCRIBE, (string) $row['selector']))['List-Unsubscribe'];
            self::assertSame(1, preg_match('#^<(https://[^>]+)>#', $header, $m), 'the https address first');
            self::assertStringStartsWith(self::BASE . '/digest/unsubscribe/?token=', $m[1]);
            $uri = substr($m[1], strlen(self::BASE));
            $r = $this->bare('POST', $uri, ['List-Unsubscribe' => 'One-Click'], ['Content-Type' => $type]);
            self::assertSame(200, $r->status, $type);
            $after = $this->db->one('SELECT * FROM news_digest_subscribers WHERE id = ?', [(int) $row['id']]);
            self::assertSame('unsubscribed', $after['state'], $type);
            self::assertSame('one_click', $after['unsubscribe_method'], $type);
            self::assertNull($after['email'], $type);
            self::assertNull($after['user_id'], $type);
            self::assertSame(200, $this->bare('POST', $uri, ['List-Unsubscribe' => 'One-Click'], ['Content-Type' => $type])->status, 'a second POST');
            self::assertSame('one_click', $this->db->value('SELECT unsubscribe_method FROM news_digest_subscribers WHERE id = ?', [(int) $row['id']]), 'the first one stands');
        }
    }

    public function testTheTestDigestsLinkChangesNothing(): void
    {
        $this->confirmed('real@example.test');
        $uri = Links::unsubscribe($this->tokens()->make(Tokens::UNSUBSCRIBE, Tokens::TEST_SELECTOR));
        foreach (['GET', 'POST'] as $method) {
            $r = $this->bare($method, $uri, $method === 'POST' ? ['List-Unsubscribe' => 'One-Click'] : []);
            self::assertSame(200, $r->status, $method);
            self::assertStringContainsString('<h1 class="c-hero-title" id="page-title">This was a test</h1>', $r->body, $method);
        }
        self::assertSame('confirmed', $this->row('real@example.test')['state']);
        self::assertSame(404, $this->bare('POST', '/digest/unsubscribe/?token=' . Tokens::TEST_SELECTOR . '.nope')->status);
    }

    // ===== Where the signup is linked =====

    public function testTheFollowRowOnTheHubsLinksToTheSignupPage(): void
    {
        $this->makeArticle(['slug' => 'digest-follow-story']);
        foreach (['/news/', '/news/video/'] as $path) {
            $r = $this->request('GET', $path);
            self::assertSame(200, $r->status, $path);
            self::assertMatchesRegularExpression('#<ul class="news-follow-list"[^>]*>\s*<li><a href="/digest/"><svg class="c-icon c-icon--sm"[^>]*><use href="\#i-mail"/></svg>Weekly digest by email</a></li>#', $r->body, $path);
            self::assertSame([], $r->cookies(), $path . ' still sets no cookie');
        }
    }

    public function testAnArticleEndsWithTheDigestsBlockButItsPreviewDoesNot(): void
    {
        $id = $this->makeArticle(['slug' => 'digest-end-story']);
        $r = $this->request('GET', '/news/video/digest-end-story/');
        self::assertSame(200, $r->status);
        self::assertStringContainsString('<section class="news-part news-digest" aria-labelledby="news-digest">', $r->body);
        self::assertStringContainsString('<h2 class="news-digest-title" id="news-digest">Get Industry Updates by email</h2>', $r->body);
        self::assertMatchesRegularExpression('#<a class="c-btn c-btn--secondary" href="/digest/">.*Get the weekly digest</a>#', $r->body);
        self::assertLessThan(strpos($r->body, 'news-digest'), strpos($r->body, 'news-share'), 'after the share row');
        self::assertSame([], $r->cookies());

        $this->signInAdmin();
        $preview = $this->request('GET', '/portal/admin/news/' . $id . '/preview/');
        self::assertSame(200, $preview->status);
        self::assertStringNotContainsString('news-digest', $preview->body, 'never in the preview');
    }

    // ===== The notices =====

    public function testThePrivacyAndCookieNoticesNameTheDigest(): void
    {
        self::assertSame('2026-10-23', Content::page('/legal/privacy/')['updated']);
        self::assertSame('2026-10-23', Content::page('/legal/cookies/')['updated']);
        $privacy = $this->request('GET', '/legal/privacy/')->body;
        self::assertStringContainsString('<time datetime="2026-10-23">', $privacy);
        self::assertStringContainsString('<strong>The Industry Updates weekly digest</strong>', $privacy);
        // Consent is the confirmation's button wherever the digest was asked for, the account page's tick included.
        self::assertStringContainsString('Lawful basis: your consent, which you give by pressing the button on the page our confirmation email leads to, wherever you asked: on the digest page, on your account page, or when you created your account (then that email follows once your address is confirmed).', $privacy);
        self::assertStringNotContainsString('by ticking the box', $privacy, 'a tick asks, and gives no consent');
        self::assertStringContainsString('We record no IP address for it.', $privacy);
        self::assertStringContainsString('Digest sign-ups never confirmed: 7 days, then deleted.', $privacy);
        self::assertStringContainsString('The record of which digests were sent to you: 12 months.', $privacy);
        self::assertStringContainsString('The download includes your digest subscription; deleting the account ends it.', $privacy);
        self::assertStringContainsString('the contact form, the digest sign-up page, the client portal', $privacy);
        $cookies = $this->request('GET', '/legal/cookies/')->body;
        self::assertStringContainsString('The contact form, the digest sign-up page, the client portal', $cookies);
        self::assertStringContainsString('the contact page, the digest sign-up page or a client portal page', $cookies);
        self::assertStringContainsString('The confirm and unsubscribe links in our emails set no cookie.', $cookies);
    }
}
