<?php

declare(strict_types=1);

namespace Psc\News\Digest;

use Psc\App;
use Psc\Auth\Auth;
use Psc\Db\Database;
use Psc\News\ArticleStore;

/**
 * The weekly digest's subscribers (docs/NEWS.md §14.2, §14.3): the only writer of news_digest_subscribers. A row is
 * one address's consent record while it is pending or confirmed, and once unsubscribed the suppression record: the
 * address, the account and the bounces are cleared, and only the keyed hash, the selector, the versions and the
 * times stay, so the digest never reaches the address again unless it asks again.
 *
 * Every change of state is one conditional UPDATE (WHERE id = ? AND state = ?), so two requests never both act, and
 * no method opens a transaction of its own, so a caller's (the account's deletion) holds every change it makes. No
 * method logs an address, a token or a selector.
 *
 * A row's generation moves on each time it leaves unsubscribed for pending (it asked again), and a confirmation link
 * carries the generation it was sent with, so a link from before a withdrawal never confirms a later request, whoever
 * made that request. Consent is only ever given by the confirm page's button (confirm()), whether it was asked on the
 * public page, on the account page or at registration; nothing else confirms a row.
 *
 * Asking costs nothing and proves nothing, so a request never rewrites a record of consent: an unsubscribed row that
 * asks again keeps where, when and with which words it was last asked in its prior_ columns, and a request that ends
 * without its button (it lapses, it is withdrawn, its account is deleted) puts them back with the earlier withdrawal.
 */
final class Subscribers
{
    public const SOURCES = ['page', 'account', 'registration'];
    public const METHODS = ['one_click', 'page', 'mailto', 'account', 'admin', 'bounce'];

    /** A run of this many hard bounces or more, its first BOUNCE_SPAN or more ago, unsubscribes the address. */
    public const BOUNCE_LIMIT = 2;
    public const BOUNCE_SPAN = 1209600;

    private const INT_COLUMNS = ['id', 'user_id', 'generation', 'requested_at', 'confirmed_at', 'unsubscribed_at', 'prior_requested_at', 'bounces', 'bounced_at', 'created_at', 'updated_at'];

    /**
     * An unsubscribed row asking again keeps its record (where, when and with which words it was asked) until the new
     * request is confirmed. It comes before those columns in a SET: MySQL reads a column assigned earlier in the same
     * SET as its new value.
     */
    private const KEEP_PRIOR = 'prior_source = source, prior_consent_version = consent_version, prior_privacy_version = privacy_version, prior_requested_at = requested_at';

    /** A request that ends without its button puts back the record KEEP_PRIOR kept, if any. */
    private const PUT_BACK = 'source = COALESCE(prior_source, source), consent_version = COALESCE(prior_consent_version, consent_version), privacy_version = COALESCE(prior_privacy_version, privacy_version),'
        . ' requested_at = COALESCE(prior_requested_at, requested_at), prior_source = NULL, prior_consent_version = NULL, prior_privacy_version = NULL, prior_requested_at = NULL';

    /** A race (another request writing the same row) is settled by reading again, at most this many times. */
    private const ATTEMPTS = 3;

    private readonly Tokens $tokens;

    public function __construct(private readonly App $app, ?Tokens $tokens = null)
    {
        $this->tokens = $tokens ?? Tokens::fromApp($app);
    }

    // ------------------------------------------------------------------------------------------------- reading

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $id > 0 ? self::row($this->db()->one('SELECT * FROM news_digest_subscribers WHERE id = ?', [$id])) : null;
    }

    /** @return array<string, mixed>|null the row with this selector (24 lower-case hex characters), or null */
    public function bySelector(string $selector): ?array
    {
        if (preg_match('/^[a-f0-9]{24}$/', $selector) !== 1) {
            return null;
        }
        return self::row($this->db()->one('SELECT * FROM news_digest_subscribers WHERE selector = ?', [$selector]));
    }

    /** @return array<string, mixed>|null the row for an address, found by its keyed hash (so an unsubscribed one too) */
    public function byEmail(string $email): ?array
    {
        $email = Auth::normaliseEmail($email);
        if ($email === '') {
            return null;
        }
        return self::row($this->db()->one('SELECT * FROM news_digest_subscribers WHERE email_hash = ?', [$this->tokens->emailHash($email)]));
    }

    /**
     * The account's subscription: the row with its user_id, else the row for its address (one no other account holds).
     *
     * @param array<string, mixed> $user a users row (id, email)
     * @return array<string, mixed>|null
     */
    public function forAccount(array $user): ?array
    {
        $id = (int) ($user['id'] ?? 0);
        $own = $id > 0 ? self::row($this->db()->one('SELECT * FROM news_digest_subscribers WHERE user_id = ?', [$id])) : null;
        if ($own !== null) {
            return $own;
        }
        $row = $this->byEmail((string) ($user['email'] ?? ''));
        return $row !== null && ($row['user_id'] === null || $row['user_id'] === $id) ? $row : null;
    }

    /** @param array<string, mixed> $user */
    public function isSubscribed(array $user): bool
    {
        return ($this->forAccount($user)['state'] ?? null) === 'confirmed';
    }

    /**
     * The desk's numbers: rows by state, and those that joined (confirmed) and left (unsubscribed) in the last 7 days.
     *
     * @return array{confirmed: int, pending: int, unsubscribed: int, joined: int, left: int}
     */
    public function counts(int $now): array
    {
        $out = ['confirmed' => 0, 'pending' => 0, 'unsubscribed' => 0];
        foreach ($this->db()->all('SELECT state, COUNT(*) AS n FROM news_digest_subscribers GROUP BY state') as $row) {
            if (isset($out[(string) $row['state']])) {
                $out[(string) $row['state']] = (int) $row['n'];
            }
        }
        $since = $now - 7 * 86400;
        // Whoever joined counts, though they left again since (confirmed_at is kept), and whoever left, though they
        // have asked to come back since (unsubscribed_at is kept until they confirm).
        $out['joined'] = (int) $this->db()->value('SELECT COUNT(*) FROM news_digest_subscribers WHERE confirmed_at >= ?', [$since]);
        $out['left'] = (int) $this->db()->value('SELECT COUNT(*) FROM news_digest_subscribers WHERE unsubscribed_at >= ?', [$since]);
        return $out;
    }

    // --------------------------------------------------------------------------------------- the public page

    /**
     * A sign-up from the public page (double opt-in: nothing is sent until the confirmation's button is pressed).
     * The outcome says what the caller does: new, again and returning send the confirmation; confirmed sends nothing.
     *  - new: a pending row was made (source page);
     *  - again: it was pending; requested_at and the versions are renewed, so a link works for 7 days from now, and
     *    it is the page's request now (source page, no account), as the latest ask is what the record says;
     *  - returning: it was unsubscribed; it is pending again with its address (source page) and its generation moved
     *    on, keeping its unsubscribed_at and method until it confirms, and its record in the prior_ columns;
     *  - confirmed: it already gets the digest, and nothing changes.
     * The address must be usable (Auth::isUsableEmail()); the controller checks first, so anything else throws.
     *
     * @return array{outcome: string, subscriber: ?array<string, mixed>}
     */
    public function request(string $email, string $consentVersion, string $privacyVersion, int $now): array
    {
        if (!Auth::isUsableEmail($email)) {
            throw new \InvalidArgumentException('Not a usable email address');
        }
        $email = Auth::normaliseEmail($email);
        $hash = $this->tokens->emailHash($email);
        $db = $this->db();
        for ($i = 0; $i < self::ATTEMPTS; $i++) {
            $row = self::row($db->one('SELECT * FROM news_digest_subscribers WHERE email_hash = ?', [$hash]));
            if ($row === null) {
                $id = $this->insert([
                    'email' => $email, 'email_hash' => $hash, 'user_id' => null, 'state' => 'pending', 'source' => 'page',
                    'consent_version' => $consentVersion, 'privacy_version' => $privacyVersion, 'requested_at' => $now,
                ], $now);
                if ($id !== null) {
                    return ['outcome' => 'new', 'subscriber' => $this->find($id)];
                }
                continue;
            }
            if ($row['state'] === 'confirmed') {
                return ['outcome' => 'confirmed', 'subscriber' => $row];
            }
            if ($row['state'] === 'pending') {
                $db->exec(
                    "UPDATE news_digest_subscribers SET source = 'page', user_id = NULL, requested_at = ?, consent_version = ?, privacy_version = ?, updated_at = ? WHERE id = ? AND state = 'pending'",
                    [$now, $consentVersion, $privacyVersion, $now, $row['id']]
                );
                // Read back rather than counted: MySQL counts a row whose values were already these (the same second)
                // as unchanged, though it matched.
                $after = $this->find($row['id']);
                if ($after !== null && $after['state'] === 'pending' && $after['requested_at'] === $now && $after['source'] === 'page') {
                    return ['outcome' => 'again', 'subscriber' => $after];
                }
                continue;
            }
            $changed = $db->exec(
                'UPDATE news_digest_subscribers SET ' . self::KEEP_PRIOR . ", state = 'pending', email = ?, source = 'page', generation = generation + 1, requested_at = ?, consent_version = ?, privacy_version = ?, updated_at = ?"
                . " WHERE id = ? AND state = 'unsubscribed'",
                [$email, $now, $consentVersion, $privacyVersion, $now, $row['id']]
            );
            if ($changed === 1) {
                return ['outcome' => 'returning', 'subscriber' => $this->find($row['id'])];
            }
        }
        throw new \RuntimeException('The digest sign-up kept changing under this request');
    }

    /**
     * What a confirmation link of $generation does for its row, as the confirm page says it before the button is
     * pressed: ask (pending, the row's own generation, asked less than Tokens::CONFIRM_SECONDS ago: a link works for 7
     * days from requested_at), already (confirmed), gone (unsubscribed, or asked again since the link was sent, so
     * only a newer link works), expired (pending but asked CONFIRM_SECONDS or more ago) or missing (no row: Housekeeping
     * removed a request never confirmed).
     *
     * @param array<string, mixed>|null $row
     */
    public static function linkState(?array $row, int $generation, int $now): string
    {
        return match (true) {
            $row === null => 'missing',
            $row['state'] === 'confirmed' => 'already',
            $row['state'] !== 'pending', (int) $row['generation'] !== $generation => 'gone',
            !self::inTime($row, $now) => 'expired',
            default => 'ask',
        };
    }

    /**
     * The confirm page's button, for a link of $generation: confirmed (pending to confirmed, its consent given now,
     * the unsubscribe fields, the bounces and the record it asked over cleared, how it was asked kept), or what
     * linkState() says when it is not ask.
     */
    public function confirm(string $selector, int $generation, int $now): string
    {
        for ($i = 0; $i < self::ATTEMPTS; $i++) {
            $row = $this->bySelector($selector);
            $state = self::linkState($row, $generation, $now);
            if ($state !== 'ask' || $row === null) {
                return $state;
            }
            if ($this->db()->exec(
                "UPDATE news_digest_subscribers SET state = 'confirmed', confirmed_at = ?, unsubscribed_at = NULL, unsubscribe_method = NULL, bounces = 0, bounced_at = NULL,"
                . ' prior_source = NULL, prior_consent_version = NULL, prior_privacy_version = NULL, prior_requested_at = NULL, updated_at = ?'
                . " WHERE id = ? AND state = 'pending' AND generation = ? AND requested_at > ?",
                [$now, $now, $row['id'], $generation, $now - Tokens::CONFIRM_SECONDS]
            ) === 1) {
                return 'confirmed';
            }
        }
        return 'already';
    }

    /**
     * Unsubscribes the row with this selector at once: done (the address, the account and the bounces cleared, the
     * time and method of the withdrawal set; a request to come back puts back the record it asked over instead),
     * already, test (the test selector of a desk's test: nothing changes) or missing. The method must be one of
     * METHODS.
     */
    public function unsubscribe(string $selector, string $method, int $now): string
    {
        if (!in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException('Unknown unsubscribe method: ' . $method);
        }
        if ($selector === Tokens::TEST_SELECTOR) {
            return 'test';
        }
        for ($i = 0; $i < self::ATTEMPTS; $i++) {
            $row = $this->bySelector($selector);
            if ($row === null) {
                return 'missing';
            }
            if ($row['state'] === 'unsubscribed') {
                return 'already';
            }
            if ($this->end($row, $method, $now)) {
                return 'done';
            }
        }
        return 'already';
    }

    /** unsubscribe() of the row for an address (the desk's form): done, already or missing. */
    public function unsubscribeEmail(string $email, string $method, int $now): string
    {
        if (!in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException('Unknown unsubscribe method: ' . $method);
        }
        $row = $this->byEmail($email);
        return $row !== null ? $this->unsubscribe((string) $row['selector'], $method, $now) : 'missing';
    }

    // ------------------------------------------------------------------------------------------- the account

    /**
     * The account page's tick (source account) and registration's (source registration): a request, which only the
     * button on the page the digest's own confirmation leads to confirms, as for a sign-up on the public page. The
     * caller sends that confirmation once the address is proven: the account page at once, registration when the
     * email check's button or a reset proves it (renewAccountRequest()). A verified address is no proof that its
     * mailbox asked, so only that confirmation's button gives consent.
     *  - The account's row, else the row for its address, else a new one, becomes pending with the account's address,
     *    its user_id, the source and versions, asked now: pending. An unsubscribed one asks again, its generation moved
     *    on and its record kept in the prior_ columns until it confirms.
     *  - A confirmed one answers already, its consent unchanged; with the address verified, one that belongs to no
     *    account is linked to the account.
     *  - Without the address verified, a row of the address that is not the account's is never changed, so a stranger
     *    who registers someone else's address never takes over their subscription (already) or rewrites their waiting
     *    request (pending, nothing changed).
     * A row of the account's that holds another address (the account's address changed) is ended first, so the
     * digest never follows an account to an address that did not consent.
     *
     * @param array<string, mixed> $user a users row (id, email, email_verified_at)
     */
    public function subscribeAccount(array $user, string $source, string $consentVersion, string $privacyVersion, int $now): string
    {
        if (!in_array($source, ['account', 'registration'], true)) {
            throw new \InvalidArgumentException('Not an account source: ' . $source);
        }
        $userId = (int) ($user['id'] ?? 0);
        $email = Auth::normaliseEmail((string) ($user['email'] ?? ''));
        if ($userId <= 0 || !Auth::isUsableEmail($email)) {
            throw new \InvalidArgumentException('Not an account with a usable address');
        }
        $verified = ($user['email_verified_at'] ?? null) !== null;
        $hash = $this->tokens->emailHash($email);
        $db = $this->db();
        for ($i = 0; $i < self::ATTEMPTS; $i++) {
            $own = self::row($db->one('SELECT * FROM news_digest_subscribers WHERE user_id = ?', [$userId]));
            if ($own !== null && $own['email_hash'] !== $hash) {
                $this->end($own, 'account', $now);
                continue;
            }
            $row = $own ?? self::row($db->one('SELECT * FROM news_digest_subscribers WHERE email_hash = ?', [$hash]));
            if ($row !== null && $row['user_id'] !== null && $row['user_id'] !== $userId) {
                throw new \RuntimeException('The address\'s subscription belongs to another account');
            }
            if ($row === null) {
                $id = $this->insert([
                    'email' => $email, 'email_hash' => $hash, 'user_id' => $userId, 'state' => 'pending', 'source' => $source,
                    'consent_version' => $consentVersion, 'privacy_version' => $privacyVersion, 'requested_at' => $now,
                ], $now);
                if ($id !== null) {
                    return 'pending';
                }
                continue;
            }
            if ($row['state'] === 'confirmed') {
                if ($verified && $row['user_id'] === null) {
                    $db->exec("UPDATE news_digest_subscribers SET user_id = ?, updated_at = ? WHERE id = ? AND state = 'confirmed' AND user_id IS NULL", [$userId, $now, $row['id']]);
                }
                return 'already';
            }
            // Someone else's waiting request (the public page's, or another ask of this address) is theirs: only an
            // account whose address is verified may make it its own.
            if (!$verified && $row['state'] === 'pending' && $row['user_id'] !== $userId) {
                return 'pending';
            }
            // The account's own request, a waiting request of its verified address, or an unsubscribed record asked
            // again: pending, keeping how it last ended until it confirms. Read back rather than counted, as in
            // request(): a pending row may already hold these values.
            $returning = $row['state'] === 'unsubscribed' ? self::KEEP_PRIOR . ', generation = generation + 1, ' : '';
            $db->exec(
                'UPDATE news_digest_subscribers SET ' . $returning . "state = 'pending', email = ?, user_id = ?, source = ?, consent_version = ?, privacy_version = ?, requested_at = ?, updated_at = ?"
                . ' WHERE id = ? AND state = ?',
                [$email, $userId, $source, $consentVersion, $privacyVersion, $now, $now, $row['id'], $row['state']]
            );
            $after = $this->find($row['id']);
            if ($after !== null && $after['state'] === 'pending' && $after['user_id'] === $userId && $after['requested_at'] === $now) {
                return 'pending';
            }
        }
        throw new \RuntimeException('The account\'s subscription kept changing under this request');
    }

    /**
     * The account page's untick: unsubscribe() with method account of the account's subscription: done, already or
     * missing.
     *
     * @param array<string, mixed> $user
     */
    public function unsubscribeAccount(array $user, int $now): string
    {
        $row = $this->forAccount($user);
        return $row !== null ? $this->unsubscribe((string) $row['selector'], 'account', $now) : 'missing';
    }

    /**
     * The account's request waiting for its address to be proven (registration's tick, or the account page's: pending,
     * asked within 7 days, for the address the account has now) is asked again now, so its confirmation link works
     * for 7 days from now, and returned; null when there is none. Auth calls it when the email check or a pending
     * account's reset is sent, so the request lasts as long as the link that proves the address and the email can
     * say a confirmation follows, and when that link proves it, to send the digest's own confirmation. The row stays
     * pending: only the button on the page that confirmation opens confirms it, as for a sign-up on the public page.
     *
     * @return array<string, mixed>|null
     */
    public function renewAccountRequest(int $userId, int $now): ?array
    {
        $row = $this->accountRequest($userId, $now);
        if ($row === null) {
            return null;
        }
        $this->db()->exec(
            "UPDATE news_digest_subscribers SET requested_at = ?, updated_at = ? WHERE id = ? AND state = 'pending' AND user_id = ? AND generation = ?",
            [$now, $now, $row['id'], $userId, $row['generation']]
        );
        // Read back rather than counted, as in request(): the same second can leave the row's values as they were.
        $after = $this->find($row['id']);
        return $after !== null && $after['state'] === 'pending' && $after['user_id'] === $userId && $after['requested_at'] === $now ? $after : null;
    }

    /**
     * The account is being deleted (inside the deletion's transaction): the account's row and its address's. A
     * confirmed one is unsubscribed (method account: the address deleted, the suppression record kept); a pending one
     * is deleted, or put back when it had unsubscribed before (putBack()). Returns unsubscribed, removed or none.
     *
     * @param array<string, mixed> $user
     */
    public function accountDeleted(array $user, int $now): string
    {
        $db = $this->db();
        $userId = (int) ($user['id'] ?? 0);
        $rows = [];
        if ($userId > 0) {
            foreach ($db->all('SELECT * FROM news_digest_subscribers WHERE user_id = ?', [$userId]) as $r) {
                $rows[(int) $r['id']] = self::row($r);
            }
        }
        $byEmail = $this->byEmail((string) ($user['email'] ?? ''));
        if ($byEmail !== null && ($byEmail['user_id'] === null || $byEmail['user_id'] === $userId)) {
            $rows[$byEmail['id']] = $byEmail;
        }
        $result = 'none';
        foreach ($rows as $row) {
            if ($row['state'] === 'confirmed' && $this->end($row, 'account', $now)) {
                $result = 'unsubscribed';
            } elseif ($row['state'] === 'pending' && $this->dropPending($row, $now) && $result === 'none') {
                $result = 'removed';
            }
        }
        return $result;
    }

    /**
     * The account's address changed: its subscription ends (method account), and the person ticks the box again for
     * the new address. done, already or missing.
     */
    public function accountEmailChanged(int $userId, int $now): string
    {
        $row = $userId > 0 ? self::row($this->db()->one('SELECT * FROM news_digest_subscribers WHERE user_id = ?', [$userId])) : null;
        return $row !== null ? $this->unsubscribe((string) $row['selector'], 'account', $now) : 'missing';
    }

    /**
     * The account's subscription for the copy of their data: never its id, selector or keyed hash. prior is the record
     * a request to come back is waiting over (where, when and with which words it was asked), or null; digests lists
     * every digest sent to it (week, the send's state and time), the newest first.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>|null
     */
    public function forExport(array $user): ?array
    {
        $row = $this->forAccount($user);
        if ($row === null) {
            return null;
        }
        $out = [];
        foreach (['email', 'state', 'source', 'consent_version', 'privacy_version', 'requested_at', 'confirmed_at', 'unsubscribed_at', 'unsubscribe_method'] as $key) {
            $out[$key] = $row[$key];
        }
        $out['prior'] = $row['prior_source'] === null ? null : [
            'source' => $row['prior_source'],
            'consent_version' => $row['prior_consent_version'],
            'privacy_version' => $row['prior_privacy_version'],
            'requested_at' => $row['prior_requested_at'],
        ];
        $out['digests'] = [];
        foreach ($this->db()->all(
            'SELECT d.week, s.state, s.sent_at FROM news_digest_sends s JOIN news_digests d ON d.id = s.digest_id WHERE s.subscriber_id = ? ORDER BY d.send_at DESC, d.id DESC',
            [$row['id']]
        ) as $send) {
            $out['digests'][] = ['week' => (string) $send['week'], 'state' => (string) $send['state'], 'sent_at' => $send['sent_at'] !== null ? (int) $send['sent_at'] : null];
        }
        return $out;
    }

    // ------------------------------------------------------------------------------------------- the sender

    /** A digest reached the address: its run of bounces ends. */
    public function delivered(int $id): void
    {
        $this->db()->exec('UPDATE news_digest_subscribers SET bounces = 0, bounced_at = NULL WHERE id = ? AND bounces > 0', [$id]);
    }

    /**
     * One more hard bounce (Delivery::hardBounce(): the recipient's address refused at RCPT as one that does not
     * exist). At BOUNCE_LIMIT or more in a row, the first BOUNCE_SPAN or more
     * ago, the address is unsubscribed (method bounce): true when this call did that.
     */
    public function bounced(int $id, int $now): bool
    {
        $this->db()->exec("UPDATE news_digest_subscribers SET bounces = bounces + 1, bounced_at = COALESCE(bounced_at, ?), updated_at = ? WHERE id = ? AND state = 'confirmed'", [$now, $now, $id]);
        $row = $this->find($id);
        if ($row === null || $row['state'] !== 'confirmed' || $row['bounces'] < self::BOUNCE_LIMIT || $row['bounced_at'] === null || $now - $row['bounced_at'] < self::BOUNCE_SPAN) {
            return false;
        }
        return $this->end($row, 'bounce', $now);
    }

    // ------------------------------------------------------------------------------------------ housekeeping

    /**
     * Requests never confirmed within 7 days (requested_at Tokens::CONFIRM_SECONDS or more ago): deleted, unless the
     * row had unsubscribed before (a lapsed request to come back), which returns to unsubscribed with its address
     * and account cleared and the record it asked over put back, its earlier withdrawal with it.
     *
     * @return array{removed: int, lapsed: int}
     */
    public function expire(int $now): array
    {
        $db = $this->db();
        $cutoff = $now - Tokens::CONFIRM_SECONDS;
        $lapsed = $db->exec(
            "UPDATE news_digest_subscribers SET state = 'unsubscribed', email = NULL, user_id = NULL, bounces = 0, bounced_at = NULL, " . self::PUT_BACK . ', updated_at = ?'
            . " WHERE state = 'pending' AND requested_at <= ? AND unsubscribed_at IS NOT NULL",
            [$now, $cutoff]
        );
        $removed = $db->exec("DELETE FROM news_digest_subscribers WHERE state = 'pending' AND requested_at <= ? AND unsubscribed_at IS NULL", [$cutoff]);
        return ['removed' => $removed, 'lapsed' => $lapsed];
    }

    // ------------------------------------------------------------------------------------------------ internals

    private function db(): Database
    {
        return $this->app->db();
    }

    /**
     * The account's request waiting for its address to be proven: pending, from registration or the account page,
     * asked less than Tokens::CONFIRM_SECONDS ago, for the address the account has now.
     *
     * @return array<string, mixed>|null
     */
    private function accountRequest(int $userId, int $now): ?array
    {
        $email = $userId > 0 ? $this->db()->value('SELECT email FROM users WHERE id = ?', [$userId]) : null;
        if (!is_string($email) || $email === '') {
            return null;
        }
        return self::row($this->db()->one(
            "SELECT * FROM news_digest_subscribers WHERE user_id = ? AND state = 'pending' AND source IN ('registration', 'account') AND email_hash = ? AND requested_at > ?",
            [$userId, $this->tokens->emailHash($email), $now - Tokens::CONFIRM_SECONDS]
        ));
    }

    /** Whether a pending row's confirmation link still works: asked less than Tokens::CONFIRM_SECONDS ago. */
    private static function inTime(array $row, int $now): bool
    {
        return $now - (int) $row['requested_at'] < Tokens::CONFIRM_SECONDS;
    }

    /**
     * Inserts a row with a selector of its own; the new id, or null when the address's row was written meanwhile
     * (its unique hash), so the caller reads it again. A selector that clashes (never in practice) is drawn again.
     *
     * @param array<string, mixed> $row
     */
    private function insert(array $row, int $now): ?int
    {
        $db = $this->db();
        for ($i = 0; $i < self::ATTEMPTS; $i++) {
            $selector = Tokens::selector();
            try {
                return $db->insert('news_digest_subscribers', $row + ['selector' => $selector, 'created_at' => $now, 'updated_at' => $now]);
            } catch (\PDOException $e) {
                if (!ArticleStore::isDuplicate($e)) {
                    throw $e;
                }
                if ($db->value('SELECT 1 FROM news_digest_subscribers WHERE email_hash = ?', [$row['email_hash']]) !== null
                    || ($row['user_id'] !== null && $db->value('SELECT 1 FROM news_digest_subscribers WHERE user_id = ?', [$row['user_id']]) !== null)) {
                    return null;
                }
            }
        }
        throw new \RuntimeException('No free selector for a digest subscriber');
    }

    /**
     * Ends a pending or confirmed row: its address, account and bounces cleared, the withdrawal's time and method set.
     * A request to come back puts back the record it asked over instead (putBack()), its earlier withdrawal with it:
     * nobody was subscribed in between. True when this call changed it.
     *
     * @param array<string, mixed> $row
     */
    private function end(array $row, string $method, int $now): bool
    {
        if ($row['state'] === 'pending' && $row['unsubscribed_at'] !== null) {
            return $this->putBack($row, $now);
        }
        return $this->db()->exec(
            "UPDATE news_digest_subscribers SET state = 'unsubscribed', email = NULL, user_id = NULL, bounces = 0, bounced_at = NULL, unsubscribed_at = ?, unsubscribe_method = ?, updated_at = ?"
            . " WHERE id = ? AND state = ? AND state <> 'unsubscribed'",
            [$now, $method, $now, $row['id'], $row['state']]
        ) === 1;
    }

    /**
     * Withdraws a pending request: deleted, or, for a row that had unsubscribed before, put back (putBack()). True when
     * this call changed it.
     *
     * @param array<string, mixed> $row
     */
    private function dropPending(array $row, int $now): bool
    {
        if ($row['unsubscribed_at'] !== null) {
            return $this->putBack($row, $now);
        }
        return $this->db()->exec("DELETE FROM news_digest_subscribers WHERE id = ? AND state = 'pending' AND unsubscribed_at IS NULL", [$row['id']]) === 1;
    }

    /**
     * A request to come back (pending, unsubscribed before) that ends without its button: unsubscribed again, its
     * address and account cleared, and the record it asked over put back with its earlier withdrawal, so the
     * suppression record still says where, when and with which words consent was given. True when this call changed it.
     *
     * @param array<string, mixed> $row
     */
    private function putBack(array $row, int $now): bool
    {
        return $this->db()->exec(
            "UPDATE news_digest_subscribers SET state = 'unsubscribed', email = NULL, user_id = NULL, bounces = 0, bounced_at = NULL, " . self::PUT_BACK . ', updated_at = ?'
            . " WHERE id = ? AND state = 'pending' AND unsubscribed_at IS NOT NULL",
            [$now, $row['id']]
        ) === 1;
    }

    /**
     * A row with its whole-number columns as ints (NULL kept), the same on both engines.
     *
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>|null
     */
    private static function row(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        foreach (self::INT_COLUMNS as $column) {
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                $row[$column] = (int) $row[$column];
            }
        }
        return $row;
    }
}
