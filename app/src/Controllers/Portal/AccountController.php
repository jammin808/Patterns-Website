<?php

declare(strict_types=1);

namespace Psc\Controllers\Portal;

use Psc\Auth\Auth;
use Psc\Content\Content;
use Psc\Controllers\Controller;
use Psc\Controllers\DigestController;
use Psc\Db\Database;
use Psc\Http\Request;
use Psc\Http\Response;
use Psc\News\Comments;
use Psc\News\Digest\DigestMail;
use Psc\News\Digest\Subscribers;
use Psc\Support\Audit;
use Psc\Support\Uploads;

/**
 * The signed-in person's own account: details, password, their Industry Updates comments (each with the way to its
 * comment page, where it can be deleted, also on an article that is no longer live), a copy of their data, and
 * deleting the account. Every handler acts on the session's account only; nothing posted can name another one.
 *
 * Deleting anonymises rather than removes the row: tickets, ticket comments and licences are business records and stay,
 * shown as "Deleted user"; the name, email, company, country, password and every sign-in token go. Comments on Industry
 * Updates articles are public and no business record, so they go for good, with the reports the person made: the
 * anonymising UPDATE never fires the foreign keys' CASCADE, so they are deleted here, and the counts they touched
 * (an article's visible comments, a comment's reports) are counted again.
 *
 * The weekly digest's tick (docs/NEWS.md §14.7) is consent of its own, apart from marketing_opt_in (product news):
 * neither ever changes the other. Ticking it asks for the digest, and the digest's own confirmation goes to the
 * account's address: only that email's button starts it, as for a sign-up on the public page. Unticking it, or
 * deleting the account, ends the subscription and deletes the address from it, keeping only the suppression record.
 * A save acts only on a tick the person moved (posted against the state the page drew, digest_shown), and only while
 * the subscription is still as drawn. The copy of the data carries the subscription, never its selector or keyed
 * hash.
 */
final class AccountController extends Controller
{
    public const DELETE_WORD = 'DELETE';

    /** The newest of the person's Industry Updates comments the account page lists (the data copy has every one). */
    public const NEWS_COMMENTS_SHOWN = 50;

    /** The profile columns a copy of the data carries (never the password hash or the sign-in counters). */
    private const PROFILE_COLUMNS = ['id', 'email', 'name', 'company', 'country', 'role', 'status', 'marketing_opt_in', 'email_verified_at', 'last_login_at', 'created_at', 'updated_at'];

    private const LICENCE_COLUMNS = ['id', 'product', 'tier', 'status', 'quantity', 'nonprofit', 'hardware_ids', 'customer_note', 'reference', 'issued_at', 'updates_until', 'expires_at', 'created_at', 'updated_at'];

    public function show(Request $request): Response
    {
        $user = $this->user();
        $id = (int) $user['id'];
        $db = $this->app->db();
        return $this->portal('portal/account/show', [
            'account' => $user,
            'old' => $this->app->session()->takeOld(),
            'errors' => $this->takeErrors(),
            'minLength' => $this->app->config->int('security.password_min_length', 10),
            'kept' => [
                'licences' => (int) $db->value('SELECT COUNT(*) FROM licences WHERE user_id = ?', [$id]),
                'tickets' => (int) $db->value('SELECT COUNT(*) FROM tickets WHERE user_id = ?', [$id]),
            ],
            'newsComments' => (int) $db->value('SELECT COUNT(*) FROM news_comments WHERE user_id = ?', [$id]),
            // Listed with the way to each comment page, so a comment on an article taken down can still be deleted.
            'newsList' => (new Comments($this->app))->ofAccount($id, self::NEWS_COMMENTS_SHOWN),
            'deleteWord' => self::DELETE_WORD,
            'soleAdmin' => $this->isSoleAdmin($user),
            'digest' => $this->digestShown($user),
        ], 'Your account');
    }

    public function profile(Request $request): Response
    {
        $user = $this->user();
        $name = Auth::cleanLine($request->input('name'));
        $company = Auth::cleanLine($request->input('company'));
        $country = $request->input('country');
        $marketing = $request->input('marketing') === '1' ? 1 : 0;
        $digest = $request->input('digest') === '1';
        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Enter your name (up to 120 characters).';
        }
        if (mb_strlen($company) > 160) {
            $errors['company'] = 'Keep the company name under 160 characters.';
        }
        if (!Auth::isCountry($country)) {
            $errors['country'] = Auth::COUNTRY_ERROR;
        }
        if ($errors !== []) {
            // To the form's error summary, which takes focus (a fragment on the section would leave it on the page).
            return $this->backWithErrors('/portal/account/#profile-errors', $request, $errors);
        }
        $set = ['name' => $name, 'company' => $company !== '' ? $company : null, 'country' => $country !== '' ? $country : null, 'marketing_opt_in' => $marketing];
        $changed = [];
        foreach ($set as $column => $value) {
            if ((string) ($user[$column] ?? '') !== (string) ($value ?? '')) {
                $changed[] = $column;
            }
        }
        // The digest's tick changes the subscription only when the person moved it: it is compared with the state the
        // page drew (digest_shown, empty when the page could not read it), never with marketing_opt_in. A moved tick
        // is saved only while the subscription is still as drawn, so a page opened before an unsubscribe (or a
        // confirmation) elsewhere never undoes it.
        $shown = $request->input('digest_shown');
        $drawn = in_array($shown, ['0', '1'], true) ? $shown === '1' : null;
        $moved = $drawn === null ? $digest : $digest !== $drawn;
        $refused = null;
        if ($moved) {
            $stored = $drawn === null ? null : $this->digestShown($user);
            $refused = match (true) {
                $stored === null => 'unavailable',
                $stored !== $drawn => 'stale',
                default => null,
            };
        }
        $digestChanged = $moved && $refused === null;
        // An answer goes to the top of the page, where its message is (and takes focus); a refusal goes to the form.
        if ($changed === [] && !$moved) {
            return $this->redirect('/portal/account/', 'info', 'Nothing to save: your details are already up to date.');
        }
        $now = time();
        $subscribers = new Subscribers($this->app);
        $versions = DigestController::versions($this->app);
        // The details and the subscription are saved together or not at all.
        $outcome = $this->app->db()->transaction(static function (Database $db) use ($set, $changed, $user, $digest, $digestChanged, $subscribers, $versions, $now): ?string {
            if ($changed !== []) {
                $db->update('users', $set + ['updated_at' => $now], ['id' => (int) $user['id']]);
            }
            if (!$digestChanged) {
                return null;
            }
            return $digest
                ? $subscribers->subscribeAccount($user, 'account', $versions['consent'], $versions['privacy'], $now)
                : $subscribers->unsubscribeAccount($user, $now);
        });
        if ($digestChanged) {
            $changed[] = 'digest';
        }
        if ($changed !== []) {
            Audit::log($this->app, (int) $user['id'], 'account.profile_updated', (string) $user['id'], ['fields' => $changed]);
        }
        $words = Content::get('news')['digest']['account'] ?? [];
        $words = is_array($words) ? $words : [];
        if ($refused !== null) {
            $why = (string) ($words[$refused] ?? 'The digest tick was not saved.');
            return $this->redirect('/portal/account/', 'warning', ($changed !== [] ? 'Your details are saved. ' : '') . $why);
        }
        // The flash says what changed: a request names the address its confirmation went to, or says why none went.
        $key = null;
        if ($digestChanged && !$digest) {
            $key = 'off';
        } elseif ($digestChanged) {
            $key = $outcome === 'pending' ? $this->askForDigest($user, $subscribers) : 'on';
        }
        $message = $key !== null ? strtr((string) ($words[$key] ?? ''), [':email' => (string) $user['email']]) : '';
        return $this->redirect('/portal/account/', 'success', $message !== '' ? $message : 'Your details are saved.');
    }

    public function password(Request $request): Response
    {
        $user = $this->user();
        $back = '/portal/account/#password-errors';
        if (!$this->app->limiter()->hit('account-password:' . (int) $user['id'], 10, 3600)) {
            return $this->backWithErrors($back, $request, [], 'Too many password changes in a short time. Please wait an hour, then try again.');
        }
        $current = $request->input('current_password');
        $new = $request->input('new_password');
        $errors = [];
        if ($current === '') {
            $errors['current_password'] = 'Enter your current password.';
        }
        if ($new !== $request->input('new_password_confirm')) {
            $errors['new_password_confirm'] = 'The two new passwords do not match. Type the same password in both boxes.';
        }
        if ($errors === []) {
            $result = $this->app->auth()->changePassword($current, $new);
            $map = ['current' => 'current_password', 'password' => 'new_password'];
            foreach ($result['errors'] as $key => $message) {
                $errors[$map[$key] ?? 'current_password'] = $message;
            }
        }
        if ($errors !== []) {
            return $this->backWithErrors($back, $request, $errors);
        }
        return $this->redirect('/portal/account/', 'success', 'Your password is changed. Any other browsers signed in to this account are now signed out.');
    }

    public function delete(Request $request): Response
    {
        $user = $this->user();
        $id = (int) $user['id'];
        $back = '/portal/account/#delete-errors';
        if (!$this->app->limiter()->hit('account-delete:' . $id, 5, 3600)) {
            return $this->backWithErrors($back, $request, [], 'Too many attempts in a short time. Please wait an hour, then try again.');
        }
        if ($this->isSoleAdmin($user)) {
            // Deleting it would leave the portal with nobody who can approve accounts or issue licences.
            return $this->redirect('/portal/account/#delete', 'danger', 'Your account was not deleted. You are the only administrator. Make another account an administrator first, then try again.');
        }
        $errors = [];
        if (!password_verify($request->input('delete_password'), (string) $user['password_hash'])) {
            $errors['delete_password'] = 'That is not your current password.';
        }
        if (trim($request->input('delete_confirm')) !== self::DELETE_WORD) {
            $errors['delete_confirm'] = 'Type ' . self::DELETE_WORD . ' in capital letters to confirm.';
        }
        if ($errors !== []) {
            return $this->backWithErrors($back, $request, $errors, 'Your account was not deleted. Please check the highlighted fields.');
        }
        $db = $this->app->db();
        $kept = [
            'licences' => (int) $db->value('SELECT COUNT(*) FROM licences WHERE user_id = ?', [$id]),
            'tickets' => (int) $db->value('SELECT COUNT(*) FROM tickets WHERE user_id = ?', [$id]),
            'comments' => (int) $db->value('SELECT COUNT(*) FROM ticket_comments WHERE user_id = ?', [$id]),
        ];
        $comments = new Comments($this->app);
        $subscribers = new Subscribers($this->app);
        $removed = $db->transaction(static function (Database $db) use ($id, $user, $comments, $subscribers): array {
            $db->update('users', [
                'status' => 'disabled',
                'role' => 'client',
                'email' => self::anonymisedEmail($db, $id),
                'name' => 'Deleted user',
                'company' => null,
                'country' => null,
                'password_hash' => Auth::hash(bin2hex(random_bytes(32))),
                'marketing_opt_in' => 0,
                'failed_logins' => 0,
                'locked_until' => null,
                'updated_at' => time(),
            ], ['id' => $id]);
            $db->exec('DELETE FROM user_tokens WHERE user_id = ?', [$id]);
            $news = $comments->deleteForAccount($id);
            // A confirmed subscription ends (its address deleted, its suppression record kept); a pending one goes.
            $digest = $subscribers->accountDeleted($user, time());
            return ['news_comments' => $news['comments'], 'news_reports' => $news['reports'], 'news_digest' => $digest];
        });
        Audit::log($this->app, $id, 'account.deleted', (string) $id, $kept + ['removed' => $removed]);
        $this->app->auth()->notifyAdmins('An account was deleted', 'account-deleted-admin', [
            'id' => $id,
            'name' => (string) $user['name'],
            'email' => (string) $user['email'],
            'company' => (string) ($user['company'] ?? ''),
            'kept' => $kept,
            'removed' => $removed,
        ]);
        $this->app->auth()->logout();
        return $this->redirect('/portal/login/', 'success', 'Your account is deleted and you are signed out. Thank you for using Patterns Show Control.');
    }

    public function export(Request $request): Response
    {
        $user = $this->user();
        $id = (int) $user['id'];
        if (!$this->app->limiter()->hit('account-export:' . $id, 20, 3600)) {
            return $this->redirect('/portal/account/#data', 'warning', 'You have downloaded your data several times in the last hour. Please wait a while, then try again.');
        }
        $db = $this->app->db();
        $profile = array_intersect_key($user, array_flip(self::PROFILE_COLUMNS));
        $tickets = $db->all('SELECT id, type, product, title, body, status, severity, app_version, environment, visibility, votes, comment_count, last_activity_at, closed_at, created_at, updated_at FROM tickets WHERE user_id = ? ORDER BY id', [$id]);
        // Comments the person wrote anywhere, and the staff replies they can see on their own tickets.
        $comments = $db->all(
            'SELECT c.id, c.ticket_id, c.user_id, c.is_staff, c.body, c.created_at FROM ticket_comments c'
            . ' WHERE c.is_internal = 0 AND (c.user_id = ? OR (c.is_staff = 1 AND c.ticket_id IN (SELECT t.id FROM tickets t WHERE t.user_id = ?)))'
            . ' ORDER BY c.ticket_id, c.id',
            [$id, $id]
        );
        $comments = array_map(static fn (array $c): array => [
            'id' => (int) $c['id'],
            'ticket_id' => (int) $c['ticket_id'],
            'from' => (int) $c['user_id'] === $id ? 'you' : 'staff',
            'body' => (string) $c['body'],
            'created_at' => $c['created_at'],
        ], $comments);
        $attachments = $db->all('SELECT id, ticket_id, comment_id, original_name, mime, size, created_at FROM ticket_attachments WHERE user_id = ? ORDER BY id', [$id]);
        $votes = $db->all('SELECT ticket_id, created_at FROM ticket_votes WHERE user_id = ? ORDER BY created_at', [$id]);
        $licences = $db->all('SELECT ' . implode(', ', self::LICENCE_COLUMNS) . ' FROM licences WHERE user_id = ? ORDER BY id', [$id]);
        $conversations = [];
        foreach ($db->all('SELECT id, page, turns, created_at, last_at FROM chat_conversations WHERE user_id = ? ORDER BY created_at', [$id]) as $conv) {
            $conv['messages'] = $db->all('SELECT role, content, created_at FROM chat_messages WHERE conversation_id = ? ORDER BY id', [(string) $conv['id']]);
            $conversations[] = $conv;
        }
        $news = (new Comments($this->app))->forExport($id);
        $data = [
            'exported_at' => time(),
            'site' => $this->app->baseUrl() . '/',
            'about' => 'A copy of the data your ' . $this->app->name() . ' account holds. Dates are ISO 8601 in UTC.',
            'profile' => $profile,
            'tickets' => $tickets,
            'comments' => $comments,
            'attachments' => $attachments,
            'votes' => $votes,
            'licences' => $licences,
            'assistant_conversations' => $conversations,
            // Comments on Industry Updates articles in every status (visible, pending, hidden), and the reports made.
            'news_comments' => $news['news_comments'],
            'news_comment_reports' => $news['news_comment_reports'],
            // The weekly digest's subscription: its address, state, where and when it was asked and confirmed, the
            // versions shown, how it ended and every digest sent to it (Subscribers::forExport()); null without one.
            'news_digest' => (new Subscribers($this->app))->forExport($user),
        ];
        // Bytes that are not UTF-8 (older or imported records) become U+FFFD rather than failing the whole copy.
        $json = json_encode(self::isoDates($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        Audit::log($this->app, $id, 'account.exported', (string) $id);
        $name = 'patterns-account-' . $id . '-' . gmdate('Y-m-d') . '.json';
        return new Response($json, 200, Uploads::downloadHeaders($name, strlen($json), 'application/json'));
    }

    /**
     * The signed-in account (the auth middleware has already refused anyone else).
     *
     * @return array<string, mixed>
     */
    private function user(): array
    {
        $user = $this->app->auth()->user();
        if ($user === null) {
            throw new \LogicException('Account pages need the auth middleware');
        }
        return $user;
    }

    /**
     * Whether the account page draws the digest's tick ticked: the account's subscription, or the one for its
     * address, is confirmed. Null when it cannot be read: the page still answers, the tick unticked and its drawn
     * state empty, so a save never acts on it.
     *
     * @param array<string, mixed> $user
     */
    private function digestShown(array $user): ?bool
    {
        try {
            return (new Subscribers($this->app))->isSubscribed($user);
        } catch (\Throwable $e) {
            $this->app->log->exception($e, 'account digest');
            return null;
        }
    }

    /**
     * The account's request for the digest (Subscribers::subscribeAccount() answered pending) is sent the digest's own
     * confirmation, within the mailbox's daily allowance (DigestController::allowConfirmation()). The words to say:
     * asked (sent), asked_limited (the allowance was used up) or unsent (it could not be sent).
     *
     * @param array<string, mixed> $user
     */
    private function askForDigest(array $user, Subscribers $subscribers): string
    {
        try {
            $row = $subscribers->forAccount($user);
            if ($row === null || $row['state'] !== 'pending') {
                return 'unsent';
            }
            if (!DigestController::allowConfirmation($this->app, (string) $row['email'])) {
                return 'asked_limited';
            }
            return DigestMail::courier($this->app)->confirmation($row) ? 'asked' : 'unsent';
        } catch (\Throwable $e) {
            $this->app->log->exception($e, 'account digest confirmation');
            return 'unsent';
        }
    }

    /**
     * Whether this account is an administrator and no other active account is one.
     *
     * @param array<string, mixed> $user
     */
    private function isSoleAdmin(array $user): bool
    {
        if (($user['role'] ?? '') !== 'admin') {
            return false;
        }
        return (int) $this->app->db()->value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' AND id <> ?", [(int) $user['id']]) === 0;
    }

    /**
     * The address a deleted account is left with: deleted-<id>@deleted.invalid, which no person can register
     * (Auth::isUsableEmail refuses .invalid). Should a row already hold it, a random suffix keeps it unique, so
     * nothing stored can stop an account being deleted.
     */
    private static function anonymisedEmail(Database $db, int $id): string
    {
        $email = 'deleted-' . $id . '@deleted.invalid';
        $holder = $db->value('SELECT id FROM users WHERE email = ?', [$email]);
        if ($holder === null || (int) $holder === $id) {
            return $email;
        }
        return 'deleted-' . $id . '-' . bin2hex(random_bytes(6)) . '@deleted.invalid';
    }

    /**
     * Integer timestamps (keys ending _at or _until) as ISO 8601 UTC strings, recursively.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function isoDates(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::isoDates($value);
            } elseif (is_string($key) && (str_ends_with($key, '_at') || str_ends_with($key, '_until')) && is_numeric($value)) {
                $data[$key] = gmdate('Y-m-d\TH:i:s\Z', (int) $value);
            }
        }
        return $data;
    }
}
