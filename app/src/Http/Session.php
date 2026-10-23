<?php

declare(strict_types=1);

namespace Psc\Http;

/**
 * The PHP session, started only on routes that need one (public pages set no cookie and stay cacheable).
 * Cookie: __Host- prefixed, Secure, HttpOnly, SameSite=Lax on HTTPS. Files live in storage/sessions.
 * Carries the CSRF token, flash messages and the signed-in user id with idle and absolute expiry.
 */
final class Session
{
    private bool $started = false;

    public function __construct(
        private readonly string $savePath,
        private readonly bool $secure,
        private readonly int $idleSeconds,
        private readonly int $absoluteSeconds,
    ) {
    }

    public function cookieName(): string
    {
        return $this->secure ? '__Host-psc' : 'psc_session';
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }
        if (!is_dir($this->savePath)) {
            @mkdir($this->savePath, 0770, true);
        }
        session_save_path($this->savePath);
        session_name($this->cookieName());
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) max($this->absoluteSeconds, 3600));
        // The id is PHP's own: 32 characters of 4 bits from the CSPRNG, 128 bits. Its length and alphabet are not
        // set here (both settings are deprecated from PHP 8.4); a longer id issued before stays valid until it expires.
        if (PHP_SAPI === 'cli') {
            // Tests: the session lives in memory for this process only.
            $_SESSION = $_SESSION ?? [];
        } else {
            session_start();
        }
        $this->started = true;
        $now = time();
        $created = (int) ($_SESSION['_created'] ?? 0);
        $seen = (int) ($_SESSION['_seen'] ?? 0);
        if ($created === 0 || ($seen > 0 && $now - $seen > $this->idleSeconds) || $now - $created > $this->absoluteSeconds) {
            $flash = $_SESSION['_flash'] ?? [];
            $_SESSION = ['_created' => $now, '_flash' => $created === 0 ? [] : $flash];
            if ($created !== 0) {
                $this->regenerate();
            }
        }
        $_SESSION['_seen'] = $now;
    }

    public function regenerate(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['_created'] = time();
        unset($_SESSION['_csrf']);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    public function destroy(): void
    {
        $this->start();
        $_SESSION = [];
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $this->started = false;
    }

    public function csrfToken(): string
    {
        $this->start();
        $token = $_SESSION['_csrf'] ?? null;
        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(32));
            $_SESSION['_csrf'] = $token;
        }
        return $token;
    }

    public function validCsrf(string $submitted): bool
    {
        $this->start();
        $token = $_SESSION['_csrf'] ?? '';
        return is_string($token) && $token !== '' && hash_equals($token, $submitted);
    }

    /** Queues a one-shot message for the next page: type is info | success | warning | danger. */
    public function flash(string $type, string $message): void
    {
        $this->start();
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type: string, message: string}> */
    public function takeFlashes(): array
    {
        if (!$this->started && !isset($_COOKIE[$this->cookieName()])) {
            return [];
        }
        $this->start();
        $out = $_SESSION['_flash'] ?? [];
        $_SESSION['_flash'] = [];
        return is_array($out) ? array_values($out) : [];
    }

    /** Remembers submitted form values (never passwords) for re-display after a validation redirect. */
    public function keepOld(array $input): void
    {
        $keep = [];
        foreach ($input as $k => $v) {
            if (is_string($k) && is_string($v) && !preg_match('/pass|token|csrf/i', $k)) {
                $keep[$k] = mb_substr($v, 0, 5000);
            }
        }
        $this->set('_old', $keep);
    }

    /** @return array<string, string> */
    public function takeOld(): array
    {
        $old = $this->get('_old', []);
        $this->forget('_old');
        return is_array($old) ? $old : [];
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    /**
     * Writes the session and releases its lock. A long response (the assistant's stream) calls this first so the
     * visitor's other requests are not held behind it; later writes in this request are not saved.
     */
    public function close(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }
}
