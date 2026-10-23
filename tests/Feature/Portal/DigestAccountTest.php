<?php

declare(strict_types=1);

namespace Psc\Tests\Feature\Portal;

use Psc\Content\Content;
use Psc\Controllers\DigestController;
use Psc\Http\Response;
use Psc\News\Digest\Subscribers;
use Psc\News\Digest\Tokens;
use Psc\Tests\TestCase;

/**
 * The weekly digest on the account (docs/NEWS.md §14.7): the account page's tick, consent of its own that never
 * moves marketing_opt_in nor is moved by it, a request that only the digest's own confirmation's button starts, and
 * saved only when the person moved it on a page that still shows the subscription as it is; registration's tick,
 * which waits for the address to be proven; the copy of the data; and deleting the account, which ends the
 * subscription and keeps only the suppression record. Rows first, words second.
 */
final class DigestAccountTest extends TestCase
{
    private const PASSWORD = 'correct horse battery';

    private function subscribers(): Subscribers
    {
        return new Subscribers($this->app);
    }

    /** @return array<string, mixed>|null the subscriber row for an address */
    private function row(string $email): ?array
    {
        return $this->db->one('SELECT * FROM news_digest_subscribers WHERE email_hash = ?', [Tokens::fromApp($this->app)->emailHash($email)]);
    }

    private function account(): string
    {
        return $this->request('GET', '/portal/account/')->body;
    }

    /** The state the account page drew the digest's tick against, as the form posts it back (empty: unknown). */
    private function shown(): string
    {
        return $this->drawnTick()[1];
    }

    /**
     * The digest's tick as the account page draws it now: whether it is ticked, and the state it is drawn against.
     *
     * @return array{bool, string}
     */
    private function drawnTick(): array
    {
        $page = $this->account();
        self::assertSame(1, preg_match('/<input type="hidden" name="digest_shown" value="([01]?)">/', $page, $m), 'the form carries it');
        return [str_contains($page, '<input id="profile-digest" name="digest" type="checkbox" value="1" checked'), $m[1]];
    }

    /** The details form sent from the account page as it stands now. @param array<string, string> $fields */
    private function save(array $fields): Response
    {
        $r = $this->post('/portal/account/profile/', $fields + ['name' => 'Test Client', 'company' => '', 'digest_shown' => $this->shown()]);
        self::assertSame(303, $r->status);
        return $r;
    }

    /** Presses the button on the page an emailed confirmation opens, then signs the account in again. */
    private function pressConfirm(array $mail, string $email = 'client@example.test'): void
    {
        self::assertStringContainsString('You are subscribed', $this->bare('POST', (string) $mail['data']['url'])->body);
        $this->signIn($email);
    }

    private static function tokenFrom(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        self::assertIsString($q['token'] ?? null, 'the link carries a token');
        return (string) $q['token'];
    }

    private function register(string $email, array $extra = []): Response
    {
        return $this->post('/portal/register/', $extra + [
            'name' => 'Nina Stage',
            'email' => $email,
            'company' => '',
            'password' => self::PASSWORD,
            'terms' => '1',
        ] + $this->filledIn('register'));
    }

    private static function words(): array
    {
        return Content::get('news')['digest']['account'];
    }

    // ===== The account page =====

    public function testTheTickShowsTheSubscriptionAsItStands(): void
    {
        $id = $this->makeUser();
        $this->signIn();
        $page = $this->account();
        self::assertStringContainsString('<input id="profile-digest" name="digest" type="checkbox" value="1" aria-describedby="profile-digest-help">', $page, 'unticked without a subscription');
        self::assertStringContainsString('<label for="profile-digest">Email me the weekly Industry Updates digest</label>', $page);
        self::assertStringContainsString('<p class="pt-help" id="profile-digest-help">' . e(self::words()['help']) . '</p>', $page);
        self::assertLessThan(strpos($page, 'id="profile-digest"'), strpos($page, 'id="profile-marketing"'), 'after the product-news tick');

        // A subscription from the public page at the account's own address is the account's.
        $pending = $this->subscribers()->request('client@example.test', '2026-10-23', '2026-10-23', time());
        self::assertSame('confirmed', $this->subscribers()->confirm((string) $pending['subscriber']['selector'], 0, time()));
        self::assertStringContainsString('<input id="profile-digest" name="digest" type="checkbox" value="1" checked', $this->account(), 'ticked with a subscription');
        self::assertSame(0, (int) $this->db->value('SELECT marketing_opt_in FROM users WHERE id = ?', [$id]));
    }

    /**
     * The tick is a request: a verified address is no proof that its mailbox asked (a link scanner could have
     * verified it), so the digest's own confirmation goes, and only the button it leads to starts the digest.
     */
    public function testTickingAsksForTheDigestAndOnlyTheEmailsButtonStartsIt(): void
    {
        $id = $this->makeUser();
        $this->signIn();
        $this->save(['digest' => '1']);
        $row = $this->row('client@example.test');
        self::assertNotNull($row);
        self::assertSame(['pending', 'account', $id, null], [$row['state'], $row['source'], (int) $row['user_id'], $row['confirmed_at']], 'a request, not consent');
        self::assertSame(DigestController::versions($this->app), ['consent' => $row['consent_version'], 'privacy' => $row['privacy_version']]);
        self::assertSame(0, (int) $this->db->value('SELECT marketing_opt_in FROM users WHERE id = ?', [$id]), 'product news untouched');
        $sent = $this->digestConfirmations('client@example.test');
        self::assertCount(1, $sent, 'the digest\'s own confirmation');
        $page = $this->account();
        self::assertStringContainsString(e(strtr(self::words()['asked'], [':email' => 'client@example.test'])), $page);
        self::assertStringContainsString('<input id="profile-digest" name="digest" type="checkbox" value="1" aria-describedby', $page, 'unticked until the button is pressed');
        $audit = json_decode((string) $this->db->value("SELECT meta FROM audit_log WHERE action = 'account.profile_updated' ORDER BY id DESC LIMIT 1"), true);
        self::assertSame(['digest'], $audit['fields'] ?? null, 'named digest in the audit');

        // Only that email's button starts it.
        $this->pressConfirm($sent[0]);
        $confirmed = $this->row('client@example.test');
        self::assertSame(['confirmed', 'account', $id], [$confirmed['state'], $confirmed['source'], (int) $confirmed['user_id']]);
        self::assertNotNull($confirmed['confirmed_at']);
        self::assertStringContainsString('id="profile-digest" name="digest" type="checkbox" value="1" checked', $this->account());

        // Product news on and off leaves the digest as it is.
        $this->save(['digest' => '1', 'marketing' => '1']);
        self::assertSame(1, (int) $this->db->value('SELECT marketing_opt_in FROM users WHERE id = ?', [$id]));
        self::assertSame('confirmed', $this->row('client@example.test')['state']);
        $this->save(['digest' => '1']);
        self::assertSame(0, (int) $this->db->value('SELECT marketing_opt_in FROM users WHERE id = ?', [$id]));
        self::assertSame('confirmed', $this->row('client@example.test')['state']);
        self::assertCount(1, $this->digestConfirmations('client@example.test'), 'a tick left as it was asks nothing');

        // Unticking unsubscribes, by the account, and the address goes.
        $this->save(['marketing' => '1']);
        $after = $this->db->one('SELECT * FROM news_digest_subscribers WHERE id = ?', [(int) $row['id']]);
        self::assertSame('unsubscribed', $after['state']);
        self::assertSame('account', $after['unsubscribe_method']);
        self::assertNull($after['email']);
        self::assertNull($after['user_id']);
        self::assertSame(1, (int) $this->db->value('SELECT marketing_opt_in FROM users WHERE id = ?', [$id]), 'product news as posted');
        $page = $this->account();
        self::assertStringContainsString(e(self::words()['off']), $page);
        self::assertStringContainsString('<input id="profile-digest" name="digest" type="checkbox" value="1" aria-describedby', $page, 'unticked again');

        // Ticking again asks again from the suppression record, and its own button starts it again.
        $this->save(['digest' => '1', 'marketing' => '1']);
        $again = $this->db->one('SELECT * FROM news_digest_subscribers WHERE id = ?', [(int) $row['id']]);
        self::assertSame(['pending', 1], [$again['state'], (int) $again['generation']]);
        $sent = $this->digestConfirmations('client@example.test');
        self::assertCount(2, $sent);
        $this->pressConfirm($sent[1]);
        self::assertSame('confirmed', $this->db->value('SELECT state FROM news_digest_subscribers WHERE id = ?', [(int) $row['id']]));
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM news_digest_subscribers'));
    }

    /** The tick's confirmations come out of the mailbox's daily allowance, shared with the public page's. */
    public function testTheTicksConfirmationSharesTheMailboxsDailyAllowance(): void
    {
        $this->makeUser();
        $this->signIn();
        for ($i = 1; $i <= DigestController::CONFIRMATIONS_PER_DAY; $i++) {
            self::assertSame('/digest/#sent', $this->post('/digest/', ['email' => 'client+' . $i . '@example.test'] + $this->filledIn('digest'))->getHeader('Location'));
        }
        $this->save(['digest' => '1']);
        self::assertSame('pending', $this->row('client@example.test')['state'], 'asked');
        self::assertSame([], $this->digestConfirmations('client@example.test'), 'past the allowance: nothing sent');
        self::assertStringContainsString(e(strtr(self::words()['asked_limited'], [':email' => 'client@example.test'])), $this->account(), 'and the page says so');
    }

    public function testAFormThatChangedNothingStillSaysNothingToSave(): void
    {
        $this->makeUser();
        $this->signIn();
        $this->save([]);
        self::assertStringContainsString('Nothing to save: your details are already up to date.', $this->account());
        $this->save(['digest' => '1']);
        $this->save([]);
        self::assertStringContainsString('Nothing to save: your details are already up to date.', $this->account(), 'waiting for its button, and left unticked');
        $this->pressConfirm($this->digestConfirmations('client@example.test')[0]);
        $this->save(['digest' => '1']);
        self::assertStringContainsString('Nothing to save: your details are already up to date.', $this->account(), 'subscribed, and still ticked');
    }

    /**
     * A save acts on the digest only when the person moved its tick, and only while the subscription is as the page
     * drew it: a page opened before an unsubscribe or a confirmation elsewhere never undoes it, and a page that could
     * not read the subscription never changes it, nor fails a save of the other details.
     */
    public function testASaveActsOnlyOnATickThePersonMovedOnAPageThatIsStillTrue(): void
    {
        $id = $this->makeUser();
        $this->signIn();
        $signUp = $this->subscribers()->request('client@example.test', '2026-10-23', '2026-10-23', time());
        $selector = (string) $signUp['subscriber']['selector'];
        self::assertSame('confirmed', $this->subscribers()->confirm($selector, 0, time()));

        // Drawn ticked; the digest's one-click unsubscribe in another tab; the old page saved with a new company.
        $stale = $this->shown();
        self::assertSame('1', $stale);
        self::assertSame('done', $this->subscribers()->unsubscribe($selector, 'one_click', time()));
        $this->save(['company' => 'Front of House Ltd', 'digest' => '1', 'digest_shown' => $stale]);
        $row = $this->db->one('SELECT * FROM news_digest_subscribers WHERE selector = ?', [$selector]);
        self::assertSame(['unsubscribed', 'one_click', null], [$row['state'], $row['unsubscribe_method'], $row['email']], 'the withdrawal stands');
        self::assertSame('Front of House Ltd', $this->db->value('SELECT company FROM users WHERE id = ?', [$id]));
        self::assertStringContainsString('Your details are saved.', $this->account());

        // Moved on that old page (ticked off, though it is off already): not saved, and the page says why.
        $this->save(['digest_shown' => $stale]);
        self::assertSame(['unsubscribed', 'one_click'], [$this->db->value('SELECT state FROM news_digest_subscribers WHERE selector = ?', [$selector]), $this->db->value('SELECT unsubscribe_method FROM news_digest_subscribers WHERE selector = ?', [$selector])]);
        self::assertStringContainsString(e(self::words()['stale']), $this->account());

        // Drawn unticked while a request waits; its button pressed in another tab; the old page saved unticked.
        $again = $this->subscribers()->request('client@example.test', '2026-10-23', '2026-10-23', time());
        $waiting = $this->shown();
        self::assertSame('0', $waiting);
        self::assertSame('confirmed', $this->subscribers()->confirm($selector, (int) $again['subscriber']['generation'], time()));
        $this->save(['company' => 'Cue Lights Ltd', 'digest_shown' => $waiting]);
        self::assertSame('confirmed', $this->db->value('SELECT state FROM news_digest_subscribers WHERE selector = ?', [$selector]), 'the confirmation stands');
        self::assertSame('Cue Lights Ltd', $this->db->value('SELECT company FROM users WHERE id = ?', [$id]));

        // The subscription cannot be read: the page still answers, drawing no state; a save of the name still works,
        // and a tick moved on it, or on a page drawn before, is not saved and the page says so.
        $drawn = $this->shown();
        $this->db->exec('ALTER TABLE news_digest_subscribers RENAME TO news_digest_subscribers_away');
        try {
            self::assertSame('', $this->shown(), 'no state drawn');
            $this->save(['name' => 'Casey Cue', 'digest' => '1']);
            self::assertSame('Casey Cue', $this->db->value('SELECT name FROM users WHERE id = ?', [$id]));
            $this->save(['digest' => '1', 'digest_shown' => '']);
            self::assertStringContainsString(e(self::words()['unavailable']), $this->account());
            $this->save(['digest_shown' => $drawn]);
            self::assertStringContainsString(e(self::words()['unavailable']), $this->account());
        } finally {
            $this->db->exec('ALTER TABLE news_digest_subscribers_away RENAME TO news_digest_subscribers');
        }
        self::assertSame('confirmed', $this->db->value('SELECT state FROM news_digest_subscribers WHERE selector = ?', [$selector]), 'nothing changed');
    }

    public function testARefusedSaveKeepsTheTickAsPostedAndChangesNothing(): void
    {
        $this->makeUser();
        $this->signIn();
        $r = $this->post('/portal/account/profile/', ['name' => '   ', 'company' => '', 'digest' => '1']);
        self::assertSame('/portal/account/#profile-errors', $r->getHeader('Location'));
        self::assertNull($this->row('client@example.test'), 'nothing subscribed');
        self::assertStringContainsString('id="profile-digest" name="digest" type="checkbox" value="1" checked', $this->account(), 'as posted');
    }

    /**
     * A save refused for a field error draws the form again with the tick as posted and against the state the page
     * first drew it against, so the corrected save still compares the tick with the page the person saw: an
     * unsubscribe or a confirmation made elsewhere in between is never undone by it.
     */
    public function testARefusedSaveKeepsTheStateTheTickWasFirstDrawnAgainst(): void
    {
        $id = $this->makeUser();
        $this->signIn();
        $signUp = $this->subscribers()->request('client@example.test', '2026-10-23', '2026-10-23', time());
        $selector = (string) $signUp['subscriber']['selector'];
        self::assertSame('confirmed', $this->subscribers()->confirm($selector, 0, time()));
        $record = function () use ($selector): array {
            $row = $this->db->one('SELECT * FROM news_digest_subscribers WHERE selector = ?', [$selector]);
            return [$row['state'], $row['unsubscribe_method'], $row['email'], (int) $row['generation']];
        };

        // Drawn ticked; the digest's one-click unsubscribe in another tab; the old page sent with the name cleared.
        $old = $this->drawnTick();
        self::assertSame([true, '1'], $old);
        self::assertSame('done', $this->subscribers()->unsubscribe($selector, 'one_click', time()));
        $withdrawn = $record();
        self::assertSame(['unsubscribed', 'one_click', null, 0], $withdrawn);
        $r = $this->post('/portal/account/profile/', ['name' => ' ', 'company' => '', 'digest' => '1', 'digest_shown' => $old[1]]);
        self::assertSame('/portal/account/#profile-errors', $r->getHeader('Location'));
        $redrawn = $this->drawnTick();
        self::assertSame([true, '1'], $redrawn, 'drawn again as posted, against the state first drawn');

        // The name put right and the tick left alone: the withdrawal stands, and nothing asks for the digest.
        $this->save(['name' => 'Casey Cue', 'digest' => '1', 'digest_shown' => $redrawn[1]]);
        self::assertSame('Casey Cue', $this->db->value('SELECT name FROM users WHERE id = ?', [$id]));
        self::assertSame($withdrawn, $record(), 'the withdrawal stands');
        self::assertSame([], $this->digestConfirmations('client@example.test'), 'no confirmation sent');
        $page = $this->account();
        self::assertStringContainsString('Your details are saved.', $page);
        self::assertStringNotContainsString(e(strtr(self::words()['asked'], [':email' => 'client@example.test'])), $page);

        // The same old page with the tick moved off and the company too long: once the company is put right, the
        // tick is refused, because the subscription changed after the page was opened.
        $r = $this->post('/portal/account/profile/', ['name' => 'Casey Cue', 'company' => str_repeat('x', 170), 'digest_shown' => $old[1]]);
        self::assertSame('/portal/account/#profile-errors', $r->getHeader('Location'));
        $redrawn = $this->drawnTick();
        self::assertSame([false, '1'], $redrawn);
        $this->save(['name' => 'Casey Cue', 'company' => 'Front of House Ltd', 'digest_shown' => $redrawn[1]]);
        self::assertSame('Front of House Ltd', $this->db->value('SELECT company FROM users WHERE id = ?', [$id]));
        self::assertSame($withdrawn, $record());
        self::assertStringContainsString(e(self::words()['stale']), $this->account());

        // Drawn unticked while a request waits; its button pressed in another tab; the old page sent with the company
        // too long, then put right with the tick left alone: the confirmation stands.
        $again = $this->subscribers()->request('client@example.test', '2026-10-23', '2026-10-23', time());
        $old = $this->drawnTick();
        self::assertSame([false, '0'], $old);
        self::assertSame('confirmed', $this->subscribers()->confirm($selector, (int) $again['subscriber']['generation'], time()));
        $confirmed = $record();
        self::assertSame(['confirmed', null, 'client@example.test', 1], $confirmed);
        $r = $this->post('/portal/account/profile/', ['name' => 'Casey Cue', 'company' => str_repeat('x', 170), 'digest_shown' => $old[1]]);
        self::assertSame('/portal/account/#profile-errors', $r->getHeader('Location'));
        $redrawn = $this->drawnTick();
        self::assertSame([false, '0'], $redrawn, 'drawn again as posted, against the state first drawn');
        $this->save(['name' => 'Casey Cue', 'company' => 'Cue Lights Ltd', 'digest_shown' => $redrawn[1]]);
        self::assertSame('Cue Lights Ltd', $this->db->value('SELECT company FROM users WHERE id = ?', [$id]));
        self::assertSame($confirmed, $record(), 'the confirmation stands');
        $page = $this->account();
        self::assertStringContainsString('Your details are saved.', $page);
        self::assertStringNotContainsString(e(self::words()['off']), $page);
    }

    // ===== Registration =====

    /** The digest's own confirmation emails sent to an address. */
    private function digestConfirmations(string $email): array
    {
        return array_values(array_filter($this->mails(), static fn (array $m): bool => $m['template'] === 'digest-confirm' && $m['to'] === $email));
    }

    /** A token page's address from an emailed link, opened as a mail client or scanner does: no cookie, no session. */
    private function bare(string $method, string $url): Response
    {
        $this->jar = [];
        $_SESSION = [];
        return $this->request($method, (string) parse_url($url, PHP_URL_PATH) . '?' . (string) parse_url($url, PHP_URL_QUERY));
    }

    public function testRegistrationsTickWaitsForTheAddressAndThenForItsOwnButton(): void
    {
        $form = $this->request('GET', '/portal/register/')->body;
        self::assertStringContainsString('<input id="register-digest" name="digest" type="checkbox" value="1" aria-describedby="register-digest-help">', $form);
        self::assertStringContainsString('<p class="pt-help" id="register-digest-help">' . e(self::words()['register_help']) . '</p>', $form);
        self::assertLessThan(strpos($form, 'id="register-digest"'), strpos($form, 'id="register-marketing"'), 'after the product-news tick');

        $this->register('nina@example.test', ['digest' => '1']);
        $id = (int) $this->db->value('SELECT id FROM users WHERE email = ?', ['nina@example.test']);
        self::assertSame(0, (int) $this->db->value('SELECT marketing_opt_in FROM users WHERE id = ?', [$id]), 'the digest is not product news');
        $row = $this->row('nina@example.test');
        self::assertNotNull($row);
        self::assertSame('pending', $row['state'], 'nothing is sent until the address is proven');
        self::assertSame('registration', $row['source']);
        self::assertSame($id, (int) $row['user_id']);
        self::assertNull($row['confirmed_at']);
        self::assertSame([], $this->digestConfirmations('nina@example.test'), 'the email check is the only email');
        $check = $this->lastMailTo('nina@example.test');
        self::assertSame(['verify-email', true], [$check['template'], $check['data']['digest']]);
        self::assertStringContainsString('You also asked for the weekly Industry Updates digest. Once your address is confirmed we will send one more email about it, and the digest starts only when you press the button that email leads to.', $check['html'], 'the email check says what follows');

        // A link scanner opens the email check's link: nothing changes, and no digest email goes.
        $url = (string) $check['data']['url'];
        foreach (['GET', 'HEAD'] as $method) {
            self::assertSame(200, $this->bare($method, $url)->status);
        }
        self::assertSame('pending', $this->db->value('SELECT status FROM users WHERE id = ?', [$id]));
        self::assertSame('pending', $this->row('nina@example.test')['state']);
        self::assertSame([], $this->digestConfirmations('nina@example.test'));

        // The person presses the email check's button: the address is proven, and the digest's own confirmation goes.
        $this->confirmEmailLink('/portal/verify/?token=' . rawurlencode(self::tokenFrom($url)));
        self::assertSame('active', $this->db->value('SELECT status FROM users WHERE id = ?', [$id]));
        $after = $this->row('nina@example.test');
        self::assertSame(['pending', 'registration', null], [$after['state'], $after['source'], $after['confirmed_at']], 'proving the address for an account is not consent to the digest');
        $sent = $this->digestConfirmations('nina@example.test');
        self::assertCount(1, $sent);

        // Only that email's button starts it: its GET changes nothing, its POST confirms.
        $ask = $this->bare('GET', (string) $sent[0]['data']['url']);
        self::assertStringContainsString('>Start my digest</button>', $ask->body);
        self::assertSame('pending', $this->row('nina@example.test')['state']);
        $done = $this->bare('POST', (string) $sent[0]['data']['url']);
        self::assertStringContainsString('You are subscribed', $done->body);
        $confirmed = $this->row('nina@example.test');
        self::assertSame(['confirmed', 'registration', $id], [$confirmed['state'], $confirmed['source'], (int) $confirmed['user_id']]);
        self::assertNotNull($confirmed['confirmed_at']);
    }

    public function testAResetThatProvesAPendingAccountsAddressSendsTheDigestsOwnConfirmation(): void
    {
        $this->register('reset@example.test', ['digest' => '1']);
        self::assertSame('pending', $this->row('reset@example.test')['state']);
        $_SESSION = [];
        $this->jar = [];
        $this->post('/portal/forgot/', ['email' => 'reset@example.test'] + $this->filledIn('forgot'));
        $mail = $this->lastMailTo('reset@example.test');
        self::assertSame(['reset-password', true], [$mail['template'], $mail['data']['digest']]);
        self::assertStringContainsString('You also asked for the weekly Industry Updates digest when you created the account.', $mail['html']);
        $token = self::tokenFrom((string) $mail['data']['url']);
        $this->post('/portal/reset/', ['token' => $token, 'purpose' => 'reset', 'password' => 'lantern quay rehearsal', 'password_confirm' => 'lantern quay rehearsal']);
        self::assertNotNull($this->db->value('SELECT email_verified_at FROM users WHERE email = ?', ['reset@example.test']));
        self::assertSame('pending', $this->row('reset@example.test')['state'], 'only the digest\'s own button starts it');
        $sent = $this->digestConfirmations('reset@example.test');
        self::assertCount(1, $sent);
        $this->bare('POST', (string) $sent[0]['data']['url']);
        self::assertSame('confirmed', $this->row('reset@example.test')['state']);

        // An active account's reset says nothing of the digest.
        $this->makeUser('active@example.test');
        $this->post('/portal/forgot/', ['email' => 'active@example.test'] + $this->filledIn('forgot'));
        $mail = $this->lastMailTo('active@example.test');
        self::assertFalse($mail['data']['digest']);
        self::assertStringNotContainsString('digest', $mail['html']);
    }

    /**
     * An email check (or a pending account's reset) that says a digest confirmation follows keeps the request alive
     * for 7 days from that email, so the promise holds whenever its link's button is pressed within the link's life.
     */
    public function testALateEmailCheckKeepsTheDigestRequestAsLongAsItsLink(): void
    {
        $this->register('late@example.test', ['digest' => '1']);
        $id = (int) $this->db->value('SELECT id FROM users WHERE email = ?', ['late@example.test']);
        $this->db->exec('UPDATE news_digest_subscribers SET requested_at = requested_at - ? WHERE user_id = ?', [6 * 86400, $id]);

        // On day 6 the person asks for the email check again: it promises the digest's confirmation, and the request
        // is asked again with it.
        $_SESSION = [];
        $this->jar = [];
        $this->post('/portal/verify/resend/', ['email' => 'late@example.test'] + $this->filledIn('resend'));
        $check = $this->lastMailTo('late@example.test');
        self::assertSame(['verify-email', true], [$check['template'], $check['data']['digest']]);
        self::assertGreaterThanOrEqual(time() - 60, (int) $this->row('late@example.test')['requested_at']);

        // A day and a half later (inside the link's 48 hours, past the first ask's 7 days) its button is pressed.
        $this->db->exec('UPDATE news_digest_subscribers SET requested_at = requested_at - ? WHERE user_id = ?', [36 * 3600, $id]);
        $this->confirmEmailLink('/portal/verify/?token=' . rawurlencode(self::tokenFrom((string) $check['data']['url'])));
        self::assertSame('active', $this->db->value('SELECT status FROM users WHERE id = ?', [$id]));
        self::assertCount(1, $this->digestConfirmations('late@example.test'), 'the promised confirmation follows');
        self::assertSame('pending', $this->row('late@example.test')['state']);

        // A pending account's reset email makes the same promise, and keeps it the same way.
        $this->register('reset-late@example.test', ['digest' => '1']);
        $this->db->exec('UPDATE news_digest_subscribers SET requested_at = requested_at - ? WHERE email = ?', [6 * 86400, 'reset-late@example.test']);
        $_SESSION = [];
        $this->jar = [];
        $this->post('/portal/forgot/', ['email' => 'reset-late@example.test'] + $this->filledIn('forgot'));
        $mail = $this->lastMailTo('reset-late@example.test');
        self::assertSame(['reset-password', true], [$mail['template'], $mail['data']['digest']]);
        self::assertGreaterThanOrEqual(time() - 60, (int) $this->row('reset-late@example.test')['requested_at']);
    }

    public function testRegistrationWithoutTheTickOrForATakenAddressLeavesNoSubscription(): void
    {
        $this->register('untick@example.test');
        self::assertNull($this->row('untick@example.test'));
        $check = $this->lastMailTo('untick@example.test');
        self::assertSame(['verify-email', false], [$check['template'], $check['data']['digest']]);
        self::assertStringNotContainsString('digest', $check['html'], 'without the tick the email check says nothing of it');

        $this->makeUser('taken@example.test');
        $this->register('taken@example.test', ['digest' => '1']);
        self::assertNull($this->row('taken@example.test'), 'an address that has an account takes the existing path and nothing else');
        self::assertSame('account-exists', $this->lastMailTo('taken@example.test')['template']);
    }

    // ===== The copy of the data and deleting the account =====

    public function testTheCopyOfTheDataCarriesTheSubscriptionButNeverItsSelectorOrHash(): void
    {
        $this->makeUser();
        $this->signIn();
        $none = json_decode($this->request('GET', '/portal/account/export/')->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('news_digest', $none);
        self::assertNull($none['news_digest'], 'null without one');

        $this->save(['digest' => '1']);
        $this->pressConfirm($this->digestConfirmations('client@example.test')[0]);
        $row = $this->row('client@example.test');
        $data = json_decode($this->request('GET', '/portal/account/export/')->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('news_digest', array_key_last($data), 'the last key');
        $digest = $data['news_digest'];
        self::assertSame('client@example.test', $digest['email']);
        self::assertSame('confirmed', $digest['state']);
        self::assertSame('account', $digest['source']);
        self::assertSame('2026-10-23', $digest['consent_version']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $digest['confirmed_at'], 'ISO 8601');
        self::assertSame([], $digest['digests']);
        self::assertNull($digest['prior']);
        foreach (['id', 'selector', 'email_hash', 'user_id'] as $key) {
            self::assertArrayNotHasKey($key, $digest);
        }
        $json = $this->request('GET', '/portal/account/export/')->body;
        self::assertStringNotContainsString((string) $row['selector'], $json);
        self::assertStringNotContainsString((string) $row['email_hash'], $json);
    }

    public function testDeletingTheAccountEndsTheSubscriptionAndKeepsOnlyTheRecord(): void
    {
        $id = $this->makeUser();
        $this->makeUser('admin@example.test', self::PASSWORD, 'admin');
        $this->signIn();
        $this->save(['digest' => '1']);
        $this->pressConfirm($this->digestConfirmations('client@example.test')[0]);
        $row = $this->row('client@example.test');
        self::assertStringContainsString('The weekly Industry Updates digest stops, and your address is deleted from it.', $this->account());

        $r = $this->post('/portal/account/delete/', ['delete_password' => self::PASSWORD, 'delete_confirm' => 'DELETE']);
        self::assertSame('/portal/login/', $r->getHeader('Location'));
        $after = $this->db->one('SELECT * FROM news_digest_subscribers WHERE id = ?', [(int) $row['id']]);
        self::assertNotNull($after, 'the suppression record is kept');
        self::assertSame('unsubscribed', $after['state']);
        self::assertSame('account', $after['unsubscribe_method']);
        self::assertNull($after['email'], 'the address deleted');
        self::assertNull($after['user_id']);
        self::assertSame($row['email_hash'], $after['email_hash']);
        self::assertSame($row['confirmed_at'], $after['confirmed_at'], 'the proof of consent kept');

        $audit = json_decode((string) $this->db->value("SELECT meta FROM audit_log WHERE action = 'account.deleted' AND user_id = ?", [$id]), true);
        self::assertSame('unsubscribed', $audit['removed']['news_digest'] ?? null, 'the audit says so');
        $mail = $this->lastMailTo('owner@example.test') ?? $this->lastMailTo('admin@example.test');
        self::assertNotNull($mail);
        self::assertSame('account-deleted-admin', $mail['template']);
        self::assertSame('unsubscribed', $mail['data']['removed']['news_digest'] ?? null, 'the administrators\' email is told');
    }

    public function testDeletingAnAccountWithAPendingTickRemovesTheRequest(): void
    {
        $id = $this->makeUser();
        $this->makeUser('admin@example.test', self::PASSWORD, 'admin');
        // A tick at registration that the email check never confirmed (the account was verified another way).
        $this->db->insert('news_digest_subscribers', [
            'email' => 'client@example.test', 'email_hash' => Tokens::fromApp($this->app)->emailHash('client@example.test'), 'user_id' => $id,
            'state' => 'pending', 'source' => 'registration', 'selector' => Tokens::selector(), 'consent_version' => '2026-10-23', 'privacy_version' => '2026-10-23',
            'requested_at' => time() - 3600, 'created_at' => time() - 3600, 'updated_at' => time() - 3600,
        ]);
        $this->signIn();
        $this->post('/portal/account/delete/', ['delete_password' => self::PASSWORD, 'delete_confirm' => 'DELETE']);
        self::assertNull($this->row('client@example.test'), 'a request never confirmed goes');
        $audit = json_decode((string) $this->db->value("SELECT meta FROM audit_log WHERE action = 'account.deleted' AND user_id = ?", [$id]), true);
        self::assertSame('removed', $audit['removed']['news_digest'] ?? null);

        // Without any subscription, none.
        $other = $this->makeUser('plain@example.test');
        $_SESSION = [];
        $this->jar = [];
        $this->signIn('plain@example.test');
        $this->post('/portal/account/delete/', ['delete_password' => self::PASSWORD, 'delete_confirm' => 'DELETE']);
        $audit = json_decode((string) $this->db->value("SELECT meta FROM audit_log WHERE action = 'account.deleted' AND user_id = ?", [$other]), true);
        self::assertSame('none', $audit['removed']['news_digest'] ?? null);
    }
}
