<?php

declare(strict_types=1);

namespace Psc\News\Digest;

use Psc\App;
use Psc\Auth\Auth;

/**
 * The digest's signed links and keyed address hashes (docs/NEWS.md §14.3).
 *
 * An unsubscribe token is "{selector}.{mac}": the subscriber row's selector and an HMAC-SHA256, base64url without
 * padding, of "digest|unsubscribe|{selector}" under a key derived from app.secret with a versioned label. A
 * confirmation token is "{selector}.{generation}.{mac}", its mac over "digest|confirm|{selector}|{generation}": the
 * row's generation when it was sent, which moves on each time an unsubscribed row asks again, so a confirmation from
 * before a withdrawal never confirms a later request (Subscribers checks the row's own generation).
 *
 * A token binds its purpose (a confirmation never unsubscribes, an unsubscribe link never confirms), the row and the
 * site's secret, and it never expires by itself: Subscribers decides how long a confirmation works, and an unsubscribe
 * link works for the row's whole life. A changed app.secret breaks every link and every hash. The key is never logged
 * or dumped.
 */
final class Tokens
{
    public const CONFIRM = 'confirm';
    public const UNSUBSCRIBE = 'unsubscribe';

    /** How long a confirmation link works after its row's requested_at: 7 days. */
    public const CONFIRM_SECONDS = 604800;

    /** The selector of a test digest's and the preview's links: no row ever has it, so they change nothing. */
    public const TEST_SELECTOR = '000000000000000000000000';

    private const PURPOSES = [self::CONFIRM, self::UNSUBSCRIBE];
    private const KEY_LABEL = 'psc-digest-v1';

    private readonly string $key;

    public function __construct(#[\SensitiveParameter] string $secret)
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('The digest tokens need a secret');
        }
        $this->key = hash_hmac('sha256', self::KEY_LABEL, $secret, true);
    }

    public static function fromApp(App $app): self
    {
        return new self($app->secret());
    }

    /** A new row's selector: 24 random lower-case hex characters, never the test selector. */
    public static function selector(): string
    {
        do {
            $selector = bin2hex(random_bytes(12));
        } while ($selector === self::TEST_SELECTOR);
        return $selector;
    }

    /** The most a confirmation's generation can be (an INT column's top). */
    public const MAX_GENERATION = 2147483647;

    /**
     * The token for $purpose and $selector; a confirmation's carries $generation, the row's when it is sent. An
     * unknown purpose, a selector not of selector()'s shape, a generation out of range, or one given to an unsubscribe
     * link (which binds none, so every old digest's link keeps working), throws.
     */
    public function make(string $purpose, string $selector, int $generation = 0): string
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new \InvalidArgumentException('Unknown digest token purpose');
        }
        if (preg_match('/^[a-f0-9]{24}$/D', $selector) !== 1) {
            throw new \InvalidArgumentException('A digest selector is 24 lower-case hex characters');
        }
        if ($generation < 0 || $generation > self::MAX_GENERATION || ($purpose === self::UNSUBSCRIBE && $generation !== 0)) {
            throw new \InvalidArgumentException('A confirmation\'s generation is a whole number from 0, and an unsubscribe link has none');
        }
        if ($purpose === self::CONFIRM) {
            return $selector . '.' . $generation . '.' . $this->mac($purpose, $selector, $generation);
        }
        return $selector . '.' . $this->mac($purpose, $selector, null);
    }

    /**
     * The selector a token carries, when it is of the shape make() gives for $purpose and its mac is right; else
     * null. It never throws. The mac is compared as text, so only make()'s own encoding is accepted.
     */
    public function read(string $purpose, string $token): ?string
    {
        if ($purpose === self::CONFIRM) {
            return $this->readConfirm($token)['selector'] ?? null;
        }
        if ($purpose !== self::UNSUBSCRIBE || preg_match('/^([a-f0-9]{24})\.([A-Za-z0-9_-]{43})$/D', $token, $m) !== 1) {
            return null;
        }
        return hash_equals($this->mac($purpose, $m[1], null), $m[2]) ? $m[1] : null;
    }

    /**
     * A confirmation token's selector and generation, when it is of make()'s shape (the generation in decimal, with
     * no leading zero) and its mac is right; else null. It never throws. Whether that generation is still the row's
     * is Subscribers' question.
     *
     * @return array{selector: string, generation: int}|null
     */
    public function readConfirm(string $token): ?array
    {
        if (preg_match('/^([a-f0-9]{24})\.(0|[1-9][0-9]{0,9})\.([A-Za-z0-9_-]{43})$/D', $token, $m) !== 1) {
            return null;
        }
        $generation = (int) $m[2];
        if ($generation > self::MAX_GENERATION || !hash_equals($this->mac(self::CONFIRM, $m[1], $generation), $m[3])) {
            return null;
        }
        return ['selector' => $m[1], 'generation' => $generation];
    }

    /**
     * The keyed hash of an address (normalised as Auth stores one): 64 lower-case hex. Keyed, so it cannot be looked
     * up from a list of addresses without the site's secret.
     */
    public function emailHash(string $email): string
    {
        return hash_hmac('sha256', 'email|' . Auth::normaliseEmail($email), $this->key);
    }

    /** @return array<string, never> nothing: a dump of this object never shows the key */
    public function __debugInfo(): array
    {
        return [];
    }

    /** A confirmation's mac binds its generation too; an unsubscribe link's ($generation null) binds none. */
    private function mac(string $purpose, string $selector, ?int $generation): string
    {
        $message = 'digest|' . $purpose . '|' . $selector . ($generation !== null ? '|' . $generation : '');
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $message, $this->key, true)), '+/', '-_'), '=');
    }
}
