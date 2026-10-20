<?php

declare(strict_types=1);

namespace Psc\Auth;

use Psc\App;
use Psc\Support\Audit;

/**
 * Client accounts. Passwords are Argon2id (bcrypt where Argon2 is missing) and rehashed when the algorithm moves.
 * One-time tokens (email check, password reset, invite, remember-me) are "selector.validator": the selector finds
 * the row, only a SHA-256 of the validator is stored, and the comparison is constant-time.
 * Sign-in is throttled per account and per address; an unknown email costs the same time as a wrong password and
 * every flow answers the same way whether or not the email has an account.
 *
 * Statuses: pending (email not checked) → active, or → approval (waiting for an administrator) → active;
 * disabled accounts cannot sign in.
 */
final class Auth
{
    private const TTL = ['verify' => 172800, 'reset' => 3600, 'invite' => 604800, 'remember' => 0];

    /** @var array<string, mixed>|null|false false = not looked up yet */
    private array|null|false $user = false;

    public function __construct(private readonly App $app)
    {
    }

    /** @return array<string, mixed>|null the signed-in, active account */
    public function user(): ?array
    {
        if ($this->user !== false) {
            return $this->user;
        }
        $this->user = null;
        $session = $this->app->session();
        $id = (int) $session->get('uid', 0);
        if ($id > 0) {
            $row = $this->find($id);
            if ($row !== null && $row['status'] === 'active' && hash_equals((string) $session->get('auth_ver', ''), self::version($row))) {
                return $this->user = $row;
            }
            $session->forget('uid');
        }
        $remember = $this->app->request()?->cookie($this->app->cookieName('psc_rm'));
        if (is_string($remember) && $remember !== '') {
            $row = $this->consume($remember, 'remember');
            if ($row !== null && $row['status'] === 'active') {
                $this->login($row, true);
                return $this->user;
            }
            $this->app->queueCookie($this->app->cookieName('psc_rm'), '', -1);
            $this->app->queueCookie('psc_li', '', -1, false);
        }
        return null;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function isAdmin(): bool
    {
        return ($this->user()['role'] ?? '') === 'admin';
    }

    public function id(): ?int
    {
        $u = $this->user();
        return $u === null ? null : (int) $u['id'];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->app->db()->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->app->db()->one('SELECT * FROM users WHERE email = ?', [self::normaliseEmail($email)]);
    }

    public static function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @return array{ok: bool, reason: string, user?: array<string, mixed>}
     *   reason: ok | invalid | throttled | unverified | approval | disabled
     */
    public function attempt(string $email, string $password, bool $remember = false): array
    {
        $email = self::normaliseEmail($email);
        $ip = $this->app->clientIp();
        $limiter = $this->app->limiter();
        $window = $this->app->config->int('security.login_window_seconds', 900);
        $max = $this->app->config->int('security.login_max_attempts', 5);
        if ($limiter->peek('login-acct:' . $email) >= $max || !$limiter->hit('login-ip:' . $ip, $max * 4, $window)) {
            Audit::log($this->app, null, 'login.throttled', null, ['email_hash' => hash('sha256', $email)]);
            return ['ok' => false, 'reason' => 'throttled'];
        }
        $row = $email !== '' ? $this->findByEmail($email) : null;
        // An unknown email still pays for one hash, so timing does not reveal which emails have accounts.
        $valid = password_verify($password, $row !== null ? (string) $row['password_hash'] : self::hash('not-a-password'));
        if ($row === null || !$valid) {
            $limiter->hits('login-acct:' . $email, $window);
            Audit::log($this->app, $row !== null ? (int) $row['id'] : null, 'login.failed');
            return ['ok' => false, 'reason' => 'invalid'];
        }
        $limiter->clear('login-acct:' . $email);
        if ($row['status'] === 'pending') {
            return ['ok' => false, 'reason' => 'unverified', 'user' => $row];
        }
        if ($row['status'] === 'approval') {
            return ['ok' => false, 'reason' => 'approval', 'user' => $row];
        }
        if ($row['status'] !== 'active') {
            return ['ok' => false, 'reason' => 'disabled'];
        }
        if (password_needs_rehash((string) $row['password_hash'], self::algorithm())) {
            $hash = self::hash($password);
            $this->app->db()->update('users', ['password_hash' => $hash], ['id' => (int) $row['id']]);
            $row['password_hash'] = $hash;
        }
        $row = $this->promoteIfAdminEmail($row);
        $this->login($row, $remember);
        Audit::log($this->app, (int) $row['id'], 'login.ok');
        return ['ok' => true, 'reason' => 'ok', 'user' => $row];
    }

    /** @param array<string, mixed> $row */
    public function login(array $row, bool $remember = false): void
    {
        $session = $this->app->session();
        $session->regenerate();
        $session->set('uid', (int) $row['id']);
        $session->set('auth_ver', self::version($row));
        $now = time();
        $this->app->db()->update('users', ['last_login_at' => $now, 'failed_logins' => 0], ['id' => (int) $row['id']]);
        $days = $this->app->config->int('security.remember_days', 30);
        if ($remember) {
            $this->app->db()->exec("DELETE FROM user_tokens WHERE user_id = ? AND purpose = 'remember' AND expires_at < ?", [(int) $row['id'], $now]);
            $token = $this->issue((int) $row['id'], 'remember', $days * 86400);
            $this->app->queueCookie($this->app->cookieName('psc_rm'), $token, $now + $days * 86400);
        }
        // A UI hint only (header shows "My account"); never trusted for access.
        $this->app->queueCookie('psc_li', '1', $remember ? $now + $days * 86400 : 0, false);
        $this->user = $row;
    }

    public function logout(): void
    {
        $id = $this->id();
        $remember = $this->app->request()?->cookie($this->app->cookieName('psc_rm'));
        if (is_string($remember) && str_contains($remember, '.')) {
            $this->app->db()->exec("DELETE FROM user_tokens WHERE selector = ? AND purpose = 'remember'", [explode('.', $remember, 2)[0]]);
        }
        $this->app->session()->destroy();
        $this->app->queueCookie($this->app->cookieName('psc_rm'), '', -1);
        $this->app->queueCookie('psc_li', '', -1, false);
        if ($id !== null) {
            Audit::log($this->app, $id, 'logout');
        }
        $this->user = null;
    }

    /**
     * Creates an account and sends the email check. An email that already has an account gets a notice instead,
     * and the caller is told "ok" either way, so the form never reveals who has an account.
     *
     * @param array{name: string, email: string, company?: string, password: string, marketing?: bool} $input
     * @return array{ok: bool, errors: array<string, string>}
     */
    public function register(array $input): array
    {
        $errors = $this->validateRegistration($input);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }
        $email = self::normaliseEmail($input['email']);
        $existing = $this->findByEmail($email);
        if ($existing !== null) {
            $this->app->mailer()->send($email, 'You already have an account', 'account-exists', ['name' => (string) $existing['name']]);
            return ['ok' => true, 'errors' => []];
        }
        $now = time();
        $id = $this->app->db()->insert('users', [
            'email' => $email,
            'name' => trim($input['name']),
            'company' => trim($input['company'] ?? '') ?: null,
            'password_hash' => self::hash($input['password']),
            'role' => $this->isAdminEmail($email) ? 'admin' : 'client',
            'status' => 'pending',
            'marketing_opt_in' => !empty($input['marketing']) ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Audit::log($this->app, $id, 'register');
        $this->sendVerification($id);
        return ['ok' => true, 'errors' => []];
    }

    public function sendVerification(int $userId): void
    {
        $row = $this->find($userId);
        if ($row === null || $row['status'] !== 'pending') {
            return;
        }
        $this->app->db()->exec("DELETE FROM user_tokens WHERE user_id = ? AND purpose = 'verify'", [$userId]);
        $token = $this->issue($userId, 'verify');
        $this->app->mailer()->send((string) $row['email'], 'Confirm your email address', 'verify-email', [
            'name' => (string) $row['name'],
            'url' => $this->app->baseUrl() . '/portal/verify/?token=' . rawurlencode($token),
        ]);
    }

    /** @return array<string, mixed>|null the account, now active or waiting for approval */
    public function verifyEmail(string $token): ?array
    {
        $row = $this->consume($token, 'verify');
        if ($row === null) {
            return null;
        }
        $needsApproval = $this->app->config->string('security.registration') === 'approval' && !$this->isAdminEmail((string) $row['email']);
        $status = $row['status'] === 'pending' ? ($needsApproval ? 'approval' : 'active') : (string) $row['status'];
        $this->app->db()->update('users', ['status' => $status, 'email_verified_at' => time(), 'updated_at' => time()], ['id' => (int) $row['id']]);
        Audit::log($this->app, (int) $row['id'], 'email.verified');
        $row = $this->find((int) $row['id']);
        if ($row !== null && $status === 'approval') {
            $this->notifyAdmins('An account is waiting for approval', 'admin-approval', ['user' => $row]);
        }
        return $row;
    }

    /** Sends a reset link when the email has an account that may sign in; silent otherwise. */
    public function requestReset(string $email): void
    {
        $email = self::normaliseEmail($email);
        if (!$this->app->limiter()->hit('reset:' . $email, 3, 3600) || !$this->app->limiter()->hit('reset-ip:' . $this->app->clientIp(), 10, 3600)) {
            return;
        }
        $row = $this->findByEmail($email);
        if ($row === null || $row['status'] === 'disabled') {
            return;
        }
        $this->app->db()->exec("DELETE FROM user_tokens WHERE user_id = ? AND purpose = 'reset'", [(int) $row['id']]);
        $token = $this->issue((int) $row['id'], 'reset');
        $this->app->mailer()->send((string) $row['email'], 'Reset your password', 'reset-password', [
            'name' => (string) $row['name'],
            'url' => $this->app->baseUrl() . '/portal/reset/?token=' . rawurlencode($token),
        ]);
        Audit::log($this->app, (int) $row['id'], 'password.reset_requested');
    }

    /** Whether a reset or invite token is still good (the form is only shown for one). */
    public function peekToken(string $token, string $purpose): bool
    {
        return $this->lookup($token, $purpose) !== null;
    }

    /**
     * Sets a new password from a reset or invite token. Every remember-me token of the account is dropped, and
     * sessions elsewhere end because their auth version no longer matches.
     *
     * @return array{ok: bool, errors: array<string, string>}
     */
    public function resetPassword(string $token, string $password, string $purpose = 'reset'): array
    {
        $row = $this->lookup($token, $purpose);
        if ($row === null) {
            return ['ok' => false, 'errors' => ['token' => 'This link has expired or was already used. Ask for a new one.']];
        }
        $error = $this->passwordError($password, (string) $row['email']);
        if ($error !== null) {
            return ['ok' => false, 'errors' => ['password' => $error]];
        }
        $row = $this->consume($token, $purpose);
        if ($row === null) {
            return ['ok' => false, 'errors' => ['token' => 'This link has expired or was already used. Ask for a new one.']];
        }
        $set = ['password_hash' => self::hash($password), 'updated_at' => time(), 'failed_logins' => 0, 'locked_until' => null];
        if ($row['status'] === 'pending') {
            $set['status'] = 'active';
            $set['email_verified_at'] = time();
        }
        $this->app->db()->update('users', $set, ['id' => (int) $row['id']]);
        $this->app->db()->exec("DELETE FROM user_tokens WHERE user_id = ? AND purpose IN ('remember', 'reset')", [(int) $row['id']]);
        Audit::log($this->app, (int) $row['id'], 'password.reset');
        return ['ok' => true, 'errors' => []];
    }

    /**
     * Changes the signed-in user's password after checking the current one; this session stays signed in.
     *
     * @return array{ok: bool, errors: array<string, string>}
     */
    public function changePassword(string $current, string $new): array
    {
        $row = $this->user();
        if ($row === null) {
            return ['ok' => false, 'errors' => ['current' => 'Please sign in again.']];
        }
        if (!password_verify($current, (string) $row['password_hash'])) {
            return ['ok' => false, 'errors' => ['current' => 'That is not your current password.']];
        }
        $error = $this->passwordError($new, (string) $row['email']);
        if ($error !== null) {
            return ['ok' => false, 'errors' => ['password' => $error]];
        }
        $this->app->db()->update('users', ['password_hash' => self::hash($new), 'updated_at' => time()], ['id' => (int) $row['id']]);
        $this->app->db()->exec("DELETE FROM user_tokens WHERE user_id = ? AND purpose = 'remember'", [(int) $row['id']]);
        $fresh = $this->find((int) $row['id']);
        if ($fresh !== null) {
            $this->login($fresh, false);
        }
        Audit::log($this->app, (int) $row['id'], 'password.changed');
        return ['ok' => true, 'errors' => []];
    }

    /**
     * An administrator creates an account; the person sets their own password from an emailed link.
     *
     * @return array{ok: bool, errors: array<string, string>, id?: int}
     */
    public function invite(string $name, string $email, ?string $company, string $role = 'client'): array
    {
        $email = self::normaliseEmail($email);
        $errors = [];
        if (trim($name) === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Enter a name.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif ($this->findByEmail($email) !== null) {
            $errors['email'] = 'That email already has an account.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }
        $now = time();
        $id = $this->app->db()->insert('users', [
            'email' => $email,
            'name' => trim($name),
            'company' => $company !== null && trim($company) !== '' ? trim($company) : null,
            'password_hash' => self::hash(bin2hex(random_bytes(24))),
            'role' => in_array($role, ['client', 'admin'], true) ? $role : 'client',
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $token = $this->issue($id, 'invite');
        $this->app->mailer()->send($email, 'Your Patterns Show Control account', 'invite', [
            'name' => trim($name),
            'url' => $this->app->baseUrl() . '/portal/reset/?invite=1&token=' . rawurlencode($token),
        ]);
        Audit::log($this->app, $this->id(), 'user.invited', (string) $id);
        return ['ok' => true, 'errors' => [], 'id' => $id];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public function validateRegistration(array $input): array
    {
        $errors = [];
        $name = trim((string) ($input['name'] ?? ''));
        $email = self::normaliseEmail((string) ($input['email'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Enter your name (up to 120 characters).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (mb_strlen((string) ($input['company'] ?? '')) > 160) {
            $errors['company'] = 'Keep the company name under 160 characters.';
        }
        $pw = $this->passwordError((string) ($input['password'] ?? ''), $email);
        if ($pw !== null) {
            $errors['password'] = $pw;
        }
        return $errors;
    }

    public function passwordError(string $password, string $email = ''): ?string
    {
        $min = $this->app->config->int('security.password_min_length', 10);
        if (mb_strlen($password) < $min) {
            return "Use at least {$min} characters.";
        }
        if (mb_strlen($password) > 200) {
            return 'Use at most 200 characters.';
        }
        $lower = mb_strtolower($password);
        if ($email !== '' && ($lower === $email || $lower === strstr($email, '@', true))) {
            return 'Do not use your email address as your password.';
        }
        if (in_array($lower, ['password123', 'password1234', '1234567890', 'qwertyuiop', 'letmein123', 'iloveyou12', 'patterns123', 'stagewatch1'], true) || count(array_unique(mb_str_split($password))) < 4) {
            return 'Choose a less common password.';
        }
        return null;
    }

    public function isAdminEmail(string $email): bool
    {
        $email = self::normaliseEmail($email);
        foreach ($this->app->config->list('app.admin_emails') as $admin) {
            if (is_string($admin) && self::normaliseEmail($admin) === $email) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function notifyAdmins(string $subject, string $template, array $data): void
    {
        $to = $this->app->config->string('mail.admin_to');
        $recipients = $to !== '' ? [$to] : array_filter($this->app->config->list('app.admin_emails'), 'is_string');
        foreach ($recipients as $r) {
            $this->app->mailer()->send($r, $subject, $template, $data);
        }
    }

    public static function hash(string $password): string
    {
        return password_hash($password, self::algorithm());
    }

    /** @return string|int */
    private static function algorithm(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    /** A fingerprint of the password hash: a session made before a password change stops matching. */
    private static function version(array $row): string
    {
        return substr(hash('sha256', (string) $row['password_hash'] . '|' . (string) $row['id']), 0, 20);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function promoteIfAdminEmail(array $row): array
    {
        if ($row['role'] !== 'admin' && $this->isAdminEmail((string) $row['email']) && $row['email_verified_at'] !== null) {
            $this->app->db()->update('users', ['role' => 'admin'], ['id' => (int) $row['id']]);
            $row['role'] = 'admin';
            Audit::log($this->app, (int) $row['id'], 'user.promoted', null, ['by' => 'admin_emails']);
        }
        return $row;
    }

    /** Issues a one-time token and returns "selector.validator" (the only time the validator exists in clear). */
    public function issue(int $userId, string $purpose, ?int $ttl = null): string
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $ttl ??= self::TTL[$purpose] ?? 3600;
        $this->app->db()->insert('user_tokens', [
            'user_id' => $userId,
            'purpose' => $purpose,
            'selector' => $selector,
            'token_hash' => hash('sha256', $validator),
            'expires_at' => time() + $ttl,
            'created_at' => time(),
        ]);
        return $selector . '.' . $validator;
    }

    /** @return array<string, mixed>|null the token's account, the token left in place */
    private function lookup(string $token, string $purpose): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || !ctype_xdigit($parts[0]) || !ctype_xdigit($parts[1])) {
            return null;
        }
        $row = $this->app->db()->one('SELECT * FROM user_tokens WHERE selector = ? AND purpose = ?', [$parts[0], $purpose]);
        if ($row === null || $row['used_at'] !== null || (int) $row['expires_at'] < time()) {
            return null;
        }
        if (!hash_equals((string) $row['token_hash'], hash('sha256', $parts[1]))) {
            return null;
        }
        return $this->find((int) $row['user_id']);
    }

    /** @return array<string, mixed>|null the token's account; the token is spent (remember-me tokens are rotated) */
    private function consume(string $token, string $purpose): ?array
    {
        $user = $this->lookup($token, $purpose);
        if ($user === null) {
            return null;
        }
        $selector = explode('.', $token, 2)[0];
        if ($purpose === 'remember') {
            $this->app->db()->exec('DELETE FROM user_tokens WHERE selector = ?', [$selector]);
        } else {
            $this->app->db()->exec('UPDATE user_tokens SET used_at = ? WHERE selector = ?', [time(), $selector]);
        }
        return $user;
    }
}
