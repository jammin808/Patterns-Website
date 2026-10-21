<?php

declare(strict_types=1);

namespace Psc\Tests\Feature\Portal;

use Psc\Controllers\Controller;
use Psc\Controllers\Portal\AuthController;
use Psc\Http\Response;
use Psc\Support\FormGuard;
use Psc\Tests\TestCase;

/**
 * Every portal form a visitor or client sends: the bot defences on the three a stranger can use to have the site
 * send mail (sign-up, a reset link, a new confirmation link), and the feedback on all of them: an error summary that
 * links to each field or says why nothing was done, the input kept, the answer marked to take focus, and a button
 * that says it is working. Each test asserts the redirect, the rows and the emails, not only the words.
 */
final class FormsTest extends TestCase
{
    private const PASSWORD = 'correct horse battery';

    /** @return array<string, string> a complete sign-up */
    private static function signUp(array $over = []): array
    {
        return $over + ['name' => 'Nina Stage', 'email' => 'nina@example.test', 'company' => 'Cue Lights Ltd', 'password' => self::PASSWORD, 'terms' => '1'];
    }

    private static function assertRedirect(Response $r, string $location): void
    {
        self::assertSame(303, $r->status, 'status');
        self::assertSame($location, $r->getHeader('Location'));
    }

    private function users(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM users');
    }

    /** The one form on a page whose action is $action, opening tag to closing tag. */
    private static function form(string $html, string $action): string
    {
        self::assertSame(1, preg_match('#<form[^>]*action="' . preg_quote($action, '#') . '"[^>]*>.*?</form>#s', $html, $m), $action);
        return $m[0];
    }

    // ------------------------------------------------------------------ the defences

    public function testTheGuardedFormsCarryTheHoneypotAndASignedTime(): void
    {
        $pages = ['/portal/register/' => '/portal/register/', '/portal/forgot/' => '/portal/forgot/', '/portal/verify/resend/' => '/portal/verify/resend/', '/portal/verify/' => '/portal/verify/resend/'];
        foreach ($pages as $page => $action) {
            $form = self::form($this->request('GET', $page)->body, $action);
            self::assertMatchesRegularExpression('/<input type="hidden" name="_started" value="\d+\.[a-f0-9]{64}">/', $form, $page);
            self::assertMatchesRegularExpression('/<div class="u-visually-hidden" aria-hidden="true"><label for="[a-z-]+-website">Leave this field empty<\/label><input id="[a-z-]+-website" name="website" type="text" tabindex="-1" autocomplete="off" value=""><\/div>/', $form, $page);
            self::assertStringContainsString(' novalidate', $form, $page . ': the server\'s summary, not the browser\'s bubbles');
        }
    }

    /** A filled honeypot gets the page a person would, and nothing is stored or sent. */
    public function testAFilledHoneypotIsAnsweredAsIfItWorkedAndNothingHappens(): void
    {
        $this->makeUser('client@example.test');
        $this->makeUser('pending@example.test', self::PASSWORD, 'client', 'pending');
        self::assertRedirect($this->post('/portal/register/', self::signUp(['website' => 'https://spam.example']) + $this->filledIn('register')), '/portal/register/');
        self::assertStringContainsString('Check your email', $this->request('GET', '/portal/register/')->body);
        self::assertSame(2, $this->users(), 'no account is made');

        self::assertRedirect($this->post('/portal/forgot/', ['email' => 'client@example.test', 'website' => 'x'] + $this->filledIn('forgot')), '/portal/forgot/');
        self::assertStringContainsString('If that address has an account, a reset link is on its way.', $this->request('GET', '/portal/forgot/')->body);

        $resend = $this->post('/portal/verify/resend/', ['email' => 'pending@example.test', 'website' => 'x'] + $this->filledIn('resend'));
        self::assertRedirect($resend, '/portal/login/');
        self::assertSame([AuthController::RESEND_ANSWER], array_column($_SESSION['_flash'] ?? [], 'message'));

        self::assertSame([], $this->mails(), 'no email at all');
    }

    /**
     * Sooner than a person fills the form in (or with no good signed time): back to the form with the input kept
     * (never a password) and a request to press the button again; nothing is stored or sent. The form it comes back
     * to keeps the first showing's time.
     */
    public function testAQuickSendComesBackWithTheInputAndAsksForAnotherPress(): void
    {
        $this->makeUser('pending@example.test', self::PASSWORD, 'client', 'pending');
        $quick = $this->filledIn('register', 0);
        self::assertRedirect($this->post('/portal/register/', self::signUp() + $quick), '/portal/register/');
        self::assertSame(1, $this->users());
        $page = $this->request('GET', '/portal/register/')->body;
        self::assertStringContainsString('Your account is not created yet', $page);
        self::assertStringContainsString(AuthController::REGISTER_QUICK, $page);
        self::assertStringContainsString('value="Nina Stage"', $page);
        self::assertStringContainsString('value="nina@example.test"', $page);
        self::assertStringNotContainsString(self::PASSWORD, $page, 'never the password');
        self::assertStringContainsString('name="_started" value="' . $quick['_started'] . '"', $page, 'the clock is not restarted');

        $noTime = $this->post('/portal/forgot/', ['email' => 'pending@example.test']);
        self::assertRedirect($noTime, '/portal/forgot/');
        $page = $this->request('GET', '/portal/forgot/')->body;
        self::assertStringContainsString('The link is not sent yet', $page);
        self::assertStringContainsString(AuthController::FORGOT_QUICK, $page);
        self::assertStringContainsString('value="pending@example.test"', $page);

        // The one-button form on the sign-in page comes back on the form's own page, the address filled in.
        $resend = $this->post('/portal/verify/resend/', ['email' => 'pending@example.test'] + $this->filledIn('resend', 0));
        self::assertRedirect($resend, '/portal/verify/resend/');
        $page = $this->request('GET', '/portal/verify/resend/')->body;
        self::assertStringContainsString(AuthController::RESEND_QUICK, $page);
        self::assertMatchesRegularExpression('/id="resend-email"[^>]*value="pending@example\.test"/', $page);

        self::assertSame([], $this->mails());
        self::assertSame(FormGuard::MIN_SECONDS['forgot'], FormGuard::minSeconds('forgot'));
    }

    /**
     * Sign-ups are limited per network address (said plainly) and per email address (silently: the answer is the
     * same "check your email" page, so the limit says nothing about whether the address has an account).
     */
    public function testSignUpsAreLimitedPerAddressWithoutSayingWhetherItHasAnAccount(): void
    {
        $pages = [];
        for ($i = 0; $i < AuthController::REGISTER_PER_ADDRESS_PER_HOUR + 2; $i++) {
            self::assertRedirect($this->post('/portal/register/', self::signUp() + $this->filledIn('register')), '/portal/register/');
            $pages[] = self::withoutFormTimes($this->request('GET', '/portal/register/')->body);
        }
        self::assertCount(1, array_unique($pages), 'every attempt gets the same page');
        $mails = array_column(array_filter($this->mails(), static fn (array $m): bool => $m['to'] === 'nina@example.test'), 'template');
        self::assertSame(['verify-email', 'account-exists', 'account-exists'], array_values($mails), 'past three an hour, nothing more is sent to the address');
        self::assertSame(1, $this->users());

        // A refused attempt (an error on the form) is not counted.
        $this->post('/portal/register/', self::signUp(['email' => 'new@example.test', 'terms' => '']) + $this->filledIn('register'));
        $this->post('/portal/register/', self::signUp(['email' => 'new@example.test']) + $this->filledIn('register'));
        self::assertSame(2, $this->users());
    }

    public function testTheForgotFormSaysWhatIsWrongWithTheAddress(): void
    {
        $this->post('/portal/forgot/', ['email' => ''] + $this->filledIn('forgot', 0));
        $page = $this->request('GET', '/portal/forgot/')->body;
        self::assertStringContainsString('<li><a href="#forgot-email">Enter the email address you use to sign in.</a></li>', $page);

        $this->post('/portal/forgot/', ['email' => 'nina.example.test'] + $this->filledIn('forgot'));
        $page = $this->request('GET', '/portal/forgot/')->body;
        self::assertStringContainsString('<li><a href="#forgot-email">' . Controller::EMAIL_FORMAT . '</a></li>', $page);
        self::assertMatchesRegularExpression('/id="forgot-email"[^>]*value="nina\.example\.test"[^>]*aria-invalid="true" aria-describedby="forgot-email-error"/', $page);

        $this->post('/portal/forgot/', ['email' => 'nina@example.test'] + $this->filledIn('forgot'));
        self::assertStringContainsString('<div class="pt-alert pt-alert--success" role="status" tabindex="-1" data-feedback>', $this->request('GET', '/portal/forgot/')->body, 'the answer takes focus');
    }

    // ------------------------------------------------------------------ the feedback

    public function testASignInThatFailsSaysSoAtTheTopOfTheForm(): void
    {
        $this->makeUser();
        $this->post('/portal/login/', ['email' => '', 'password' => '']);
        $page = $this->request('GET', '/portal/login/')->body;
        self::assertStringContainsString('Check 2 fields and try again', $page);
        self::assertStringContainsString('<li><a href="#login-email">Enter your email address.</a></li>', $page);
        self::assertStringContainsString('<li><a href="#login-password">Enter your password.</a></li>', $page);

        $this->post('/portal/login/', ['email' => 'client@example.test', 'password' => 'not it at all']);
        $page = $this->request('GET', '/portal/login/')->body;
        $form = self::form($page, '/portal/login/');
        self::assertMatchesRegularExpression('/<div class="c-callout c-callout--danger c-error-summary" id="error-summary" data-error-summary tabindex="-1" role="alert" aria-labelledby="error-summary-summary-title">/', $form);
        self::assertStringContainsString('You are not signed in', $form);
        self::assertStringContainsString('That email address and password do not match an account.', $form);
        self::assertStringContainsString('value="client@example.test"', $form, 'the address is kept');
        self::assertStringContainsString('data-busy="Signing in…"', $form);
    }

    public function testTheSignUpSummaryLinksToEachField(): void
    {
        // At once, as a person checking what the form needs would: the fields come first, not the clock.
        $this->post('/portal/register/', ['name' => '', 'email' => 'nope', 'company' => '', 'password' => 'short', 'terms' => ''] + $this->filledIn('register', 0));
        $form = self::form($this->request('GET', '/portal/register/')->body, '/portal/register/');
        self::assertStringNotContainsString(AuthController::REGISTER_QUICK, $form);
        self::assertStringContainsString('Check 4 fields before creating your account', $form);
        foreach (['register-name', 'register-email', 'register-password', 'register-terms'] as $id) {
            self::assertStringContainsString('<li><a href="#' . $id . '">', $form, $id);
            self::assertMatchesRegularExpression('/id="' . $id . '"[^>]*aria-invalid="true"/', $form, $id);
        }
        self::assertLessThan(strpos($form, 'id="register-name"'), strpos($form, 'data-error-summary'), 'the summary comes first');
        self::assertStringContainsString('data-busy="Creating account…"', $form);
    }

    /** Every flash is the answer to what was just done: marked to take focus on load. */
    public function testFlashMessagesAreMarkedToTakeFocus(): void
    {
        $this->makeUser();
        $this->signIn();
        $this->post('/portal/logout/', []);
        self::assertStringContainsString('<div class="pt-alert pt-alert--success" role="status" tabindex="-1" data-feedback>You are signed out.</div>', $this->request('GET', '/portal/login/')->body);
    }

    /**
     * The check-your-email page after a sign-up is the answer: marked to take focus, with the address. A request
     * from its one-button form for a new link carries the guard's fields too.
     */
    public function testTheCheckYourEmailPageIsTheAnswer(): void
    {
        $this->post('/portal/register/', self::signUp() + $this->filledIn('register'));
        $page = $this->request('GET', '/portal/register/')->body;
        self::assertStringContainsString('<div class="pt-alert pt-alert--success" role="status" tabindex="-1" data-feedback>', $page);
        self::assertStringContainsString('<strong>nina@example.test</strong>', $page);
        $form = self::form($page, '/portal/verify/resend/');
        self::assertStringContainsString('name="_started"', $form);
        self::assertStringContainsString('id="check-resend-website"', $form);
    }

    public function testTicketAndReplySummariesLinkToTheirFieldsAndSayWhyALimitRefused(): void
    {
        $id = $this->makeUser();
        $this->signIn();
        $this->post('/portal/tickets/', ['type' => 'bug', 'title' => 'Hi', 'what_happened' => 'short']);
        $form = self::form($this->request('GET', '/portal/tickets/new/?type=bug')->body, '/portal/tickets/');
        self::assertStringContainsString('Check 4 fields before sending', $form);
        foreach (['f-title', 'f-product', 'severity-low', 'f-what_happened'] as $target) {
            self::assertStringContainsString('<li><a href="#' . $target . '">', $form, $target);
            self::assertStringContainsString('id="' . $target . '"', $form, $target . ' is on the page');
        }
        self::assertStringContainsString('data-busy="Sending…"', $form);

        $ticket = $this->db->insert('tickets', ['user_id' => $id, 'type' => 'question', 'product' => 'stagewatch', 'title' => 'How do I pair?', 'body' => 'How do I pair two machines?', 'status' => 'open', 'visibility' => 'private', 'last_activity_at' => time(), 'created_at' => time(), 'updated_at' => time()]);
        $this->post("/portal/tickets/{$ticket}/comments/", ['form' => 'comment', 'body' => '']);
        $reply = self::form($this->request('GET', "/portal/tickets/{$ticket}/")->body, "/portal/tickets/{$ticket}/comments/");
        self::assertStringContainsString('<li><a href="#reply-body">Write your reply.</a></li>', $reply);

        for ($i = 0; $i < 60; $i++) {
            $this->app->limiter()->hits('ticket-comment:' . $id, 3600);
        }
        $this->post("/portal/tickets/{$ticket}/comments/", ['form' => 'comment', 'body' => 'One reply too many this hour.']);
        $page = $this->request('GET', "/portal/tickets/{$ticket}/")->body;
        $reply = self::form($page, "/portal/tickets/{$ticket}/comments/");
        self::assertStringContainsString('Your reply was not posted', $reply);
        self::assertStringContainsString('You have sent a lot of replies in the last hour.', $reply);
        self::assertStringContainsString('One reply too many this hour.</textarea>', $reply, 'the reply is kept');
        self::assertStringContainsString('data-busy="Posting…"', $reply);
    }

    public function testTheLicenceRequestSummaryLinksToTheHardwareIds(): void
    {
        $this->makeUser();
        $this->signIn();
        $this->post('/portal/licences/request/', ['product' => 'stagewatch', 'tier' => 'production', 'hardware_id_1' => '', 'hardware_id_2' => 'x']);
        $form = self::form($this->request('GET', '/portal/licences/request/?product=stagewatch&tier=production')->body, '/portal/licences/request/');
        self::assertStringContainsString('Check 2 fields before sending', $form);
        foreach (['f-hardware-id-1', 'f-hardware-id-2'] as $target) {
            self::assertStringContainsString('<li><a href="#' . $target . '">', $form, $target);
            self::assertStringContainsString('id="' . $target . '"', $form, $target);
        }
        self::assertStringContainsString(' novalidate', $form);
    }

    /** The account page has three forms: a refusal shows in the summary of the one that was sent, and only there. */
    public function testTheAccountPageSaysWhichFormWasRefused(): void
    {
        $id = $this->makeUser();
        $this->signIn();
        for ($i = 0; $i < 10; $i++) {
            $this->app->limiter()->hits('account-password:' . $id, 3600);
        }
        $r = $this->post('/portal/account/password/', ['form' => 'password', 'current_password' => self::PASSWORD, 'new_password' => 'lantern quay rehearsal', 'new_password_confirm' => 'lantern quay rehearsal']);
        self::assertRedirect($r, '/portal/account/#password');
        $page = $this->request('GET', '/portal/account/')->body;
        self::assertSame(1, substr_count($page, 'data-error-summary'));
        $password = self::form($page, '/portal/account/password/');
        self::assertStringContainsString('Your password was not changed', $password);
        self::assertStringContainsString('Too many password changes in a short time.', $password);
        self::assertStringNotContainsString('data-error-summary', self::form($page, '/portal/account/profile/'));

        $this->post('/portal/account/delete/', ['form' => 'delete', 'delete_password' => 'wrong', 'delete_confirm' => 'delete']);
        $page = $this->request('GET', '/portal/account/')->body;
        $delete = self::form($page, '/portal/account/delete/');
        self::assertStringContainsString('Check 2 fields to delete your account', $delete);
        self::assertStringContainsString('<li><a href="#delete-password">', $delete);
        self::assertStringContainsString('<li><a href="#delete-confirm">', $delete);
        self::assertStringContainsString('value="delete"', $delete, 'the typed word is kept');
        self::assertStringContainsString('data-busy="Deleting…"', $delete);
    }

    /** Every button that sends one of the forms says what it is doing while the next page loads. */
    public function testEverySendingButtonHasWordsForWhileItWorks(): void
    {
        $pages = [
            '/contact/' => '/contact/#form',
            '/portal/login/' => '/portal/login/',
            '/portal/register/' => '/portal/register/',
            '/portal/forgot/' => '/portal/forgot/',
            '/portal/verify/resend/' => '/portal/verify/resend/',
        ];
        foreach ($pages as $page => $action) {
            self::assertMatchesRegularExpression('/<button[^>]*type="submit"[^>]*data-busy="[^"]+…"/', self::form($this->request('GET', $page)->body, $action), $page);
        }
    }
}
