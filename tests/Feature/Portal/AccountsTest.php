<?php

declare(strict_types=1);

namespace Psc\Tests\Feature\Portal;

use Psc\Controllers\Controller;
use Psc\Http\Response;
use Psc\Tests\TestCase;

/**
 * Accounts: registering, confirming the address, signing in and out, resetting and setting passwords, and the
 * account page (details, password, data copy, deletion). Each test asserts the status, the redirect, the stored
 * rows and the emails, not only the words on the page.
 */
final class AccountsTest extends TestCase
{
    private const PASSWORD = 'correct horse battery';
    private const NEW_PASSWORD = 'lantern quay rehearsal';

    // ----------------------------------------------------------------- helpers

    /** @return list<string> the flash messages waiting in the session */
    private static function flashes(): array
    {
        return array_map(static fn (array $f): string => (string) $f['message'], $_SESSION['_flash'] ?? []);
    }

    private static function signedInId(): ?int
    {
        return isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
    }

    /** The token in an emailed link. */
    private static function tokenFrom(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        self::assertIsString($q['token'] ?? null, 'the link carries a token');
        return (string) $q['token'];
    }

    private function mailUrl(string $to, string $template): string
    {
        $mail = $this->lastMailTo($to);
        self::assertNotNull($mail, "an email went to {$to}");
        self::assertSame($template, $mail['template']);
        return (string) $mail['data']['url'];
    }

    /** A new browser: no session, no cookies. */
    private function freshBrowser(): void
    {
        $_SESSION = [];
        $this->jar = [];
    }

    private function hashOf(int $id): string
    {
        return (string) $this->db->value('SELECT password_hash FROM users WHERE id = ?', [$id]);
    }

    private function register(string $email, array $extra = []): Response
    {
        return $this->post('/portal/register/', $extra + [
            'name' => 'Nina Stage',
            'email' => $email,
            'company' => 'Cue Lights Ltd',
            'password' => self::PASSWORD,
            'terms' => '1',
        ] + $this->filledIn('register'));
    }

    private static function assertRedirect(Response $r, string $location, int $status = 303): void
    {
        self::assertSame($status, $r->status, 'status');
        self::assertSame($location, $r->getHeader('Location'));
    }

    // ------------------------------------------------------------ registering

    public function testRegisterSendsAConfirmationLinkThenVerifyThenSignInWorks(): void
    {
        $form = $this->request('GET', '/portal/register/');
        self::assertSame(200, $form->status);
        self::assertStringContainsString('action="/portal/register/"', $form->body);
        self::assertStringContainsString('name="_csrf"', $form->body);
        self::assertStringContainsString('href="/legal/terms/"', $form->body);
        self::assertStringContainsString('href="/legal/privacy/"', $form->body);
        self::assertMatchesRegularExpression('/<input id="register-marketing"[^>]*>/', $form->body);
        preg_match('/<input id="register-marketing"[^>]*>/', $form->body, $m);
        self::assertStringNotContainsString('checked', $m[0], 'marketing opt-in is unticked by default');

        $r = $this->register('  Nina@Example.TEST ');
        self::assertRedirect($r, '/portal/register/');
        $check = $this->request('GET', '/portal/register/');
        self::assertSame(200, $check->status);
        self::assertStringContainsString('Check your email', $check->body);
        self::assertStringContainsString('nina@example.test', $check->body);
        // Opening the link confirms nothing: the page names the button on the page it opens, which does.
        self::assertStringContainsString('Open the link in that email and press Confirm my email address on the page it opens', $check->body);
        self::assertStringNotContainsString('action="/portal/register/"', $check->body);
        self::assertStringContainsString('action="/portal/register/"', $this->request('GET', '/portal/register/')->body, 'shown once: reloading never sends the form again');

        $user = $this->db->one('SELECT * FROM users WHERE email = ?', ['nina@example.test']);
        self::assertNotNull($user);
        self::assertSame('pending', $user['status']);
        self::assertSame('client', $user['role']);
        self::assertSame('Cue Lights Ltd', $user['company']);
        self::assertSame(0, (int) $user['marketing_opt_in']);
        self::assertNull($user['email_verified_at']);
        self::assertNull(self::signedInId(), 'registering does not sign anyone in');

        $url = $this->mailUrl('nina@example.test', 'verify-email');
        self::assertStringStartsWith('https://patternsshowcontrol.test/portal/verify/?token=', $url);

        // Opening the link (as a mail scanner does, with no cookie) shows the button and changes nothing; so does a
        // POST without the page's CSRF token.
        $path = '/portal/verify/?token=' . rawurlencode(self::tokenFrom($url));
        $_SESSION = [];
        $this->jar = [];
        foreach (['GET', 'HEAD'] as $method) {
            $opened = $this->request($method, $path);
            self::assertSame(200, $opened->status, $method);
            self::assertSame('pending', $this->db->value('SELECT status FROM users WHERE id = ?', [(int) $user['id']]), $method . ' changes nothing');
        }
        self::assertStringContainsString('>Confirm my email address</button>', $this->request('GET', $path)->body, 'the button the page after signing up names');
        self::assertSame(419, $this->post($path, [], false)->status);
        self::assertSame('pending', $this->db->value('SELECT status FROM users WHERE id = ?', [(int) $user['id']]), 'only the button confirms');

        $verify = $this->confirmEmailLink($path);
        self::assertSame(200, $verify->status);
        self::assertStringContainsString('Email address confirmed', $verify->body);
        self::assertStringContainsString('href="/portal/login/"', $verify->body);
        self::assertNull(self::signedInId(), 'confirming does not sign anyone in');
        $user = $this->db->one('SELECT status, email_verified_at FROM users WHERE id = ?', [(int) $user['id']]);
        self::assertSame('active', $user['status']);
        self::assertNotNull($user['email_verified_at']);

        $again = $this->request('GET', $path);
        self::assertStringContainsString('This link no longer works', $again->body, 'a confirmation link works once');
        self::assertStringContainsString('This link no longer works', $this->post($path)->body, 'and its button too');

        $login = $this->signIn('nina@example.test', self::PASSWORD);
        self::assertRedirect($login, '/portal/');
        self::assertNotNull(self::signedInId());
        $account = $this->request('GET', '/portal/account/');
        self::assertSame(200, $account->status);
        self::assertStringContainsString('Nina Stage', $account->body);
    }

    public function testRegistrationNeedsTheTermsAndValidInput(): void
    {
        $r = $this->register('nina@example.test', ['terms' => '']);
        self::assertRedirect($r, '/portal/register/');
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM users'));
        self::assertSame([], $this->mails());

        $form = $this->request('GET', '/portal/register/');
        self::assertStringContainsString('Please accept the terms of use', $form->body);
        self::assertMatchesRegularExpression('/id="register-terms"[^>]*aria-invalid="true"/', $form->body);
        self::assertStringContainsString('value="nina@example.test"', $form->body, 'the form keeps what was typed');
        self::assertStringNotContainsString(self::PASSWORD, $form->body, 'never the password');

        $r = $this->register('not-an-email', ['password' => 'short']);
        self::assertRedirect($r, '/portal/register/');
        $form = $this->request('GET', '/portal/register/');
        self::assertStringContainsString('Enter a valid email address.', $form->body);
        self::assertStringContainsString('Use at least 10 characters.', $form->body);
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM users'));
    }

    public function testRegisteringATakenEmailSendsANoticeAndShowsTheSamePage(): void
    {
        $existing = $this->makeUser('taken@example.test');
        $before = $this->hashOf($existing);

        $fresh = $this->register('fresh@example.test');
        $freshPage = $this->request('GET', '/portal/register/');
        $taken = $this->register('taken@example.test');
        $takenPage = $this->request('GET', '/portal/register/');
        self::assertRedirect($fresh, '/portal/register/');
        self::assertRedirect($taken, '/portal/register/');
        self::assertSame(200, $takenPage->status);
        self::assertSame(
            str_replace('fresh@example.test', 'EMAIL', self::withoutFormTimes($freshPage->body)),
            str_replace('taken@example.test', 'EMAIL', self::withoutFormTimes($takenPage->body)),
            'the page never tells which addresses have accounts'
        );

        self::assertSame('account-exists', $this->lastMailTo('taken@example.test')['template'] ?? null);
        self::assertSame('verify-email', $this->lastMailTo('fresh@example.test')['template'] ?? null);
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM users WHERE email = ?', ['taken@example.test']));
        self::assertSame($before, $this->hashOf($existing), 'the existing account is untouched');
    }

    public function testInviteModeRefusesRegistration(): void
    {
        $this->bootApp(['security' => ['registration' => 'invite']]);
        $form = $this->request('GET', '/portal/register/');
        self::assertSame(200, $form->status);
        self::assertStringContainsString('by invitation', $form->body);
        self::assertStringContainsString('href="/contact/', $form->body);
        self::assertStringNotContainsString('action="/portal/register/"', $form->body);

        $r = $this->register('nina@example.test');
        self::assertRedirect($r, '/portal/register/');
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM users'));
        self::assertSame([], $this->mails());
    }

    /**
     * The portal's Sign in / Create an account switch is the one route between the two forms, so neither card
     * repeats it; when accounts are by invitation the sign-in card says how to get one and offers no sign-up.
     */
    public function testTheAuthCardsDoNotRepeatTheSwitch(): void
    {
        $login = $this->request('GET', '/portal/login/')->body;
        self::assertStringContainsString('<a href="/portal/register/"', $login, 'the switch offers sign-up');
        self::assertStringNotContainsString('New here?', $login);
        $register = $this->request('GET', '/portal/register/')->body;
        self::assertStringContainsString('<a href="/portal/login/"', $register, 'the switch offers sign-in');
        self::assertStringNotContainsString('Already have an account?', $register);

        $this->bootApp(['security' => ['registration' => 'invite']]);
        $invite = $this->request('GET', '/portal/login/')->body;
        self::assertStringContainsString('New here? Accounts are by invitation. <a href="/contact/">Contact us</a> if you need one.', $invite);
        self::assertStringNotContainsString('New here? <a href="/portal/register/">', $invite);
        self::assertStringNotContainsString('<a href="/portal/register/">Create an account</a>', $invite, 'the switch offers no sign-up either');
    }

    /** Every portal page carries the same description, of a length search engines and link previews show whole. */
    public function testPortalPagesHaveADescriptionOfAUsableLength(): void
    {
        $length = mb_strlen(Controller::PORTAL_DESCRIPTION);
        self::assertGreaterThanOrEqual(50, $length);
        self::assertLessThanOrEqual(160, $length);
        foreach (['/portal/login/', '/portal/register/', '/portal/forgot/'] as $path) {
            self::assertStringContainsString('<meta name="description" content="' . Controller::PORTAL_DESCRIPTION . '">', $this->request('GET', $path)->body, $path);
        }
    }

    public function testApprovalModeKeepsTheAccountWaitingAndEmailsTheAdministrator(): void
    {
        $this->bootApp(['security' => ['registration' => 'approval']]);
        self::assertStringContainsString('checked by a person', $this->request('GET', '/portal/register/')->body);

        $this->register('nina@example.test');
        $url = $this->mailUrl('nina@example.test', 'verify-email');
        $verify = $this->confirmEmailLink('/portal/verify/?token=' . rawurlencode(self::tokenFrom($url)));
        self::assertSame(200, $verify->status);
        self::assertStringContainsString('waiting for approval', $verify->body);
        self::assertSame('approval', $this->db->value('SELECT status FROM users WHERE email = ?', ['nina@example.test']));

        $admin = $this->lastMailTo('owner@example.test');
        self::assertNotNull($admin);
        self::assertSame('admin-approval', $admin['template']);
        self::assertStringContainsString('nina@example.test', $admin['html']);
        self::assertStringContainsString('/portal/admin/users/', $admin['html']);

        $login = $this->signIn('nina@example.test', self::PASSWORD);
        self::assertRedirect($login, '/portal/login/');
        self::assertNull(self::signedInId());
        self::assertStringContainsString('Your account is waiting for approval.', $this->request('GET', '/portal/login/')->body);
    }

    public function testApprovalCannotBeSkippedThroughAPasswordReset(): void
    {
        $this->bootApp(['security' => ['registration' => 'approval']]);
        $this->register('nina@example.test');
        $this->post('/portal/forgot/', ['email' => 'nina@example.test'] + $this->filledIn('forgot'));
        $token = self::tokenFrom($this->mailUrl('nina@example.test', 'reset-password'));

        $r = $this->post('/portal/reset/', ['token' => $token, 'purpose' => 'reset', 'password' => self::NEW_PASSWORD, 'password_confirm' => self::NEW_PASSWORD]);
        self::assertRedirect($r, '/portal/login/');
        $row = $this->db->one('SELECT status, email_verified_at FROM users WHERE email = ?', ['nina@example.test']);
        self::assertSame('approval', $row['status'], 'the link proves the address, not an administrator\'s approval');
        self::assertNotNull($row['email_verified_at']);
        self::assertSame('admin-approval', $this->lastMailTo('owner@example.test')['template'] ?? null);

        // An administrator's invitation needs no further approval, even through the reset link.
        $invited = $this->app->auth()->invite('Ivy Wing', 'ivy@example.test', null);
        self::assertTrue($invited['ok']);
        $this->post('/portal/forgot/', ['email' => 'ivy@example.test'] + $this->filledIn('forgot'));
        $token = self::tokenFrom($this->mailUrl('ivy@example.test', 'reset-password'));
        $this->post('/portal/reset/', ['token' => $token, 'purpose' => 'reset', 'password' => self::NEW_PASSWORD, 'password_confirm' => self::NEW_PASSWORD]);
        self::assertSame('active', $this->db->value('SELECT status FROM users WHERE email = ?', ['ivy@example.test']));
    }

    // -------------------------------------------------------------- signing in

    public function testAWrongPasswordAndAnUnknownEmailGetTheSameGenericAnswer(): void
    {
        $this->makeUser();
        $wrong = $this->signIn('client@example.test', 'not the password');
        self::assertRedirect($wrong, '/portal/login/');
        $wrongFlash = self::flashes();
        $page = $this->request('GET', '/portal/login/');
        self::assertStringContainsString('That email address and password do not match an account.', $page->body);
        self::assertStringContainsString('value="client@example.test"', $page->body, 'the email is kept');

        $unknown = $this->signIn('nobody@example.test', 'not the password');
        self::assertRedirect($unknown, '/portal/login/');
        self::assertSame($wrongFlash, self::flashes(), 'one message whether or not the account exists');
        self::assertNull(self::signedInId());
        self::assertSame(2, (int) $this->db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'login.failed'"));
    }

    public function testSignInIsThrottledAfterTheConfiguredAttempts(): void
    {
        $this->bootApp(['security' => ['login_max_attempts' => 3]]);
        $this->makeUser();
        for ($i = 0; $i < 3; $i++) {
            $this->signIn('client@example.test', 'wrong guess ' . $i);
        }
        $_SESSION['_flash'] = [];
        $r = $this->signIn('client@example.test', self::PASSWORD);
        self::assertRedirect($r, '/portal/login/');
        self::assertNull(self::signedInId(), 'even the right password waits out the window');
        self::assertStringContainsString('Too many sign-in attempts. Please wait 15 minutes', implode(' ', self::flashes()));
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'login.throttled'"));
    }

    /**
     * Five wrong passwords from any one address locked the account for everyone, and a password reset did not lift
     * it: a stranger could keep anyone, the administrator included, out indefinitely. A reset now lifts the hold.
     */
    public function testAPasswordResetLiftsTheSignInHold(): void
    {
        $id = $this->makeUser();
        for ($i = 0; $i < 5; $i++) {
            $this->freshBrowser();
            $this->signIn('client@example.test', 'wrong guess ' . $i);
        }
        $this->freshBrowser();
        $this->signIn();
        self::assertNull(self::signedInId(), 'held after five failures');

        $this->post('/portal/forgot/', ['email' => 'client@example.test'] + $this->filledIn('forgot'));
        $token = self::tokenFrom($this->mailUrl('client@example.test', 'reset-password'));
        $ok = $this->post('/portal/reset/', ['token' => $token, 'purpose' => 'reset', 'password' => self::NEW_PASSWORD, 'password_confirm' => self::NEW_PASSWORD]);
        self::assertRedirect($ok, '/portal/login/');

        $this->freshBrowser();
        self::assertRedirect($this->signIn('client@example.test', self::NEW_PASSWORD), '/portal/');
        self::assertSame($id, self::signedInId(), 'the new password works at once');
    }

    /** Failures from one address hold the account at that address only, up to a far higher ceiling for all of them. */
    public function testAStrangersFailuresDoNotLockTheOwnerOut(): void
    {
        $this->bootApp(['app' => ['trusted_proxy_header' => 'HTTP_X_TEST_CLIENT', 'trusted_proxies' => ['203.0.113.7']]]);
        $id = $this->makeUser();
        $from = function (string $ip, string $password): Response {
            $this->freshBrowser();
            return $this->request('POST', '/portal/login/', ['email' => 'client@example.test', 'password' => $password, '_csrf' => $this->csrf()], [], null, true, [], ['HTTP_X_TEST_CLIENT' => $ip]);
        };
        for ($i = 0; $i < 5; $i++) {
            $from('198.51.100.9', 'wrong guess ' . $i);
        }
        $from('198.51.100.9', self::PASSWORD);
        self::assertNull(self::signedInId(), 'the guessing address waits out its window');

        self::assertRedirect($from('192.0.2.44', self::PASSWORD), '/portal/');
        self::assertSame($id, self::signedInId(), 'the owner, elsewhere, signs in');

        // Spread over many addresses, failures still meet a ceiling for the account (ten times the limit).
        for ($i = 0; $i < 50; $i++) {
            $from('198.51.' . intdiv($i, 4) . '.' . ($i % 4 + 1), 'wrong guess ' . $i);
        }
        $from('192.0.2.45', self::PASSWORD);
        self::assertNull(self::signedInId(), 'fifty failures across addresses hold the account everywhere');
    }

    public function testAnUnconfirmedAccountIsOfferedANewLink(): void
    {
        $id = $this->makeUser('pending@example.test', self::PASSWORD, 'client', 'pending');
        $r = $this->signIn('pending@example.test', self::PASSWORD);
        self::assertRedirect($r, '/portal/login/');
        self::assertNull(self::signedInId());

        $page = $this->request('GET', '/portal/login/');
        self::assertStringContainsString('Please confirm your email address first.', $page->body);
        self::assertStringContainsString('action="/portal/verify/resend/"', $page->body);
        self::assertStringContainsString('name="email" value="pending@example.test"', $page->body);

        $resend = $this->post('/portal/verify/resend/', ['email' => 'pending@example.test'] + $this->filledIn('resend'));
        self::assertRedirect($resend, '/portal/login/');
        $url = $this->mailUrl('pending@example.test', 'verify-email');
        $this->confirmEmailLink('/portal/verify/?token=' . rawurlencode(self::tokenFrom($url)));
        self::assertSame('active', $this->db->value('SELECT status FROM users WHERE id = ?', [$id]));
        self::assertRedirect($this->signIn('pending@example.test', self::PASSWORD), '/portal/');
    }

    public function testResendAnswersTheSameForEveryAddressAndIsRateLimited(): void
    {
        $this->makeUser('pending@example.test', self::PASSWORD, 'client', 'pending');
        $this->makeUser('active@example.test');
        $answers = [];
        foreach (['pending@example.test', 'active@example.test', 'nobody@example.test'] as $email) {
            $_SESSION['_flash'] = [];
            $r = $this->post('/portal/verify/resend/', ['email' => $email] + $this->filledIn('resend'));
            $answers[] = [$r->status, $r->getHeader('Location'), self::flashes()];
        }
        self::assertCount(1, array_unique(array_map('serialize', $answers)), 'the same answer for every address');
        self::assertSame([303, '/portal/login/', [\Psc\Controllers\Portal\AuthController::RESEND_ANSWER]], $answers[0]);
        self::assertCount(1, $this->mails(), 'only the unconfirmed account gets a link');

        // What is not an address at all is told how to write one (a fact about the typing, never about an account),
        // on the form's own page with the input kept.
        $_SESSION['_flash'] = [];
        $r = $this->post('/portal/verify/resend/', ['email' => 'not-an-email'] + $this->filledIn('resend'));
        self::assertRedirect($r, '/portal/verify/resend/');
        $page = $this->request('GET', '/portal/verify/resend/')->body;
        self::assertStringContainsString('<li><a href="#resend-email">' . Controller::EMAIL_FORMAT . '</a></li>', $page);
        self::assertMatchesRegularExpression('/id="resend-email"[^>]*value="not-an-email"[^>]*aria-invalid="true"/', $page);
        self::assertCount(1, $this->mails());

        for ($i = 0; $i < 4; $i++) {
            $this->post('/portal/verify/resend/', ['email' => 'pending@example.test'] + $this->filledIn('resend'));
        }
        $sent = array_filter($this->mails(), static fn (array $m): bool => $m['to'] === 'pending@example.test');
        self::assertCount(3, $sent, 'three links an hour for one address');
    }

    public function testSignInReturnsToTheGuardedPageWithItsQuery(): void
    {
        $this->makeUser();
        $page = '/portal/licences/request/?product=stagewatch&tier=pro';
        self::assertRedirect($this->request('GET', $page), '/portal/login/?next=' . rawurlencode($page), 302);

        $r = $this->post('/portal/login/', ['email' => 'client@example.test', 'password' => self::PASSWORD, 'next' => $page]);
        self::assertRedirect($r, $page);

        // Only scalar query values travel, and an oversized query falls back to the bare path.
        $this->freshBrowser();
        self::assertRedirect($this->request('GET', '/portal/tickets/new/?type=bug&x[]=1'), '/portal/login/?next=' . rawurlencode('/portal/tickets/new/?type=bug'), 302);
        self::assertRedirect($this->request('GET', '/portal/tickets/new/?type=' . str_repeat('a', 600)), '/portal/login/?next=' . rawurlencode('/portal/tickets/new/'), 302);
    }

    public function testNextIsKeptForSameSitePathsAndNeutralisedOtherwise(): void
    {
        $this->makeUser();
        $guarded = $this->request('GET', '/portal/account/');
        self::assertRedirect($guarded, '/portal/login/?next=' . rawurlencode('/portal/account/'), 302);

        $page = $this->request('GET', '/portal/login/?next=' . rawurlencode('//evil.example/steal'));
        self::assertSame(200, $page->status);
        self::assertStringNotContainsString('evil.example', $page->body);

        // Encoded forms too: the page a next= leads to decodes its own path, so /%5CEvil.example/ would become
        // /\evil.example/ one redirect later.
        $encoded = $this->request('GET', '/portal/login/?next=' . rawurlencode('/%5CEvil.example/'));
        self::assertSame(200, $encoded->status);
        self::assertStringNotContainsString('Evil.example', $encoded->body);

        foreach (['//evil.example', 'https://evil.example/', '/\\evil.example', "/portal/\r\nX: y", '/%5CEvil.example/', '/%5c%5cevil.example/', '/%2F%2Fevil.example/', '/%2fevil.example', '/%09/evil.example', '/%7F/evil.example'] as $next) {
            $this->freshBrowser();
            $r = $this->post('/portal/login/', ['email' => 'client@example.test', 'password' => self::PASSWORD, 'next' => $next]);
            self::assertRedirect($r, '/portal/');
        }

        $this->freshBrowser();
        $r = $this->post('/portal/login/', ['email' => 'client@example.test', 'password' => self::PASSWORD, 'next' => '/portal/account/']);
        self::assertRedirect($r, '/portal/account/');
    }

    public function testKeepMeSignedInSetsTheRememberCookieAndItWorks(): void
    {
        $id = $this->makeUser();
        $this->signIn();
        self::assertArrayNotHasKey('__Host-psc_rm', $this->jar, 'no cookie unless ticked');
        self::assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM user_tokens WHERE purpose = 'remember'"));

        $this->freshBrowser();
        $r = $this->post('/portal/login/', ['email' => 'client@example.test', 'password' => self::PASSWORD, 'remember' => '1']);
        self::assertRedirect($r, '/portal/');
        self::assertArrayHasKey('__Host-psc_rm', $this->jar);
        $cookie = null;
        foreach ($r->cookies() as [$name, , $options]) {
            if ($name === '__Host-psc_rm') {
                $cookie = $options;
            }
        }
        self::assertNotNull($cookie);
        self::assertTrue($cookie['httponly']);
        self::assertTrue($cookie['secure']);
        self::assertGreaterThan(time() + 86400 * 29, (int) $cookie['expires']);
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM user_tokens WHERE purpose = 'remember' AND user_id = ?", [$id]));

        // The browser comes back after its session ended: the cookie signs it in again.
        $_SESSION = [];
        self::assertSame(200, $this->request('GET', '/portal/account/')->status);
        self::assertSame($id, self::signedInId());
    }

    public function testLogoutClearsTheSessionAndTheRememberCookie(): void
    {
        $id = $this->makeUser();
        $this->post('/portal/login/', ['email' => 'client@example.test', 'password' => self::PASSWORD, 'remember' => '1']);
        self::assertSame($id, self::signedInId());

        self::assertSame(419, $this->post('/portal/logout/', [], false)->status, 'signing out needs the form token');
        self::assertSame($id, self::signedInId());

        $r = $this->post('/portal/logout/');
        self::assertRedirect($r, '/portal/login/');
        self::assertNull(self::signedInId());
        self::assertArrayNotHasKey('__Host-psc_rm', $this->jar);
        self::assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM user_tokens WHERE purpose = 'remember'"));
        self::assertContains('You are signed out.', self::flashes());
        self::assertSame(302, $this->request('GET', '/portal/account/')->status);
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'logout' AND user_id = ?", [$id]));
    }

    // ---------------------------------------------------- resetting passwords

    public function testPasswordResetEndToEndAndTheLinkWorksOnce(): void
    {
        $id = $this->makeUser();
        $form = $this->request('GET', '/portal/forgot/');
        self::assertSame(200, $form->status);
        self::assertStringContainsString('action="/portal/forgot/"', $form->body);

        $known = $this->post('/portal/forgot/', ['email' => 'client@example.test'] + $this->filledIn('forgot'));
        self::assertRedirect($known, '/portal/forgot/');
        $knownPage = self::withoutFormTimes($this->request('GET', '/portal/forgot/')->body);
        $unknown = $this->post('/portal/forgot/', ['email' => 'nobody@example.test'] + $this->filledIn('forgot'));
        self::assertRedirect($unknown, '/portal/forgot/');
        $unknownPage = self::withoutFormTimes($this->request('GET', '/portal/forgot/')->body);
        self::assertSame($knownPage, $unknownPage, 'the same answer whether or not the address has an account');
        self::assertStringContainsString('If that address has an account, a reset link is on its way.', $knownPage);
        self::assertNull($this->lastMailTo('nobody@example.test'));

        $token = self::tokenFrom($this->mailUrl('client@example.test', 'reset-password'));
        $page = $this->request('GET', '/portal/reset/?token=' . rawurlencode($token));
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Choose a new password', $page->body);
        self::assertStringContainsString('name="token" value="' . $token . '"', $page->body);

        $before = $this->hashOf($id);
        $mismatch = $this->post('/portal/reset/', ['token' => $token, 'purpose' => 'reset', 'password' => self::NEW_PASSWORD, 'password_confirm' => 'something else']);
        self::assertRedirect($mismatch, '/portal/reset/?token=' . rawurlencode($token));
        self::assertStringContainsString('The two passwords do not match.', $this->request('GET', '/portal/reset/?token=' . rawurlencode($token))->body);
        $weak = $this->post('/portal/reset/', ['token' => $token, 'purpose' => 'reset', 'password' => 'short', 'password_confirm' => 'short']);
        self::assertRedirect($weak, '/portal/reset/?token=' . rawurlencode($token));
        self::assertSame($before, $this->hashOf($id));

        $ok = $this->post('/portal/reset/', ['token' => $token, 'purpose' => 'reset', 'password' => self::NEW_PASSWORD, 'password_confirm' => self::NEW_PASSWORD]);
        self::assertRedirect($ok, '/portal/login/');
        self::assertContains('Your password is changed. Sign in with your new password.', self::flashes());
        self::assertTrue(password_verify(self::NEW_PASSWORD, $this->hashOf($id)));

        $reuse = $this->post('/portal/reset/', ['token' => $token, 'purpose' => 'reset', 'password' => 'another fresh phrase', 'password_confirm' => 'another fresh phrase']);
        self::assertRedirect($reuse, '/portal/reset/');
        self::assertTrue(password_verify(self::NEW_PASSWORD, $this->hashOf($id)), 'a used link changes nothing');
        $expired = $this->request('GET', '/portal/reset/?token=' . rawurlencode($token));
        self::assertStringContainsString('This link no longer works', $expired->body);
        self::assertStringContainsString('href="/portal/forgot/"', $expired->body);
        self::assertStringNotContainsString('action="/portal/reset/"', $expired->body);

        self::assertRedirect($this->signIn('client@example.test', self::PASSWORD), '/portal/login/');
        self::assertNull(self::signedInId(), 'the old password no longer works');
        self::assertRedirect($this->signIn('client@example.test', self::NEW_PASSWORD), '/portal/');
    }

    public function testAnInviteLinkSetsTheFirstPassword(): void
    {
        $result = $this->app->auth()->invite('Ivy Wing', 'ivy@example.test', 'Wing Productions');
        self::assertTrue($result['ok']);
        $url = $this->mailUrl('ivy@example.test', 'invite');
        self::assertStringContainsString('/portal/reset/?invite=1&token=', $url);
        $token = self::tokenFrom($url);

        $page = $this->request('GET', '/portal/reset/?invite=1&token=' . rawurlencode($token));
        self::assertStringContainsString('Set your password', $page->body);
        self::assertStringContainsString('name="purpose" value="invite"', $page->body);
        self::assertStringContainsString('This link no longer works', $this->request('GET', '/portal/reset/?token=' . rawurlencode($token))->body, 'an invite token is not a reset token');

        $r = $this->post('/portal/reset/', ['token' => $token, 'purpose' => 'invite', 'password' => self::NEW_PASSWORD, 'password_confirm' => self::NEW_PASSWORD]);
        self::assertRedirect($r, '/portal/login/');
        self::assertContains('Your password is set. Sign in to get started.', self::flashes());
        self::assertSame('active', $this->db->value('SELECT status FROM users WHERE email = ?', ['ivy@example.test']));
        self::assertRedirect($this->signIn('ivy@example.test', self::NEW_PASSWORD), '/portal/');
    }

    public function testAnUnknownLinkPurposeIsRefused(): void
    {
        $id = $this->makeUser();
        $this->post('/portal/forgot/', ['email' => 'client@example.test'] + $this->filledIn('forgot'));
        $token = self::tokenFrom($this->mailUrl('client@example.test', 'reset-password'));
        $before = $this->hashOf($id);

        $r = $this->post('/portal/reset/', ['token' => $token, 'purpose' => 'remember', 'password' => self::NEW_PASSWORD, 'password_confirm' => self::NEW_PASSWORD]);
        self::assertRedirect($r, '/portal/reset/');
        self::assertSame($before, $this->hashOf($id));
        self::assertNull($this->db->value("SELECT used_at FROM user_tokens WHERE purpose = 'reset'"), 'the link is still good');
    }

    // ------------------------------------------------------------- the account

    public function testTheAccountPageNeedsASignedInAccount(): void
    {
        foreach (['/portal/account/', '/portal/account/export/'] as $path) {
            $r = $this->request('GET', $path);
            self::assertRedirect($r, '/portal/login/?next=' . rawurlencode($path), 302);
        }
        foreach (['/portal/account/profile/', '/portal/account/password/', '/portal/account/delete/'] as $path) {
            $r = $this->post($path, ['name' => 'X']);
            self::assertRedirect($r, '/portal/login/?next=' . rawurlencode('/portal/'), 302);
        }

        $this->makeUser();
        $this->signIn();
        $page = $this->request('GET', '/portal/account/');
        self::assertSame(200, $page->status);
        self::assertSame('private, no-store', $page->getHeader('Cache-Control'));
        foreach (['action="/portal/account/profile/"', 'action="/portal/account/password/"', 'action="/portal/account/delete/"', 'href="/portal/account/export/"'] as $needle) {
            self::assertStringContainsString($needle, $page->body);
        }
        self::assertSame(1, substr_count($page->body, '<h1'), 'one heading per page');
    }

    public function testProfileUpdatesOnlyTheSignedInAccount(): void
    {
        $me = $this->makeUser();
        $other = $this->makeUser('other@example.test');
        $this->signIn();

        self::assertSame(419, $this->post('/portal/account/profile/', ['name' => 'No Token'], false)->status);

        $r = $this->post('/portal/account/profile/', [
            'name' => 'Casey Cue',
            'company' => 'Front of House Ltd',
            'marketing' => '1',
            'id' => (string) $other,
            'user_id' => (string) $other,
            'email' => 'hijack@example.test',
            'role' => 'admin',
        ]);
        // A saved change is answered at the top of the page, where its message takes focus.
        self::assertRedirect($r, '/portal/account/');
        self::assertStringContainsString('<div class="pt-alert pt-alert--success" role="status" tabindex="-1" data-feedback>Your details are saved.</div>', $this->request('GET', '/portal/account/')->body);
        $mine = $this->db->one('SELECT * FROM users WHERE id = ?', [$me]);
        self::assertSame('Casey Cue', $mine['name']);
        self::assertSame('Front of House Ltd', $mine['company']);
        self::assertSame(1, (int) $mine['marketing_opt_in']);
        self::assertSame('client@example.test', $mine['email'], 'the email is not editable here');
        self::assertSame('client', $mine['role']);
        $theirs = $this->db->one('SELECT name, email FROM users WHERE id = ?', [$other]);
        self::assertSame(['name' => 'Test Other', 'email' => 'other@example.test'], $theirs);
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'account.profile_updated' AND user_id = ?", [$me]));

        $r = $this->post('/portal/account/profile/', ['name' => '   ', 'company' => '']);
        self::assertRedirect($r, '/portal/account/#profile-errors');
        self::assertSame('Casey Cue', $this->db->value('SELECT name FROM users WHERE id = ?', [$me]));
        $page = $this->request('GET', '/portal/account/');
        self::assertMatchesRegularExpression('/id="profile-name"[^>]*aria-invalid="true"[^>]*aria-describedby="profile-name-error"/', $page->body);

        $this->post('/portal/account/profile/', ['name' => 'Casey Cue', 'company' => '']);
        self::assertSame(0, (int) $this->db->value('SELECT marketing_opt_in FROM users WHERE id = ?', [$me]), 'unticking opts out');
        self::assertNull($this->db->value('SELECT company FROM users WHERE id = ?', [$me]));
    }

    public function testChangingThePasswordNeedsTheCurrentOne(): void
    {
        $id = $this->makeUser();
        $this->signIn();
        $before = $this->hashOf($id);

        self::assertSame(419, $this->post('/portal/account/password/', ['current_password' => self::PASSWORD, 'new_password' => self::NEW_PASSWORD, 'new_password_confirm' => self::NEW_PASSWORD], false)->status);

        $wrong = $this->post('/portal/account/password/', ['current_password' => 'not it at all', 'new_password' => self::NEW_PASSWORD, 'new_password_confirm' => self::NEW_PASSWORD]);
        self::assertRedirect($wrong, '/portal/account/#password-errors');
        self::assertSame($before, $this->hashOf($id));
        $page = $this->request('GET', '/portal/account/');
        self::assertStringContainsString('That is not your current password.', $page->body);
        self::assertMatchesRegularExpression('/id="password-current"[^>]*aria-invalid="true"/', $page->body);
        self::assertStringNotContainsString('not it at all', $page->body);

        $mismatch = $this->post('/portal/account/password/', ['current_password' => self::PASSWORD, 'new_password' => self::NEW_PASSWORD, 'new_password_confirm' => 'something else']);
        self::assertRedirect($mismatch, '/portal/account/#password-errors');
        self::assertSame($before, $this->hashOf($id));

        $ok = $this->post('/portal/account/password/', ['current_password' => self::PASSWORD, 'new_password' => self::NEW_PASSWORD, 'new_password_confirm' => self::NEW_PASSWORD]);
        self::assertRedirect($ok, '/portal/account/');
        self::assertTrue(password_verify(self::NEW_PASSWORD, $this->hashOf($id)));
        self::assertSame(200, $this->request('GET', '/portal/account/')->status, 'this browser stays signed in');
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'password.changed'"));

        $this->freshBrowser();
        self::assertRedirect($this->signIn('client@example.test', self::PASSWORD), '/portal/login/');
        self::assertRedirect($this->signIn('client@example.test', self::NEW_PASSWORD), '/portal/');
    }

    public function testDeletingAnonymisesTheAccountKeepsBusinessRecordsAndBlocksSignIn(): void
    {
        $id = $this->makeUser();
        $this->db->update('users', ['company' => 'Front of House Ltd', 'marketing_opt_in' => 1], ['id' => $id]);
        $ticket = $this->db->insert('tickets', ['user_id' => $id, 'type' => 'bug', 'product' => 'stagewatch', 'title' => 'Clock drifts', 'body' => 'It drifts.', 'last_activity_at' => time(), 'created_at' => time(), 'updated_at' => time()]);
        $licence = $this->db->insert('licences', ['user_id' => $id, 'product' => 'stagewatch', 'tier' => 'pro', 'status' => 'issued', 'reference' => 'SW-TEST-1', 'created_at' => time(), 'updated_at' => time()]);
        $this->post('/portal/login/', ['email' => 'client@example.test', 'password' => self::PASSWORD, 'remember' => '1']);
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM user_tokens WHERE user_id = ?', [$id]));

        self::assertSame(419, $this->post('/portal/account/delete/', ['delete_password' => self::PASSWORD, 'delete_confirm' => 'DELETE'], false)->status);

        $wrongPassword = $this->post('/portal/account/delete/', ['delete_password' => 'not it at all', 'delete_confirm' => 'DELETE']);
        self::assertRedirect($wrongPassword, '/portal/account/#delete-errors');
        $wrongWord = $this->post('/portal/account/delete/', ['delete_password' => self::PASSWORD, 'delete_confirm' => 'delete']);
        self::assertRedirect($wrongWord, '/portal/account/#delete-errors');
        self::assertSame('active', $this->db->value('SELECT status FROM users WHERE id = ?', [$id]), 'nothing happens without both');
        self::assertSame([], array_filter($this->mails(), static fn (array $m): bool => $m['template'] === 'account-deleted-admin'));

        $r = $this->post('/portal/account/delete/', ['delete_password' => self::PASSWORD, 'delete_confirm' => 'DELETE']);
        self::assertRedirect($r, '/portal/login/');
        $row = $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
        self::assertSame('disabled', $row['status']);
        self::assertSame('deleted-' . $id . '@deleted.invalid', $row['email']);
        self::assertSame('Deleted user', $row['name']);
        self::assertNull($row['company']);
        self::assertSame(0, (int) $row['marketing_opt_in']);
        self::assertFalse(password_verify(self::PASSWORD, (string) $row['password_hash']));
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM user_tokens WHERE user_id = ?', [$id]));
        self::assertSame($id, (int) $this->db->value('SELECT user_id FROM tickets WHERE id = ?', [$ticket]), 'tickets are kept');
        self::assertSame($id, (int) $this->db->value('SELECT user_id FROM licences WHERE id = ?', [$licence]), 'licences are kept');
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'account.deleted' AND subject = ?", [(string) $id]));

        self::assertNull(self::signedInId(), 'signed out');
        self::assertArrayNotHasKey('__Host-psc_rm', $this->jar);
        self::assertSame(302, $this->request('GET', '/portal/account/')->status);

        $admin = $this->lastMailTo('owner@example.test');
        self::assertNotNull($admin);
        self::assertSame('account-deleted-admin', $admin['template']);
        self::assertStringContainsString('#' . $id, $admin['html']);
        self::assertStringContainsString('1 licence', $admin['html']);
        self::assertStringContainsString('1 ticket', $admin['html']);

        $this->freshBrowser();
        self::assertRedirect($this->signIn('client@example.test', self::PASSWORD), '/portal/login/');
        self::assertNull(self::signedInId());
        self::assertRedirect($this->signIn('deleted-' . $id . '@deleted.invalid', self::PASSWORD), '/portal/login/');
        self::assertNull(self::signedInId());
        $this->post('/portal/forgot/', ['email' => 'deleted-' . $id . '@deleted.invalid'] + $this->filledIn('forgot'));
        self::assertNull($this->lastMailTo('deleted-' . $id . '@deleted.invalid'), 'no reset for a deleted account');
    }

    /** The mailer swallows a broken template (it logs and returns false), so every account email is rendered here. */
    public function testEveryAccountEmailRenders(): void
    {
        $user = ['id' => 7, 'name' => 'Nina <Stage>', 'email' => 'nina@example.test', 'company' => 'Cue Lights'];
        $cases = [
            'verify-email' => [['name' => 'Nina <Stage>', 'url' => 'https://patternsshowcontrol.test/portal/verify/?token=a.b'], '/portal/verify/?token=a.b'],
            'reset-password' => [['name' => 'Nina <Stage>', 'url' => 'https://patternsshowcontrol.test/portal/reset/?token=a.b'], '/portal/reset/?token=a.b'],
            'invite' => [['name' => 'Nina <Stage>', 'url' => 'https://patternsshowcontrol.test/portal/reset/?invite=1&token=a.b'], '/portal/forgot/'],
            'account-exists' => [['name' => 'Nina <Stage>'], '/portal/login/'],
            'admin-approval' => [['user' => $user], '/portal/admin/users/7/'],
            'account-approved' => [['name' => 'Nina <Stage>'], '/portal/login/'],
            'account-deleted-admin' => [['id' => 7, 'name' => 'Nina <Stage>', 'email' => 'nina@example.test', 'company' => '', 'kept' => ['licences' => 2, 'tickets' => 1, 'comments' => 0]], '2 licences, 1 ticket and 0 comments'],
        ];
        foreach ($cases as $template => [$data, $needle]) {
            self::assertTrue($this->mail->send('to@example.test', 'Subject', $template, $data), $template);
            $html = $this->lastMailTo('to@example.test')['html'] ?? '';
            self::assertStringContainsString($needle, $html, $template);
            self::assertStringNotContainsString('<Stage>', $html, "{$template} escapes what people typed");
        }
    }

    public function testTheDataExportHoldsOnlyTheAccountsOwnData(): void
    {
        $me = $this->makeUser();
        $other = $this->makeUser('other@example.test');
        $staff = $this->makeUser('admin@example.test', self::PASSWORD, 'admin');
        $now = time();
        $ticket = fn (int $user, string $title, string $visibility = 'private'): int => $this->db->insert('tickets', ['user_id' => $user, 'type' => 'feature', 'product' => 'patterns', 'title' => $title, 'body' => 'Body of ' . $title, 'visibility' => $visibility, 'last_activity_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        $comment = fn (int $ticketId, int $user, string $body, int $staffFlag = 0, int $internal = 0): int => $this->db->insert('ticket_comments', ['ticket_id' => $ticketId, 'user_id' => $user, 'body' => $body, 'is_staff' => $staffFlag, 'is_internal' => $internal, 'created_at' => $now]);
        $mine = $ticket($me, 'My public idea', 'public');
        $theirs = $ticket($other, 'Their private bug');
        $comment($mine, $me, 'My own comment');
        $comment($mine, $staff, 'A staff reply', 1);
        $comment($mine, $staff, 'Internal staff note', 1, 1);
        $comment($mine, $other, 'Someone else on my idea');
        $comment($theirs, $other, 'Their own comment');
        $comment($theirs, $me, 'My comment on their ticket');
        $this->db->insert('licences', ['user_id' => $me, 'product' => 'stagewatch', 'tier' => 'pro', 'status' => 'issued', 'reference' => 'SW-MINE', 'admin_note' => 'Admin only remark', 'created_at' => $now, 'updated_at' => $now]);
        $this->db->insert('licences', ['user_id' => $other, 'product' => 'stagewatch', 'tier' => 'standard', 'status' => 'issued', 'reference' => 'SW-THEIRS', 'created_at' => $now, 'updated_at' => $now]);
        $this->db->insert('chat_conversations', ['id' => str_repeat('a', 32), 'user_id' => $me, 'ip_hash' => 'secret-ip-hash', 'page' => '/stagewatch/', 'turns' => 1, 'created_at' => $now, 'last_at' => $now]);
        $this->db->insert('chat_messages', ['conversation_id' => str_repeat('a', 32), 'role' => 'user', 'content' => 'My question to the assistant', 'created_at' => $now]);
        $this->db->insert('chat_conversations', ['id' => str_repeat('b', 32), 'user_id' => $other, 'created_at' => $now, 'last_at' => $now]);
        $this->db->insert('chat_messages', ['conversation_id' => str_repeat('b', 32), 'role' => 'user', 'content' => 'Their question to the assistant', 'created_at' => $now]);

        $this->signIn();
        $r = $this->request('GET', '/portal/account/export/');
        self::assertSame(200, $r->status);
        self::assertSame('application/json', $r->getHeader('Content-Type'));
        self::assertStringStartsWith('attachment; filename="patterns-account-' . $me . '-', (string) $r->getHeader('Content-Disposition'));
        self::assertSame('nosniff', $r->getHeader('X-Content-Type-Options'));
        self::assertStringContainsString('no-store', (string) $r->getHeader('Cache-Control'));

        $data = json_decode($r->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('client@example.test', $data['profile']['email']);
        self::assertArrayNotHasKey('password_hash', $data['profile']);
        self::assertSame(['My public idea'], array_column($data['tickets'], 'title'));
        self::assertEqualsCanonicalizing(['My own comment', 'A staff reply', 'My comment on their ticket'], array_column($data['comments'], 'body'));
        self::assertSame(['SW-MINE'], array_column($data['licences'], 'reference'));
        self::assertArrayNotHasKey('admin_note', $data['licences'][0]);
        self::assertCount(1, $data['assistant_conversations']);
        self::assertSame('My question to the assistant', $data['assistant_conversations'][0]['messages'][0]['content']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) $data['profile']['created_at']);

        foreach (['password_hash', '$2y$', '$argon2', 'other@example.test', 'Their private bug', 'Internal staff note', 'Someone else on my idea', 'Admin only remark', 'SW-THEIRS', 'Their question', 'secret-ip-hash', 'admin@example.test'] as $needle) {
            self::assertStringNotContainsString($needle, $r->body, "the export leaks nothing: {$needle}");
        }
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'account.exported' AND user_id = ?", [$me]));
    }

    // ------------------------------------------------- review: attacks that worked

    /**
     * Hashing a throwaway password for each unknown email cost a hash and a verify (about twice a wrong
     * password's time), so response time told an attacker which emails have accounts. The stand-in hash must be
     * made at the same cost as a real one, and matching its throwaway password must still sign nobody in.
     */
    public function testAnUnknownEmailCostsOneVerifyLikeAWrongPassword(): void
    {
        $dummy = (new \ReflectionMethod($this->app->auth(), 'dummyHash'))->invoke($this->app->auth());
        $real = \Psc\Auth\Auth::hash('any password at all');
        self::assertSame(password_get_info($real), password_get_info($dummy), 'the same algorithm and cost as a real hash');
        self::assertFileDoesNotExist($this->storage . '/cache/auth-dummy-hash', 'the built-in hash fits, so nothing is made per request');

        $r = $this->signIn('nobody@example.test', 'not-a-password');
        self::assertRedirect($r, '/portal/login/');
        self::assertNull(self::signedInId(), 'the stand-in hash opens nothing');
    }

    /** Successful sign-ins used the address's attempts, so a venue or office on one network locked itself out. */
    public function testOnlyFailedSignInsCountAgainstANetwork(): void
    {
        $this->bootApp(['security' => ['login_max_attempts' => 1]]); // four attempts per address and window
        for ($i = 1; $i <= 6; $i++) {
            $this->makeUser("crew{$i}@example.test");
            $this->freshBrowser();
            self::assertRedirect($this->signIn("crew{$i}@example.test"), '/portal/', 303);
            self::assertNotNull(self::signedInId(), "crew member {$i} signs in");
        }
        for ($i = 1; $i <= 4; $i++) {
            $this->freshBrowser();
            $this->signIn("crew{$i}@example.test", 'wrong guess ' . $i);
        }
        $this->freshBrowser();
        $this->signIn('crew5@example.test');
        self::assertNull(self::signedInId(), 'four failures from one address hold it back, even with the right password');
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'login.throttled'"));

        // Every attempt still costs a full password verify, so one address has a far higher ceiling on all of them.
        $this->bootApp(['security' => ['login_max_attempts' => 1]]); // a ceiling of twenty
        $this->makeUser();
        for ($i = 1; $i <= 20; $i++) {
            $this->freshBrowser();
            $this->signIn();
            self::assertNotNull(self::signedInId(), "sign-in {$i} of twenty");
        }
        $this->freshBrowser();
        $this->signIn();
        self::assertNull(self::signedInId(), 'the twenty-first attempt in the window waits');
    }

    /**
     * Confirming the address of an invited account (a confirmation link asked for on the public form) put it in
     * the approval queue, although an administrator had created it; a reset link already did not.
     */
    public function testAnInvitedAccountNeverWaitsForApproval(): void
    {
        $this->bootApp(['security' => ['registration' => 'approval']]);
        $this->app->auth()->invite('Ivy Wing', 'ivy@example.test', null);
        $invite = self::tokenFrom($this->mailUrl('ivy@example.test', 'invite'));
        $this->post('/portal/verify/resend/', ['email' => 'ivy@example.test'] + $this->filledIn('resend'));
        $verify = $this->confirmEmailLink('/portal/verify/?token=' . rawurlencode(self::tokenFrom($this->mailUrl('ivy@example.test', 'verify-email'))));
        self::assertStringContainsString('Email address confirmed', $verify->body);
        self::assertSame('active', $this->db->value('SELECT status FROM users WHERE email = ?', ['ivy@example.test']));
        self::assertNull($this->lastMailTo('owner@example.test'), 'nothing for an administrator to approve');

        $this->post('/portal/reset/', ['token' => $invite, 'purpose' => 'invite', 'password' => self::NEW_PASSWORD, 'password_confirm' => self::NEW_PASSWORD]);
        self::assertRedirect($this->signIn('ivy@example.test', self::NEW_PASSWORD), '/portal/');
    }

    /** A second confirmation link for an account already waiting emailed the administrators about it again. */
    public function testAdministratorsHearOnceWhenAnAccountJoinsTheQueue(): void
    {
        $this->bootApp(['security' => ['registration' => 'approval']]);
        $id = $this->makeUser('nina@example.test', self::PASSWORD, 'client', 'approval');
        $token = $this->app->auth()->issue($id, 'verify');
        $verify = $this->confirmEmailLink('/portal/verify/?token=' . rawurlencode($token));
        self::assertStringContainsString('waiting for approval', $verify->body);
        self::assertSame('approval', $this->db->value('SELECT status FROM users WHERE id = ?', [$id]));
        self::assertNull($this->lastMailTo('owner@example.test'));
    }

    /** Repeated sign-ups with a taken address sent it a notice every time, from any number of addresses. */
    public function testRepeatedSignUpsWithATakenAddressAreCappedButAnswerTheSame(): void
    {
        $this->makeUser('taken@example.test');
        $pages = [];
        for ($i = 0; $i < 5; $i++) {
            $_SESSION['_flash'] = [];
            self::assertRedirect($this->register('taken@example.test'), '/portal/register/');
            $pages[] = self::withoutFormTimes($this->request('GET', '/portal/register/')->body);
        }
        self::assertCount(1, array_unique($pages), 'every attempt gets the same page');
        $notices = array_filter($this->mails(), static fn (array $m): bool => $m['to'] === 'taken@example.test');
        self::assertCount(3, $notices, 'three notices an hour for one address');
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM users'));
    }

    /**
     * The name typed at sign-up was printed in the emails sent to the typed address, so anyone could send a
     * stranger an email from this site with words of their own in it. Until the address is proven, emails greet
     * nobody by name; afterwards they do.
     */
    public function testEmailsToAnUnprovenAddressCarryNoTypedName(): void
    {
        $spam = 'Claim your prize at www.spam.example';
        $this->register('stranger@example.test', ['name' => $spam]);
        $this->post('/portal/verify/resend/', ['email' => 'stranger@example.test'] + $this->filledIn('resend'));
        $this->post('/portal/forgot/', ['email' => 'stranger@example.test'] + $this->filledIn('forgot'));
        $this->register('stranger@example.test', ['name' => $spam]);
        $templates = [];
        foreach ($this->mails() as $mail) {
            if ($mail['to'] === 'stranger@example.test') {
                $templates[] = $mail['template'];
                self::assertStringNotContainsString('spam.example', $mail['html'], $mail['template']);
                self::assertStringContainsString('Hello,', $mail['html'], $mail['template']);
            }
        }
        self::assertSame(['verify-email', 'verify-email', 'reset-password', 'account-exists'], $templates);

        $this->makeUser('known@example.test');
        $this->post('/portal/forgot/', ['email' => 'known@example.test'] + $this->filledIn('forgot'));
        self::assertStringContainsString('Hello Test Known,', $this->lastMailTo('known@example.test')['html'] ?? '', 'a proven address is greeted by name');
    }

    /**
     * An invitation link stayed good for seven days after the password was set through a reset link, and a
     * reset link stayed good after the password was changed: either could set the password again.
     */
    public function testOutstandingLinksStopWorkingOnceAPasswordIsSet(): void
    {
        $this->app->auth()->invite('Ivy Wing', 'ivy@example.test', null);
        $invite = self::tokenFrom($this->mailUrl('ivy@example.test', 'invite'));
        $this->post('/portal/forgot/', ['email' => 'ivy@example.test'] + $this->filledIn('forgot'));
        $reset = self::tokenFrom($this->mailUrl('ivy@example.test', 'reset-password'));
        $this->post('/portal/reset/', ['token' => $reset, 'purpose' => 'reset', 'password' => self::NEW_PASSWORD, 'password_confirm' => self::NEW_PASSWORD]);
        $ivy = (int) $this->db->value('SELECT id FROM users WHERE email = ?', ['ivy@example.test']);
        $set = $this->hashOf($ivy);

        self::assertStringContainsString('This link no longer works', $this->request('GET', '/portal/reset/?invite=1&token=' . rawurlencode($invite))->body);
        $r = $this->post('/portal/reset/', ['token' => $invite, 'purpose' => 'invite', 'password' => 'someone else entirely', 'password_confirm' => 'someone else entirely']);
        self::assertRedirect($r, '/portal/reset/?invite=1');
        self::assertSame($set, $this->hashOf($ivy), 'the old invitation changes nothing');
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM user_tokens WHERE user_id = ? AND purpose = 'invite'", [$ivy]), 'the invitation is spent, not forgotten');

        // A reset link asked for before a password change dies with the old password.
        $id = $this->makeUser();
        $this->freshBrowser();
        $this->post('/portal/forgot/', ['email' => 'client@example.test'] + $this->filledIn('forgot'));
        $reset = self::tokenFrom($this->mailUrl('client@example.test', 'reset-password'));
        $this->signIn();
        $this->post('/portal/account/password/', ['current_password' => self::PASSWORD, 'new_password' => self::NEW_PASSWORD, 'new_password_confirm' => self::NEW_PASSWORD]);
        $changed = $this->hashOf($id);
        $this->freshBrowser();
        $r = $this->post('/portal/reset/', ['token' => $reset, 'purpose' => 'reset', 'password' => 'someone else entirely', 'password_confirm' => 'someone else entirely']);
        self::assertRedirect($r, '/portal/reset/');
        self::assertSame($changed, $this->hashOf($id));

        // A confirmation link outlives nothing either: the reset proved the address.
        $this->freshBrowser();
        $this->register('nina@example.test');
        $verify = self::tokenFrom($this->mailUrl('nina@example.test', 'verify-email'));
        $this->post('/portal/forgot/', ['email' => 'nina@example.test'] + $this->filledIn('forgot'));
        $reset = self::tokenFrom($this->mailUrl('nina@example.test', 'reset-password'));
        $this->post('/portal/reset/', ['token' => $reset, 'purpose' => 'reset', 'password' => self::NEW_PASSWORD, 'password_confirm' => self::NEW_PASSWORD]);
        self::assertSame('active', $this->db->value('SELECT status FROM users WHERE email = ?', ['nina@example.test']));
        self::assertStringContainsString('This link no longer works', $this->request('GET', '/portal/verify/?token=' . rawurlencode($verify))->body);
    }

    /** A one-time link was spent by a read then an unconditional write, so two racing requests could both use it. */
    public function testAOneTimeLinkIsClaimedByOneRequestOnly(): void
    {
        $id = $this->makeUser();
        $auth = $this->app->auth();
        $spend = new \ReflectionMethod($auth, 'spend');
        $selector = explode('.', $auth->issue($id, 'reset'), 2)[0];
        self::assertTrue($spend->invoke($auth, $selector, 'reset'), 'the first request claims it');
        self::assertFalse($spend->invoke($auth, $selector, 'reset'), 'the second finds it already spent');
        self::assertFalse($spend->invoke($auth, $selector, 'invite'), 'and a claim names the purpose it was issued for');
    }

    /**
     * Changing the password dropped "Keep me signed in" on the very browser that changed it, so it was signed out
     * when its session ended. That browser keeps it; every other browser's remember-me token goes.
     */
    public function testChangingThePasswordKeepsThisBrowserRememberedAndNoOther(): void
    {
        $id = $this->makeUser();
        $this->post('/portal/login/', ['email' => 'client@example.test', 'password' => self::PASSWORD, 'remember' => '1']);
        $otherBrowser = $this->jar;
        $this->freshBrowser();
        $this->post('/portal/login/', ['email' => 'client@example.test', 'password' => self::PASSWORD, 'remember' => '1']);
        self::assertSame(2, (int) $this->db->value("SELECT COUNT(*) FROM user_tokens WHERE purpose = 'remember' AND user_id = ?", [$id]));

        $r = $this->post('/portal/account/password/', ['current_password' => self::PASSWORD, 'new_password' => self::NEW_PASSWORD, 'new_password_confirm' => self::NEW_PASSWORD]);
        self::assertRedirect($r, '/portal/account/');
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM user_tokens WHERE purpose = 'remember' AND user_id = ?", [$id]));

        $_SESSION = []; // this browser's session ends; its cookie signs it in again
        self::assertSame(200, $this->request('GET', '/portal/account/')->status);
        self::assertSame($id, self::signedInId());

        $_SESSION = [];
        $this->jar = $otherBrowser;
        self::assertSame(302, $this->request('GET', '/portal/account/')->status, 'the other browser is signed out');
        self::assertNull(self::signedInId());
    }

    /**
     * Anyone could register deleted-<id>@deleted.invalid ahead of time; the deletion then hit the unique email
     * and failed with an error page, so that person could never delete their account.
     */
    public function testNoAddressCanStopAnAccountBeingDeleted(): void
    {
        $id = $this->makeUser();
        $r = $this->register('deleted-' . $id . '@deleted.invalid');
        self::assertRedirect($r, '/portal/register/');
        self::assertStringContainsString('Enter a valid email address.', $this->request('GET', '/portal/register/')->body);
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM users'), 'a .invalid address can never have an account');
        self::assertFalse($this->app->auth()->invite('Squatter', 'deleted-' . $id . '@deleted.invalid', null)['ok']);

        // A row that already holds the address (an older import, say) still cannot block the deletion.
        $this->db->insert('users', ['email' => 'deleted-' . $id . '@deleted.invalid', 'name' => 'Squatter', 'password_hash' => 'x', 'role' => 'client', 'status' => 'pending', 'created_at' => time(), 'updated_at' => time()]);
        $this->freshBrowser();
        $this->signIn();
        $r = $this->post('/portal/account/delete/', ['delete_password' => self::PASSWORD, 'delete_confirm' => 'DELETE']);
        self::assertRedirect($r, '/portal/login/');
        $row = $this->db->one('SELECT status, email, name FROM users WHERE id = ?', [$id]);
        self::assertSame('disabled', $row['status']);
        self::assertSame('Deleted user', $row['name']);
        self::assertMatchesRegularExpression('/^deleted-' . $id . '-[0-9a-f]{12}@deleted\.invalid$/', (string) $row['email']);
    }

    /** The only administrator could delete their account and leave nobody to approve accounts or issue licences. */
    public function testTheOnlyAdministratorCannotDeleteTheirAccount(): void
    {
        $admin = $this->makeUser('admin@example.test', self::PASSWORD, 'admin');
        $this->makeUser('disabled-admin@example.test', self::PASSWORD, 'admin', 'disabled');
        $this->signIn('admin@example.test');
        $page = $this->request('GET', '/portal/account/');
        self::assertStringContainsString('You are the only administrator.', $page->body);
        self::assertStringNotContainsString('action="/portal/account/delete/"', $page->body);

        $r = $this->post('/portal/account/delete/', ['delete_password' => self::PASSWORD, 'delete_confirm' => 'DELETE']);
        self::assertRedirect($r, '/portal/account/#delete');
        self::assertSame(['status' => 'active', 'role' => 'admin', 'email' => 'admin@example.test'], $this->db->one('SELECT status, role, email FROM users WHERE id = ?', [$admin]));
        self::assertSame($admin, self::signedInId(), 'still signed in');
        self::assertContains('Your account was not deleted. You are the only administrator. Make another account an administrator first, then try again.', self::flashes());
        self::assertNull($this->lastMailTo('owner@example.test'));

        $this->makeUser('second@example.test', self::PASSWORD, 'admin');
        self::assertStringContainsString('action="/portal/account/delete/"', $this->request('GET', '/portal/account/')->body);
        self::assertRedirect($this->post('/portal/account/delete/', ['delete_password' => self::PASSWORD, 'delete_confirm' => 'DELETE']), '/portal/login/');
        self::assertSame('disabled', $this->db->value('SELECT status FROM users WHERE id = ?', [$admin]));
    }

    /**
     * Names and companies were stored as sent: bytes that are not UTF-8 made the data copy fail with an error
     * page, and line breaks and control characters went into pages and emails.
     */
    public function testOneLineFieldsAreCleanedAndTheDataCopyNeverFails(): void
    {
        $id = $this->makeUser();
        $this->signIn();
        $r = $this->post('/portal/account/profile/', ['name' => "Nina \xff\xfe", 'company' => 'Cue Lights']);
        self::assertRedirect($r, '/portal/account/#profile-errors');
        self::assertSame('Test Client', $this->db->value('SELECT name FROM users WHERE id = ?', [$id]), 'not UTF-8: refused, nothing stored');

        $this->post('/portal/account/profile/', ['name' => "Nina\r\n\tStage\x00", 'company' => "Front\x07 of\n\nHouse "]);
        self::assertSame(['name' => 'Nina Stage', 'company' => 'Front of House'], $this->db->one('SELECT name, company FROM users WHERE id = ?', [$id]));

        // A record holding bytes that are not UTF-8 (written before these checks, or imported) is still exported.
        // MySQL in strict mode cannot hold such bytes at all, so the legacy record exists only on SQLite.
        if ($this->db->driver() !== 'sqlite') {
            return;
        }
        $this->db->insert('tickets', ['user_id' => $id, 'type' => 'bug', 'product' => 'patterns', 'title' => "Bad \xff bytes", 'body' => 'Body', 'last_activity_at' => time(), 'created_at' => time(), 'updated_at' => time()]);
        $export = $this->request('GET', '/portal/account/export/');
        self::assertSame(200, $export->status);
        $data = json_decode($export->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame("Bad \u{FFFD} bytes", $data['tickets'][0]['title']);

        $this->freshBrowser();
        $this->register('line@example.test', ['name' => "Line\nBreak", 'company' => "Two\r\nLines"]);
        self::assertSame(['name' => 'Line Break', 'company' => 'Two Lines'], $this->db->one('SELECT name, company FROM users WHERE email = ?', ['line@example.test']));
    }

    // ------------------------------------------------------- Industry Updates comments and the way back

    /** A news comment straight into the database, with the article's visible count kept true. */
    private function newsComment(int $article, int $user, string $body, string $status = 'visible', int $reports = 0): int
    {
        $id = $this->db->insert('news_comments', ['article_id' => $article, 'user_id' => $user, 'body' => $body, 'status' => $status, 'reports' => $reports, 'created_at' => time() - 60, 'updated_at' => time() - 60]);
        \Psc\News\Comments::recountArticles($this->db, [$article]);
        return $id;
    }

    public function testTheDataCopyListsNewsCommentsInEveryStatusAndTheReportsMade(): void
    {
        $me = $this->makeUser();
        $other = $this->makeUser('other@example.test');
        $article = $this->makeArticle(['slug' => 'export-me', 'title' => 'An article to comment on for the copy']);
        $this->newsComment($article, $me, 'My visible comment');
        $this->newsComment($article, $me, 'My waiting comment', 'pending');
        $this->newsComment($article, $me, 'My hidden comment', 'hidden');
        $theirs = $this->newsComment($article, $other, 'Their words, which I reported', 'visible', 1);
        $this->db->insert('news_comment_reports', ['comment_id' => $theirs, 'user_id' => $me, 'created_at' => time()]);
        $this->newsComment($article, $other, 'Their other words');

        $this->signIn();
        $data = json_decode($this->request('GET', '/portal/account/export/')->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['My visible comment', 'My waiting comment', 'My hidden comment'], array_column($data['news_comments'], 'body'));
        self::assertSame(['visible', 'pending', 'hidden'], array_column($data['news_comments'], 'status'));
        self::assertSame('https://patternsshowcontrol.test/news/video/export-me/', $data['news_comments'][0]['url'], 'the article\'s own address');
        self::assertSame('An article to comment on for the copy', $data['news_comments'][0]['article']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) $data['news_comments'][0]['created_at']);
        self::assertSame([$theirs], array_column($data['news_comment_reports'], 'comment_id'));
        $copy = json_encode($data, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Their words, which I reported', $copy, 'a report names the comment, never the other person\'s words');
        self::assertStringNotContainsString('Their other words', $copy);
        self::assertStringNotContainsString('other@example.test', $copy);
    }

    /**
     * The account page lists the person's news comments, the newest first, each linking to its comment page (where it
     * can be deleted) and naming its article only while that is live: the one way to find a comment on an article
     * taken down since.
     */
    public function testTheAccountPageListsTheNewsCommentsWithTheWayToEach(): void
    {
        $me = $this->makeUser();
        $other = $this->makeUser('other@example.test');
        $live = $this->makeArticle(['slug' => 'still-live', 'title' => 'An article still on the site', 'categories' => ['audio', 'video']]);
        $gone = $this->makeArticle(['slug' => 'taken-down-since', 'title' => 'A headline nobody may read now', 'categories' => ['legal']]);
        $visible = $this->newsComment($live, $me, "My visible comment\nover two lines");
        $hidden = $this->newsComment($live, $me, 'My hidden comment', 'hidden');
        $orphan = $this->newsComment($gone, $me, 'My comment on the article taken down');
        $this->newsComment($live, $other, 'Their comment, never on my page');
        $this->db->update('news_comments', ['created_at' => time() - 3600], ['id' => $visible]);
        (new \Psc\News\ArticleStore($this->app))->unpublish($gone);

        $this->signIn();
        $page = $this->request('GET', '/portal/account/');
        self::assertSame(200, $page->status);
        self::assertSame(1, preg_match('#<section class="pt-section" id="comments"[^>]*>(.*?)</section>#s', $page->body, $section));
        preg_match_all('#<th scope="row"><a href="([^"]+)">([^<]*)</a></th>#', $section[1], $rows, PREG_SET_ORDER);
        self::assertSame([
            ['/news/legal/taken-down-since/comment/#comment-' . $orphan, 'My comment on the article taken down'],
            ['/news/audio/still-live/comment/#comment-' . $hidden, 'My hidden comment'],
            ['/news/audio/still-live/comment/#comment-' . $visible, 'My visible comment over two lines'],
        ], array_map(static fn (array $r): array => [html_entity_decode($r[1]), $r[2]], $rows), 'the newest first, each to its comment page');
        self::assertStringContainsString('An article still on the site', $section[1]);
        self::assertStringNotContainsString('A headline nobody may read now', $section[1], 'an article not live is not named');
        self::assertStringContainsString('An article that is not published at the moment', $section[1]);
        self::assertStringContainsString('Hidden by a moderator', $section[1]);
        self::assertStringNotContainsString('Their comment', $page->body);
        self::assertStringContainsString('<table class="pt-table pt-table--stack">', $section[1]);
        foreach (['Article', 'Status', 'Posted'] as $label) {
            self::assertStringContainsString('data-label="' . $label . '"', $section[1]);
        }

        // That page is the person's, and its delete takes the comment.
        $there = $this->request('GET', '/news/legal/taken-down-since/comment/');
        self::assertSame(200, $there->status);
        self::assertStringContainsString('name="comment" value="' . $orphan . '"', $there->body);

        $this->freshBrowser();
        $this->makeUser('quiet@example.test');
        $this->signIn('quiet@example.test');
        self::assertStringNotContainsString('id="comments"', $this->request('GET', '/portal/account/')->body, 'no section without comments');
    }

    public function testDeletingAnAccountDeletesItsNewsCommentsAndReportsAndCountsAgain(): void
    {
        $me = $this->makeUser();
        $others = [$this->makeUser('a@example.test'), $this->makeUser('b@example.test'), $this->makeUser('c@example.test')];
        $article = $this->makeArticle();
        $second = $this->makeArticle();
        $this->newsComment($article, $me, 'Mine, visible');
        $this->newsComment($second, $me, 'Mine, also visible');
        $this->newsComment($second, $me, 'Mine, waiting', 'pending');
        $mineReported = $this->newsComment($article, $me, 'Mine, reported by others', 'visible', 2);
        foreach ([$others[0], $others[1]] as $u) {
            $this->db->insert('news_comment_reports', ['comment_id' => $mineReported, 'user_id' => $u, 'created_at' => time()]);
        }
        $theirs = $this->newsComment($article, $others[2], 'Theirs, which I reported', 'visible', 2);
        foreach ([$me, $others[0]] as $u) {
            $this->db->insert('news_comment_reports', ['comment_id' => $theirs, 'user_id' => $u, 'created_at' => time()]);
        }
        self::assertSame(3, (int) $this->db->value('SELECT comment_count FROM news_articles WHERE id = ?', [$article]));
        self::assertSame(1, (int) $this->db->value('SELECT comment_count FROM news_articles WHERE id = ?', [$second]));

        $this->signIn();
        $page = $this->request('GET', '/portal/account/')->body;
        self::assertStringContainsString('(you have 4 comments) are public, so they are deleted for good with your account', $page);

        $r = $this->post('/portal/account/delete/', ['delete_password' => self::PASSWORD, 'delete_confirm' => 'DELETE']);
        self::assertRedirect($r, '/portal/login/');
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM news_comments WHERE user_id = ?', [$me]));
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM news_comment_reports WHERE user_id = ?', [$me]));
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM news_comment_reports WHERE comment_id = ?', [$mineReported]), 'reports on the deleted comments went with them');
        self::assertSame(1, (int) $this->db->value('SELECT reports FROM news_comments WHERE id = ?', [$theirs]), 'counted again without the deleted report');
        self::assertSame(1, (int) $this->db->value('SELECT comment_count FROM news_articles WHERE id = ?', [$article]));
        self::assertSame(0, (int) $this->db->value('SELECT comment_count FROM news_articles WHERE id = ?', [$second]));
        self::assertSame('Deleted user', $this->db->value('SELECT name FROM users WHERE id = ?', [$me]), 'the account row is still anonymised, not removed');

        $audit = json_decode((string) $this->db->value("SELECT meta FROM audit_log WHERE action = 'account.deleted'"), true);
        // news_digest: the weekly digest's subscription, none here (docs/NEWS.md §14.7; DigestAccountTest has the rest).
        self::assertSame(['news_comments' => 4, 'news_reports' => 1, 'news_digest' => 'none'], $audit['removed']);
        $mail = $this->lastMailTo('owner@example.test');
        self::assertNotNull($mail);
        self::assertSame(['news_comments' => 4, 'news_reports' => 1, 'news_digest' => 'none'], $mail['data']['removed']);
        self::assertStringContainsString('4 comments on Industry Updates articles and 1 report of other people', $mail['html']);
        self::assertStringContainsString('0 comments on tickets', $mail['html']);
    }

    public function testRegistrationCarriesWhereThePersonWasGoingToTheConfirmationPage(): void
    {
        $this->makeArticle(['slug' => 'good-read']);
        $form = $this->request('GET', '/portal/register/?next=' . rawurlencode('/news/video/good-read/#comments'));
        self::assertSame(200, $form->status);
        $this->register('nina@example.test');
        $check = $this->request('GET', '/portal/register/');
        self::assertStringContainsString('Check your email', $check->body);
        self::assertStringContainsString('take you back to the article you were reading', $check->body);

        $url = $this->mailUrl('nina@example.test', 'verify-email');
        $verify = $this->confirmEmailLink('/portal/verify/?token=' . rawurlencode(self::tokenFrom($url)));
        self::assertStringContainsString('Email address confirmed', $verify->body);
        self::assertStringContainsString('<a class="pt-btn pt-btn--primary" href="/portal/login/?next=' . e(rawurlencode('/news/video/good-read/#comments')) . '">Sign in</a>', $verify->body);
        self::assertStringContainsString('<a class="pt-btn pt-btn--ghost" href="/news/video/good-read/#comments">Continue to the article</a>', $verify->body);
        self::assertArrayNotHasKey(\Psc\Controllers\Portal\AuthController::RETURN_KEY, $_SESSION, 'offered once');

        $login = $this->post('/portal/login/', ['email' => 'nina@example.test', 'password' => self::PASSWORD, 'next' => '/news/video/good-read/#comments']);
        self::assertRedirect($login, '/news/video/good-read/#comments');
    }

    public function testThePortalSwitchFromSignInKeepsTheWayBack(): void
    {
        // Sent to sign in from an article's comment page, the person picks Create an account instead.
        $this->request('GET', '/portal/login/?next=' . rawurlencode('/news/switch-story/comment/'));
        $this->request('GET', '/portal/register/');
        $this->register('nina@example.test');
        $url = $this->mailUrl('nina@example.test', 'verify-email');
        $verify = $this->confirmEmailLink('/portal/verify/?token=' . rawurlencode(self::tokenFrom($url)));
        self::assertStringContainsString('href="/news/switch-story/#comments">Continue to the article</a>', $verify->body);
        self::assertStringContainsString('href="/portal/login/?next=' . e(rawurlencode('/news/switch-story/comment/')) . '"', $verify->body);
    }

    public function testOnlyASafeArticlePathIsOfferedAsTheWayBack(): void
    {
        foreach (['//evil.example/news/x/', 'https://evil.example/', '/%2F%2Fevil.example/'] as $next) {
            $this->freshBrowser();
            $this->request('GET', '/portal/register/?next=' . rawurlencode($next));
            self::assertArrayNotHasKey(\Psc\Controllers\Portal\AuthController::RETURN_KEY, $_SESSION, $next);
        }

        // A portal page is carried for the sign-in but is not an article.
        $this->freshBrowser();
        $this->request('GET', '/portal/register/?next=' . rawurlencode('/portal/tickets/new/'));
        $this->register('nina@example.test');
        $verify = $this->confirmEmailLink('/portal/verify/?token=' . rawurlencode(self::tokenFrom($this->mailUrl('nina@example.test', 'verify-email'))));
        self::assertStringContainsString('href="/portal/login/?next=' . e(rawurlencode('/portal/tickets/new/')) . '"', $verify->body);
        self::assertStringNotContainsString('Continue to the article', $verify->body);

        $articleOf = [\Psc\Controllers\Portal\AuthController::class, 'articleOf'];
        self::assertSame('/news/audio/x-y/#comments', $articleOf('/news/audio/x-y/'));
        self::assertSame('/news/audio/x-y/#comments', $articleOf('/news/audio/x-y/comment/'));
        self::assertSame('/news/audio/x-y/#comments', $articleOf('/news/audio/x-y/comment/?utm_source=mail#form'));
        self::assertSame('/news/x-y/#comments', $articleOf('/news/x-y/'), 'the lasting address, which leads to the article');
        self::assertSame('/news/x-y/#comments', $articleOf('/news/x-y/comment/'));
        self::assertSame('/news/x-y/#comments', $articleOf('/news/x-y/#comments'));
        foreach (['/news/', '/news/category/video/', '/news/category/', '/news/go/3/', '/news/x/y/z/', '/portal/news/x/', '/news/X/', '/news/video/', '/news/video/page/2/', '/news/page/2/', '/news/rumours/x-y/'] as $path) {
            self::assertNull($articleOf($path), $path);
        }

        // A sign-in that goes through forgets the kept way back, so it is never offered later by surprise.
        $this->freshBrowser();
        $this->makeUser();
        $this->request('GET', '/portal/login/?next=' . rawurlencode('/news/old-story/comment/'));
        self::assertSame('/news/old-story/comment/', $_SESSION[\Psc\Controllers\Portal\AuthController::RETURN_KEY]);
        $this->post('/portal/login/', ['email' => 'client@example.test', 'password' => self::PASSWORD, 'next' => '/news/old-story/comment/']);
        self::assertArrayNotHasKey(\Psc\Controllers\Portal\AuthController::RETURN_KEY, $_SESSION);
    }
}
