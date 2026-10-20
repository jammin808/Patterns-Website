<?php

declare(strict_types=1);

namespace Psc\Controllers\Portal;

use Psc\Auth\Auth;
use Psc\Controllers\Controller;
use Psc\Http\Request;
use Psc\Http\Response;
use Psc\Support\Audit;

/**
 * Signing in and out, creating an account, confirming an email address and setting a password from a reset or
 * invite link. Every flow that takes an email address answers the same way whether or not it has an account; only
 * a correct password earns a message about the account's state (unconfirmed, waiting for approval, disabled).
 */
final class AuthController extends Controller
{
    /** Account states a correct password may be told about on the sign-in page. */
    private const LOGIN_NOTICES = ['unverified', 'approval', 'disabled'];

    private const REGISTRATION_MODES = ['open', 'approval', 'invite'];

    public function loginForm(Request $request): Response
    {
        $session = $this->app->session();
        $notice = $session->get('login_notice');
        $session->forget('login_notice');
        return $this->portal('portal/auth/login', [
            'next' => $this->safeNext($request->queryString('next')),
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
            return $this->portal('portal/auth/check-email', ['email' => $registered], 'Check your email');
        }
        return $this->portal('portal/auth/register', [
            'registration' => $this->registrationMode(),
            'old' => $session->takeOld(),
            'errors' => $this->takeErrors(),
            'minLength' => $this->app->config->int('security.password_min_length', 10),
        ], 'Create an account');
    }

    public function register(Request $request): Response
    {
        if ($this->registrationMode() === 'invite') {
            return $this->redirect('/portal/register/', 'info', 'Accounts are by invitation only. Please contact us if you need one.');
        }
        $input = [
            'name' => Auth::cleanLine($request->input('name')),
            'email' => trim($request->input('email')),
            'company' => Auth::cleanLine($request->input('company')),
            'password' => $request->input('password'),
            'marketing' => $request->input('marketing') === '1',
        ];
        $errors = $this->app->auth()->validateRegistration($input);
        if ($request->input('terms') !== '1') {
            $errors['terms'] = 'Please accept the terms of use to create an account.';
        }
        if ($errors !== []) {
            return $this->backWithErrors('/portal/register/', $request, $errors);
        }
        if (!$this->app->limiter()->hit('register-ip:' . $this->app->clientIp(), 10, 3600)) {
            return $this->backWithErrors('/portal/register/', $request, [], 'Several accounts have been created from here recently. Please wait an hour, then try again.');
        }
        $result = $this->app->auth()->register($input);
        if (!$result['ok']) {
            return $this->backWithErrors('/portal/register/', $request, $result['errors']);
        }
        // The same page whether the address was new or already had an account (that one gets a notice instead).
        $this->app->session()->set('registered_email', Auth::normaliseEmail($input['email']));
        return Response::redirect('/portal/register/', 303);
    }

    public function verify(Request $request): Response
    {
        $token = $request->queryString('token');
        $row = $token !== '' ? $this->app->auth()->verifyEmail($token) : null;
        $state = match ($row['status'] ?? null) {
            null => 'invalid',
            'active' => 'confirmed',
            'approval' => 'approval',
            default => 'disabled',
        };
        $titles = [
            'invalid' => 'This link no longer works',
            'confirmed' => 'Email address confirmed',
            'approval' => 'Email address confirmed',
            'disabled' => 'This account cannot sign in',
        ];
        return $this->portal('portal/auth/verify', [
            'state' => $state,
            'signedIn' => $this->app->auth()->user() !== null,
        ], $titles[$state]);
    }

    public function resend(Request $request): Response
    {
        $email = Auth::normaliseEmail($request->input('email'));
        $limiter = $this->app->limiter();
        if (filter_var($email, FILTER_VALIDATE_EMAIL)
            && $limiter->hit('verify-resend-ip:' . $this->app->clientIp(), 10, 3600)
            && $limiter->hit('verify-resend:' . $email, 3, 3600)) {
            $row = $this->app->auth()->findByEmail($email);
            if ($row !== null && $row['status'] === 'pending') {
                $this->app->auth()->sendVerification((int) $row['id']);
                Audit::log($this->app, (int) $row['id'], 'email.verification_resent');
            }
        }
        // One answer for every address, known or not, and for every rate-limited attempt.
        return $this->redirect('/portal/login/', 'info', 'If that address has an account waiting to be confirmed, a new link is on its way. It works for 48 hours.');
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
        ], 'Reset your password');
    }

    public function forgot(Request $request): Response
    {
        $email = trim($request->input('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            return $this->backWithErrors('/portal/forgot/', $request, ['email' => 'Enter the email address you use to sign in.']);
        }
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
