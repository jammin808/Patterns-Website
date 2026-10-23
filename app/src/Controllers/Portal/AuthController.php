<?php

declare(strict_types=1);

namespace Psc\Controllers\Portal;

use Psc\Auth\Auth;
use Psc\Controllers\Controller;
use Psc\Http\Request;
use Psc\Http\Response;
use Psc\News\Paths;
use Psc\Support\Audit;
use Psc\Support\FormGuard;

/**
 * Signing in and out, creating an account, confirming an email address and setting a password from a reset or
 * invite link. Every flow that takes an email address answers the same way whether or not it has an account; only
 * a correct password earns a message about the account's state (unconfirmed, waiting for approval, disabled).
 *
 * The three forms a stranger can use to have the site send mail (sign-up, a reset link, a new confirmation link)
 * carry FormGuard's honeypot and signed showing time, and are rate limited per network address and per email
 * address. A filled honeypot gets the answer a person would and nothing is done; a send faster than a person fills
 * the form in comes back with the input kept and a request to press the button again.
 *
 * Where the person was going (a safe ?next= path, such as an article's comments) is kept in the session from the
 * sign-in and sign-up pages through the check-your-email page to the confirmation link, so the confirmation page
 * can take them back: its sign-in returns there, and an article gets "Continue to the article". A sign-in that
 * goes through uses its own next and forgets the kept one.
 */
final class AuthController extends Controller
{
    /** Account states a correct password may be told about on the sign-in page. */
    private const LOGIN_NOTICES = ['unverified', 'approval', 'disabled'];

    /** A password is never kept for a form shown again, so the sign-up's request says to type it once more. */
    public const REGISTER_QUICK = 'That was quick. Please type your password again, then press Create account.';
    public const FORGOT_QUICK = 'That was quick. Please check the address, then press Send reset link again.';
    public const RESEND_QUICK = 'That was quick. Please check the address, then press Send a new link again.';

    /** The one answer to a request for a new confirmation link, whatever the address. */
    public const RESEND_ANSWER = 'If that address has an account waiting to be confirmed, a new link is on its way. It works for 48 hours.';

    /**
     * Sign-ups accepted for one email address in an hour. Past it the answer is the same "check your email" page and
     * nothing is done, so the limit says nothing about whether the address has an account.
     */
    public const REGISTER_PER_ADDRESS_PER_HOUR = 3;

    /** Sign-ups accepted from one network address in an hour. */
    public const REGISTER_PER_IP_PER_HOUR = 10;

    private const REGISTRATION_MODES = ['open', 'approval', 'invite'];

    /** The session key holding where the person was going before signing in or creating an account. */
    public const RETURN_KEY = 'return_to';

    public function loginForm(Request $request): Response
    {
        $session = $this->app->session();
        $notice = $session->get('login_notice');
        $session->forget('login_notice');
        $next = $this->safeNext($request->queryString('next'));
        // Kept, so the portal's Create an account switch (which carries no next) still leads back there.
        if ($next !== '/portal/') {
            $session->set(self::RETURN_KEY, $next);
        }
        return $this->portal('portal/auth/login', [
            'next' => $next,
            'notice' => is_string($notice) && in_array($notice, self::LOGIN_NOTICES, true) ? $notice : null,
            'old' => $session->takeOld(),
            'errors' => $this->takeErrors(),
            'registration' => $this->registrationMode(),
        ], 'Sign in');
    }

    public function login(Request $request): Response
    {
        $next = $this->safeNext($request->input('next'));
        $back = '/portal/login/' . ($next !== '/portal/' ? '?next=' . rawurlencode($next) : '');
        $email = trim($request->input('email'));
        $password = $request->input('password');
        $errors = [];
        if ($email === '') {
            $errors['email'] = 'Enter your email address.';
        }
        if ($password === '') {
            $errors['password'] = 'Enter your password.';
        }
        if ($errors !== []) {
            return $this->backWithErrors($back, $request, $errors);
        }
        $result = $this->app->auth()->attempt($email, $password, $request->input('remember') === '1');
        switch ($result['reason']) {
            case 'ok':
                $this->app->session()->forget(self::RETURN_KEY);
                return $this->redirect($next);
            case 'throttled':
                $minutes = max(1, (int) ceil($this->app->config->int('security.login_window_seconds', 900) / 60));
                return $this->backWithErrors($back, $request, [], "Too many sign-in attempts. Please wait {$minutes} minutes, then try again.");
            case 'unverified':
            case 'approval':
            case 'disabled':
                // The page explains the state itself (and, when unconfirmed, offers a new link), so no flash.
                $session = $this->app->session();
                $session->keepOld(['email' => $email, 'remember' => $request->input('remember') === '1' ? '1' : '']);
                $session->set('login_notice', $result['reason']);
                return Response::redirect($back, 303);
            default:
                return $this->backWithErrors($back, $request, [], 'That email address and password do not match an account. Check both and try again.');
        }
    }

    public function registerForm(Request $request): Response
    {
        $session = $this->app->session();
        // Straight after a sign-up (post, redirect, get): the check-your-email page, shown once, so reloading it
        // never sends the form again.
        $registered = $session->get('registered_email');
        if (is_string($registered) && $registered !== '') {
            $session->forget('registered_email');
            $kept = $session->get(self::RETURN_KEY);
            return $this->portal('portal/auth/check-email', [
                'email' => $registered,
                'article' => is_string($kept) ? self::articleOf($kept) : null,
            ], 'Check your email');
        }
        $next = $this->safeNext($request->queryString('next'), '');
        if ($next !== '' && $next !== '/portal/') {
            $session->set(self::RETURN_KEY, $next);
        }
        return $this->portal('portal/auth/register', [
            'registration' => $this->registrationMode(),
            'old' => $session->takeOld(),
            'errors' => $this->takeErrors(),
            'quick' => $this->takeQuick('register'),
            'minLength' => $this->app->config->int('security.password_min_length', 10),
        ], 'Create an account');
    }

    public function register(Request $request): Response
    {
        if ($this->registrationMode() === 'invite') {
            return $this->redirect('/portal/register/', 'info', 'Accounts are by invitation only. Please contact us if you need one.');
        }
        $verdict = (new FormGuard($this->app))->check('register', $request);
        if ($verdict === FormGuard::BOT) {
            // The page a sign-up gets, and nothing is stored or sent.
            return $this->checkYourEmail($request->input('email'));
        }
        $input = [
            'name' => Auth::cleanLine($request->input('name')),
            'email' => trim($request->input('email')),
            'company' => Auth::cleanLine($request->input('company')),
            'password' => $request->input('password'),
            'marketing' => $request->input('marketing') === '1',
            'digest' => $request->input('digest') === '1',
        ];
        $errors = $this->app->auth()->validateRegistration($input);
        if ($request->input('terms') !== '1') {
            $errors['terms'] = 'Please accept the terms of use to create an account.';
        }
        if ($errors !== []) {
            return $this->backWithErrors('/portal/register/', $request, $errors);
        }
        // Only a sign-up that would go through is timed: what to fix is said first, however fast the send.
        if ($verdict === FormGuard::QUICK) {
            return $this->tooQuick('/portal/register/', $request, 'register', self::REGISTER_QUICK);
        }
        $limiter = $this->app->limiter();
        if (!$limiter->hit('register-ip:' . $this->app->clientIp(), self::REGISTER_PER_IP_PER_HOUR, 3600)) {
            return $this->backWithErrors('/portal/register/', $request, [], 'Several accounts have been created from here recently. Please wait an hour, then try again.');
        }
        // Counted before the password is hashed, so a flood at one address costs neither mail nor work.
        if (!$limiter->hit('register-addr:' . Auth::mailboxKey($input['email']), self::REGISTER_PER_ADDRESS_PER_HOUR, 3600)) {
            return $this->checkYourEmail($input['email']);
        }
        $result = $this->app->auth()->register($input);
        if (!$result['ok']) {
            return $this->backWithErrors('/portal/register/', $request, $result['errors']);
        }
        // The same page whether the address was new or already had an account (that one gets a notice instead).
        return $this->checkYourEmail($input['email']);
    }

    /** The "check your email" page after a sign-up: the one answer, whatever happened to the address. */
    private function checkYourEmail(string $email): Response
    {
        $this->app->session()->set('registered_email', Auth::normaliseEmail($email));
        return Response::redirect('/portal/register/', 303);
    }

    /**
     * GET /portal/verify/?token=: the email check's link opens a page with one button, and only its POST confirms
     * the address. Link scanners open every link in an email before a person does, so opening it changes nothing:
     * a proven address is a person's act, which the account page's digest tick relies on.
     */
    public function verifyPage(Request $request): Response
    {
        $token = $request->queryString('token');
        if ($token === '' || !$this->app->auth()->peekToken($token, 'verify')) {
            return $this->verifyResult('invalid');
        }
        return $this->portal('portal/auth/verify', [
            'state' => 'ask',
            'signedIn' => $this->app->auth()->user() !== null,
            'action' => '/portal/verify/?token=' . rawurlencode($token),
        ], 'Confirm your email');
    }

    /** POST /portal/verify/?token=: the button. The token is spent and the account moves on. */
    public function verify(Request $request): Response
    {
        $token = $request->queryString('token');
        $row = $token !== '' ? $this->app->auth()->verifyEmail($token) : null;
        return $this->verifyResult(match ($row['status'] ?? null) {
            null => 'invalid',
            'active' => 'confirmed',
            'approval' => 'approval',
            default => 'disabled',
        });
    }

    /** The email check's result page in one of its states. */
    private function verifyResult(string $state): Response
    {
        $titles = [
            'invalid' => 'This link no longer works',
            'confirmed' => 'Email address confirmed',
            'approval' => 'Email address confirmed',
            'disabled' => 'This account cannot sign in',
        ];
        // Where the person was going before signing up, offered once the address is confirmed (only in the browser
        // that signed up: the session holds it).
        $next = null;
        if ($state !== 'invalid') {
            $session = $this->app->session();
            $kept = $session->get(self::RETURN_KEY);
            $session->forget(self::RETURN_KEY);
            $next = is_string($kept) && $this->safeNext($kept, '') !== '' ? $kept : null;
        }
        return $this->portal('portal/auth/verify', [
            'state' => $state,
            'signedIn' => $this->app->auth()->user() !== null,
            'next' => $state === 'confirmed' ? $next : null,
            'article' => $state === 'confirmed' && $next !== null ? self::articleOf($next) : null,
        ], $titles[$state]);
    }

    /**
     * The article a return path belongs to, at its comments (/news/{category}/{slug}/#comments, or the lasting
     * /news/{slug}/#comments for an address without its category, which leads there), or null when the path is not an
     * article's: an article, its comment page, or either with a query or a fragment (Paths::parse()).
     */
    public static function articleOf(string $next): ?string
    {
        $parts = preg_split('/[?#]/', $next, 2);
        $found = Paths::parse((string) ($parts[0] ?? ''));
        if ($found === null) {
            return null;
        }
        return Paths::article(['slug' => $found['slug'], 'category' => $found['category'] ?? '']) . '#comments';
    }

    /**
     * The request for a new confirmation link on a page of its own: where a refused request comes back to (the form
     * also sits on the sign-in, check-your-email and expired-link pages).
     */
    public function resendForm(Request $request): Response
    {
        $session = $this->app->session();
        return $this->portal('portal/auth/resend', [
            'old' => $session->takeOld(),
            'errors' => $this->takeErrors(),
            'quick' => $this->takeQuick('resend'),
        ], 'New confirmation link');
    }

    public function resend(Request $request): Response
    {
        $verdict = (new FormGuard($this->app))->check('resend', $request);
        if ($verdict === FormGuard::BOT) {
            return $this->redirect('/portal/login/', 'info', self::RESEND_ANSWER);
        }
        $email = Auth::normaliseEmail($request->input('email'));
        // Only the form of the address is judged here, never whether it has an account.
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            return $this->backWithErrors('/portal/verify/resend/', $request, ['email' => $email === '' ? 'Enter the email address you created your account with.' : self::EMAIL_FORMAT]);
        }
        if ($verdict === FormGuard::QUICK) {
            return $this->tooQuick('/portal/verify/resend/', $request, 'resend', self::RESEND_QUICK);
        }
        $limiter = $this->app->limiter();
        if ($limiter->hit('verify-resend-ip:' . $this->app->clientIp(), 10, 3600)
            && $limiter->hit('verify-resend:' . Auth::mailboxKey($email), 3, 3600)) {
            $row = $this->app->auth()->findByEmail($email);
            if ($row !== null && $row['status'] === 'pending') {
                $this->app->auth()->sendVerification((int) $row['id']);
                Audit::log($this->app, (int) $row['id'], 'email.verification_resent');
            }
        }
        // One answer for every address, known or not, and for every rate-limited attempt.
        return $this->redirect('/portal/login/', 'info', self::RESEND_ANSWER);
    }

    public function forgotForm(Request $request): Response
    {
        $session = $this->app->session();
        $sent = (bool) $session->get('forgot_sent', false);
        $session->forget('forgot_sent');
        return $this->portal('portal/auth/forgot', [
            'sent' => $sent,
            'old' => $session->takeOld(),
            'errors' => $this->takeErrors(),
            'quick' => $this->takeQuick('forgot'),
        ], 'Reset your password');
    }

    public function forgot(Request $request): Response
    {
        $verdict = (new FormGuard($this->app))->check('forgot', $request);
        if ($verdict === FormGuard::BOT) {
            // The answer every address gets, and nothing is sent.
            $this->app->session()->set('forgot_sent', true);
            return Response::redirect('/portal/forgot/', 303);
        }
        $email = trim($request->input('email'));
        if ($email === '') {
            return $this->backWithErrors('/portal/forgot/', $request, ['email' => 'Enter the email address you use to sign in.']);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            return $this->backWithErrors('/portal/forgot/', $request, ['email' => self::EMAIL_FORMAT]);
        }
        if ($verdict === FormGuard::QUICK) {
            return $this->tooQuick('/portal/forgot/', $request, 'forgot', self::FORGOT_QUICK);
        }
        // Rate limited per address and per network address inside requestReset(), silently: the answer is the same.
        $this->app->auth()->requestReset($email);
        $this->app->session()->set('forgot_sent', true);
        return Response::redirect('/portal/forgot/', 303);
    }

    public function resetForm(Request $request): Response
    {
        $session = $this->app->session();
        $invite = $request->queryString('invite') === '1';
        $purpose = $invite ? 'invite' : 'reset';
        $token = $request->queryString('token');
        $valid = $token !== '' && $this->app->auth()->peekToken($token, $purpose);
        return $this->portal('portal/auth/reset', [
            'valid' => $valid,
            'invite' => $invite,
            'purpose' => $purpose,
            'token' => $valid ? $token : '',
            'errors' => $this->takeErrors(),
            'minLength' => $this->app->config->int('security.password_min_length', 10),
        ], $valid ? ($invite ? 'Set your password' : 'Choose a new password') : 'This link no longer works');
    }

    public function reset(Request $request): Response
    {
        $purpose = $request->input('purpose');
        $token = $request->input('token');
        // A real token is "selector.validator" in hex, well under 128 characters.
        if (!in_array($purpose, ['reset', 'invite'], true) || $token === '' || strlen($token) > 128) {
            // The page without a good token explains that the link no longer works and offers a new one.
            return Response::redirect('/portal/reset/', 303);
        }
        $invite = $purpose === 'invite';
        $back = '/portal/reset/?token=' . rawurlencode($token) . ($invite ? '&invite=1' : '');
        $password = $request->input('password');
        if ($password !== $request->input('password_confirm')) {
            return $this->backWithErrors($back, $request, ['password_confirm' => 'The two passwords do not match. Type the same password in both boxes.']);
        }
        $result = $this->app->auth()->resetPassword($token, $password, $purpose);
        if (!$result['ok']) {
            if (isset($result['errors']['token'])) {
                return Response::redirect('/portal/reset/' . ($invite ? '?invite=1' : ''), 303);
            }
            return $this->backWithErrors($back, $request, $result['errors']);
        }
        return $this->redirect('/portal/login/', 'success', $invite
            ? 'Your password is set. Sign in to get started.'
            : 'Your password is changed. Sign in with your new password.');
    }

    public function logout(Request $request): Response
    {
        $this->app->auth()->logout();
        return $this->redirect('/portal/login/', 'success', 'You are signed out.');
    }

    private function registrationMode(): string
    {
        $mode = $this->app->config->string('security.registration', 'open');
        // An unrecognised setting fails closed: nobody can create an account until it is fixed.
        return in_array($mode, self::REGISTRATION_MODES, true) ? $mode : 'invite';
    }
}
