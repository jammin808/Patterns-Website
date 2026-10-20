<?php

declare(strict_types=1);

namespace Psc\Tests\Feature\Portal;

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
        ]);
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

        $verify = $this->request('GET', '/portal/verify/?token=' . rawurlencode(self::tokenFrom($url)));
        self::assertSame(200, $verify->status);
        self::assertStringContainsString('Email address confirmed', $verify->body);
        self::assertStringContainsString('href="/portal/login/"', $verify->body);
        self::assertNull(self::signedInId(), 'confirming does not sign anyone in');
        $user = $this->db->one('SELECT status, email_verified_at FROM users WHERE id = ?', [(int) $user['id']]);
        self::assertSame('active', $user['status']);
        self::assertNotNull($user['email_verified_at']);

        $again = $this->request('GET', '/portal/verify/?token=' . rawurlencode(self::tokenFrom($url)));
        self::assertStringContainsString('This link no longer works', $again->body, 'a confirmation link works once');

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
            str_replace('fresh@example.test', 'EMAIL', $freshPage->body),
            str_replace('taken@example.test', 'EMAIL', $takenPage->body),
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

    public function testApprovalModeKeepsTheAccountWaitingAndEmailsTheAdministrator(): void
    {
        $this->bootApp(['security' => ['registration' => 'approval']]);
        self::assertStringContainsString('checked by a person', $this->request('GET', '/portal/register/')->body);

        $this->register('nina@example.test');
        $url = $this->mailUrl('nina@example.test', 'verify-email');
        $verify = $this->request('GET', '/portal/verify/?token=' . rawurlencode(self::tokenFrom($url)));
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
        $this->post('/portal/forgot/', ['email' => 'nina@example.test']);
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
        $this->post('/portal/forgot/', ['email' => 'ivy@example.test']);
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

        $resend = $this->post('/portal/verify/resend/', ['email' => 'pending@example.test']);
        self::assertRedirect($resend, '/portal/login/');
        $url = $this->mailUrl('pending@example.test', 'verify-email');
        $this->request('GET', '/portal/verify/?token=' . rawurlencode(self::tokenFrom($url)));
        self::assertSame('active', $this->db->value('SELECT status FROM users WHERE id = ?', [$id]));
        self::assertRedirect($this->signIn('pending@example.test', self::PASSWORD), '/portal/');
    }

    public function testResendAnswersTheSameForEveryAddressAndIsRateLimited(): void
    {
        $this->makeUser('pending@example.test', self::PASSWORD, 'client', 'pending');
        $this->makeUser('active@example.test');
        $answers = [];
        foreach (['pending@example.test', 'active@example.test', 'nobody@example.test', 'not-an-email'] as $email) {
            $_SESSION['_flash'] = [];
            $r = $this->post('/portal/verify/resend/', ['email' => $email]);
            $answers[] = [$r->status, $r->getHeader('Location'), self::flashes()];
        }
        self::assertCount(1, array_unique(array_map('serialize', $answers)), 'the same answer for every address');
        self::assertCount(1, $this->mails(), 'only the unconfirmed account gets a link');

        for ($i = 0; $i < 4; $i++) {
            $this->post('/portal/verify/resend/', ['email' => 'pending@example.test']);
        }
        $sent = array_filter($this->mails(), static fn (array $m): bool => $m['to'] === 'pending@example.test');
        self::assertCount(3, $sent, 'three links an hour for one address');
    }

    public function testNextIsKeptForSameSitePathsAndNeutralisedOtherwise(): void
    {
        $this->makeUser();
        $guarded = $this->request('GET', '/portal/account/');
        self::assertRedirect($guarded, '/portal/login/?next=' . rawurlencode('/portal/account/'), 302);

        $page = $this->request('GET', '/portal/login/?next=' . rawurlencode('//evil.example/steal'));
        self::assertSame(200, $page->status);
        self::assertStringNotContainsString('evil.example', $page->body);

        foreach (['//evil.example', 'https://evil.example/', '/\\evil.example', "/portal/\r\nX: y"] as $next) {
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

        $known = $this->post('/portal/forgot/', ['email' => 'client@example.test']);
        self::assertRedirect($known, '/portal/forgot/');
        $knownPage = $this->request('GET', '/portal/forgot/')->body;
        $unknown = $this->post('/portal/forgot/', ['email' => 'nobody@example.test']);
        self::assertRedirect($unknown, '/portal/forgot/');
        $unknownPage = $this->request('GET', '/portal/forgot/')->body;
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
        $this->post('/portal/forgot/', ['email' => 'client@example.test']);
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
        self::assertRedirect($r, '/portal/account/#details');
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
        self::assertRedirect($r, '/portal/account/#details');
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
        self::assertRedirect($wrong, '/portal/account/#password');
        self::assertSame($before, $this->hashOf($id));
        $page = $this->request('GET', '/portal/account/');
        self::assertStringContainsString('That is not your current password.', $page->body);
        self::assertMatchesRegularExpression('/id="password-current"[^>]*aria-invalid="true"/', $page->body);
        self::assertStringNotContainsString('not it at all', $page->body);

        $mismatch = $this->post('/portal/account/password/', ['current_password' => self::PASSWORD, 'new_password' => self::NEW_PASSWORD, 'new_password_confirm' => 'something else']);
        self::assertRedirect($mismatch, '/portal/account/#password');
        self::assertSame($before, $this->hashOf($id));

        $ok = $this->post('/portal/account/password/', ['current_password' => self::PASSWORD, 'new_password' => self::NEW_PASSWORD, 'new_password_confirm' => self::NEW_PASSWORD]);
        self::assertRedirect($ok, '/portal/account/#password');
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
        self::assertRedirect($wrongPassword, '/portal/account/#delete');
        $wrongWord = $this->post('/portal/account/delete/', ['delete_password' => self::PASSWORD, 'delete_confirm' => 'delete']);
        self::assertRedirect($wrongWord, '/portal/account/#delete');
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
        $this->post('/portal/forgot/', ['email' => 'deleted-' . $id . '@deleted.invalid']);
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
        $this->post('/portal/verify/resend/', ['email' => 'ivy@example.test']);
        $verify = $this->request('GET', '/portal/verify/?token=' . rawurlencode(self::tokenFrom($this->mailUrl('ivy@example.test', 'verify-email'))));
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
        $verify = $this->request('GET', '/portal/verify/?token=' . rawurlencode($token));
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
            $pages[] = $this->request('GET', '/portal/register/')->body;
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
        $this->post('/portal/verify/resend/', ['email' => 'stranger@example.test']);
        $this->post('/portal/forgot/', ['email' => 'stranger@example.test']);
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
        $this->post('/portal/forgot/', ['email' => 'known@example.test']);
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
        $this->post('/portal/forgot/', ['email' => 'ivy@example.test']);
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
        $this->post('/portal/forgot/', ['email' => 'client@example.test']);
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
        $this->post('/portal/forgot/', ['email' => 'nina@example.test']);
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
        self::assertRedirect($r, '/portal/account/#password');
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
        self::assertRedirect($r, '/portal/account/#details');
        self::assertSame('Test Client', $this->db->value('SELECT name FROM users WHERE id = ?', [$id]), 'not UTF-8: refused, nothing stored');

        $this->post('/portal/account/profile/', ['name' => "Nina\r\n\tStage\x00", 'company' => "Front\x07 of\n\nHouse "]);
        self::assertSame(['name' => 'Nina Stage', 'company' => 'Front of House'], $this->db->one('SELECT name, company FROM users WHERE id = ?', [$id]));

        // A record holding bytes that are not UTF-8 (written before these checks, or imported) is still exported.
        $this->db->insert('tickets', ['user_id' => $id, 'type' => 'bug', 'product' => 'patterns', 'title' => "Bad \xff bytes", 'body' => 'Body', 'last_activity_at' => time(), 'created_at' => time(), 'updated_at' => time()]);
        $export = $this->request('GET', '/portal/account/export/');
        self::assertSame(200, $export->status);
        $data = json_decode($export->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame("Bad \u{FFFD} bytes", $data['tickets'][0]['title']);

        $this->freshBrowser();
        $this->register('line@example.test', ['name' => "Line\nBreak", 'company' => "Two\r\nLines"]);
        self::assertSame(['name' => 'Line Break', 'company' => 'Two Lines'], $this->db->one('SELECT name, company FROM users WHERE email = ?', ['line@example.test']));
    }
}
