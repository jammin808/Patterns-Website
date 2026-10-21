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

    /**
     * Hashes of a throwaway string at PHP's default cost for each algorithm (matching one grants nothing: there is
     * no account behind it). A sign-in for an unknown email verifies against one, so it costs one verify exactly as
     * a wrong password does; hashing afresh would cost a hash and a verify, twice as long, which tells an observer
     * the email has no account.
     */
    private const DUMMY_HASHES = [
        'argon2id' => '$argon2id$v=19$m=65536,t=4,p=1$Ri9Ib3RsZ2kvNzFhcjJnUQ$yGahdIcMAB/7ur4+bHBqBpN0NbdElO3CfD5Iu4vREYU',
        '2y' => '$2y$10$TfdSk/rdF1y4wC6ezK/JSu6AsvHHQxUSJhUcpxS.S20csWo1cqmGm',
    ];

    /** Emails an address with an account receives from repeated sign-up attempts, per hour (the rest are silent). */
    private const EXISTING_ACCOUNT_NOTICES_PER_HOUR = 3;

    /** The per-account ceiling on failed sign-ins from all addresses together, as a multiple of the per-address limit. */
    private const ACCOUNT_CEILING_FACTOR = 10;

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
     * A one-line text field (a name, a company) as stored: valid UTF-8 only (anything else is empty), control
     * characters and runs of whitespace as one space, trimmed. Line breaks never reach an email or a page.
     */
    public static function cleanLine(string $value): string
    {
        // Invalid bytes arrive already replaced by U+FFFD (Request::scrub): a name holding one is refused too.
        if (!mb_check_encoding($value, 'UTF-8') || str_contains($value, "\u{FFFD}")) {
            return '';
        }
        return trim((string) preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $value));
    }

    /**
     * An address an account may have: well formed, within the column, and not under the reserved .invalid
     * top-level domain (RFC 2606), which can never receive mail and holds the addresses of deleted accounts.
     */
    public static function isUsableEmail(string $email): bool
    {
        $email = self::normaliseEmail($email);
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && mb_strlen($email) <= 190
            && !str_ends_with($email, '.invalid');
    }

    /**
     * The name an email to this account greets it by: empty until the address is proven, so a stranger who typed
     * someone else's address into the sign-up form cannot put words of their own into an email to it.
     *
     * @param array<string, mixed> $row
     */
    public static function greetingName(array $row): string
    {
        return ($row['email_verified_at'] ?? null) !== null ? (string) $row['name'] : '';
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
        // Failures count per account from each address, so a stranger guessing at an account from their own
        // address never locks out its owner signing in from theirs; a far higher ceiling per account holds guessing
        // spread over many addresses. Failures also count per address, so people signing in correctly from one
        // shared network (a venue, an office) never use up each other's attempts. Every attempt counts against a
        // far higher ceiling per address too, because each one costs a full password verify (Argon2id: 64 MiB).
        if ($limiter->peek(self::accountBucket($email, $ip)) >= $max
            || $limiter->peek('login-acct:' . $email) >= $max * self::ACCOUNT_CEILING_FACTOR
            || $limiter->peek('login-ip:' . $ip) >= $max * 4
            || !$limiter->hit('login-ip-all:' . $ip, $max * 20, $window)) {
            Audit::log($this->app, null, 'login.throttled', null, ['email_hash' => hash('sha256', $email)]);
            return ['ok' => false, 'reason' => 'throttled'];
        }
        $row = $email !== '' ? $this->findByEmail($email) : null;
        // An unknown email costs one verify, as a wrong password does, so timing does not reveal which emails have accounts.
        $valid = password_verify($password, $row !== null ? (string) $row['password_hash'] : $this->dummyHash());
        if ($row === null || !$valid) {
            $limiter->hits(self::accountBucket($email, $ip), $window);
            $limiter->hits('login-acct:' . $email, $window);
            $limiter->hits('login-ip:' . $ip, $window);
            Audit::log($this->app, $row !== null ? (int) $row['id'] : null, 'login.failed');
            return ['ok' => false, 'reason' => 'invalid'];
        }
        $this->clearSignInFailures($email);
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
        // Hashed before the lookup, so a new address and a taken one cost the same time.
        $hash = self::hash($input['password']);
        $existing = $this->findByEmail($email);
        if ($existing !== null) {
            // Capped per address, so the form cannot be used to flood someone's inbox with notices.
            if ($this->app->limiter()->hit('register-exists:' . $email, self::EXISTING_ACCOUNT_NOTICES_PER_HOUR, 3600)) {
                $this->app->mailer()->send($email, 'You already have an account', 'account-exists', ['name' => self::greetingName($existing)]);
            }
            return ['ok' => true, 'errors' => []];
        }
        $now = time();
        $id = $this->app->db()->insert('users', [
            'email' => $email,
            'name' => self::cleanLine($input['name']),
            'company' => self::cleanLine($input['company'] ?? '') ?: null,
            'password_hash' => $hash,
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
            'name' => self::greetingName($row),
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
        // The link proves control of the mailbox, which lifts any hold strangers' failed sign-ins put on the account.
        $this->clearSignInFailures((string) $row['email']);
        // As in resetPassword(): an account an administrator invited never waits for an administrator's approval.
        $needsApproval = $this->app->config->string('security.registration') === 'approval'
            && !$this->isAdminEmail((string) $row['email'])
            && !$this->wasInvited((int) $row['id']);
        $joinsQueue = $row['status'] === 'pending' && $needsApproval;
        $status = $row['status'] === 'pending' ? ($needsApproval ? 'approval' : 'active') : (string) $row['status'];
        $this->app->db()->update('users', ['status' => $status, 'email_verified_at' => time(), 'updated_at' => time()], ['id' => (int) $row['id']]);
        Audit::log($this->app, (int) $row['id'], 'email.verified');
        $row = $this->find((int) $row['id']);
        // Administrators hear once, when the account joins the queue, not again for an account already in it.
        if ($row !== null && $joinsQueue) {
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
            'name' => self::greetingName($row),
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
     * Sets a new password from a reset or invite token. Every other way into the account ends with it (see
     * endOtherCredentials()), and sessions elsewhere end because their auth version no longer matches.
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
        // The link proves control of the mailbox: the new password works at once, whatever failed sign-ins came before.
        $this->clearSignInFailures((string) $row['email']);
        $set = ['password_hash' => self::hash($password), 'updated_at' => time(), 'failed_logins' => 0, 'locked_until' => null];
        $needsApproval = false;
        if ($row['status'] === 'pending') {
            // The link proves the address, so the account moves on as an email check would: a self-registered
            // account still waits for an administrator when registration needs approval; an invited one does not.
            $needsApproval = $this->app->config->string('security.registration') === 'approval'
                && !$this->isAdminEmail((string) $row['email'])
                && !$this->wasInvited((int) $row['id']);
            $set['status'] = $needsApproval ? 'approval' : 'active';
            $set['email_verified_at'] = time();
        }
        $this->app->db()->update('users', $set, ['id' => (int) $row['id']]);
        $this->endOtherCredentials((int) $row['id']);
        Audit::log($this->app, (int) $row['id'], 'password.reset');
        if ($needsApproval) {
            $fresh = $this->find((int) $row['id']);
            if ($fresh !== null) {
                $this->notifyAdmins('An account is waiting for approval', 'admin-approval', ['user' => $fresh]);
            }
        }
        return ['ok' => true, 'errors' => []];
    }

    /**
     * Changes the signed-in user's password after checking the current one. This session stays signed in (and
     * remembered, if it was); every other session and link ends, as after a reset.
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
        // This browser keeps "Keep me signed in" if it had it; every other browser's token goes with the rest.
        $cookie = $this->app->request()?->cookie($this->app->cookieName('psc_rm'));
        $remembered = is_string($cookie) && (int) ($this->lookup($cookie, 'remember')['id'] ?? 0) === (int) $row['id'];
        $this->app->db()->update('users', ['password_hash' => self::hash($new), 'updated_at' => time()], ['id' => (int) $row['id']]);
        $this->endOtherCredentials((int) $row['id']);
        $fresh = $this->find((int) $row['id']);
        if ($fresh !== null) {
            $this->login($fresh, $remembered);
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
        $name = self::cleanLine($name);
        $company = $company !== null ? self::cleanLine($company) : null;
        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Enter a name.';
        }
        if (!self::isUsableEmail($email)) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif ($this->findByEmail($email) !== null) {
            $errors['email'] = 'That email already has an account.';
        }
        if ($company !== null && mb_strlen($company) > 160) {
            $errors['company'] = 'Keep the company name under 160 characters.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }
        $now = time();
        $id = $this->app->db()->insert('users', [
            'email' => $email,
            'name' => $name,
            'company' => $company !== null && $company !== '' ? $company : null,
            'password_hash' => self::hash(bin2hex(random_bytes(24))),
            'role' => in_array($role, ['client', 'admin'], true) ? $role : 'client',
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $token = $this->issue($id, 'invite');
        $this->app->mailer()->send($email, 'Your Patterns Show Control account', 'invite', [
            'name' => $name,
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
        $name = self::cleanLine((string) ($input['name'] ?? ''));
        $email = self::normaliseEmail((string) ($input['email'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Enter your name (up to 120 characters).';
        }
        if (!self::isUsableEmail($email)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (mb_strlen(self::cleanLine((string) ($input['company'] ?? ''))) > 160) {
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

    /** The failed sign-ins for one account from one address. */
    private static function accountBucket(string $email, string $ip): string
    {
        return 'login-acct-ip:' . self::normaliseEmail($email) . '|' . $ip;
    }

    /**
     * Lifts the account's sign-in holds: its ceiling across all addresses and its count from this address. Called
     * after a correct password and after an emailed link proves the mailbox.
     */
    private function clearSignInFailures(string $email): void
    {
        $limiter = $this->app->limiter();
        $limiter->clear('login-acct:' . self::normaliseEmail($email));
        $limiter->clear(self::accountBucket($email, $this->app->clientIp()));
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

    /**
     * A hash to verify an unknown email's password against, at the cost of a real one. The built-in hashes match
     * PHP's defaults; a platform with other defaults makes one once and keeps it, so a miss still costs one verify.
     */
    private function dummyHash(): string
    {
        $algorithm = self::algorithm();
        $builtIn = self::DUMMY_HASHES[(string) $algorithm] ?? null;
        if ($builtIn !== null && !password_needs_rehash($builtIn, $algorithm)) {
            return $builtIn;
        }
        $file = $this->app->storage->dir('cache') . '/auth-dummy-hash';
        $kept = is_file($file) ? trim((string) @file_get_contents($file)) : '';
        if ($kept !== '' && !password_needs_rehash($kept, $algorithm)) {
            return $kept;
        }
        $hash = self::hash(bin2hex(random_bytes(16)));
        @file_put_contents($file, $hash, LOCK_EX);
        return $hash;
    }

    /**
     * After a password is set, every other way into the account ends: remember-me and reset tokens go, and any
     * invitation or confirmation link still out is spent (kept as a row, so wasInvited() still knows the history).
     * Sessions elsewhere end by themselves, because their auth version follows the password hash.
     */
    private function endOtherCredentials(int $userId): void
    {
        $db = $this->app->db();
        $db->exec("DELETE FROM user_tokens WHERE user_id = ? AND purpose IN ('remember', 'reset')", [$userId]);
        $db->exec("UPDATE user_tokens SET used_at = ? WHERE user_id = ? AND purpose IN ('invite', 'verify') AND used_at IS NULL", [time(), $userId]);
    }

    /** Whether an administrator created the account (an invite token was ever issued for it). */
    private function wasInvited(int $userId): bool
    {
        return $this->app->db()->value("SELECT 1 FROM user_tokens WHERE user_id = ? AND purpose = 'invite' LIMIT 1", [$userId]) !== null;
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
        return $this->spend(explode('.', $token, 2)[0], $purpose) ? $user : null;
    }

    /**
     * Spends a token. A one-time link is claimed by a single conditional write, so of two requests racing with the
     * same link only one wins. A remember-me token is rotated rather than claimed: two tabs reopening at once must
     * not sign the browser out, so both are let through.
     */
    private function spend(string $selector, string $purpose): bool
    {
        $db = $this->app->db();
        if ($purpose === 'remember') {
            $db->exec("DELETE FROM user_tokens WHERE selector = ? AND purpose = 'remember'", [$selector]);
            return true;
        }
        return $db->exec('UPDATE user_tokens SET used_at = ? WHERE selector = ? AND purpose = ? AND used_at IS NULL', [time(), $selector, $purpose]) === 1;
    }
}
