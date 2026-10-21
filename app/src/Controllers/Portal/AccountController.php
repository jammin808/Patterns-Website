<?php

declare(strict_types=1);

namespace Psc\Controllers\Portal;

use Psc\Auth\Auth;
use Psc\Controllers\Controller;
use Psc\Db\Database;
use Psc\Http\Request;
use Psc\Http\Response;
use Psc\Support\Audit;
use Psc\Support\Uploads;

/**
 * The signed-in person's own account: details, password, a copy of their data, and deleting the account. Every
 * handler acts on the session's account only; nothing posted can name another one.
 *
 * Deleting anonymises rather than removes the row: tickets, comments and licences are business records and stay,
 * shown as "Deleted user"; the name, email, company, password and every sign-in token go.
 */
final class AccountController extends Controller
{
    public const DELETE_WORD = 'DELETE';

    /** The profile columns a copy of the data carries (never the password hash or the sign-in counters). */
    private const PROFILE_COLUMNS = ['id', 'email', 'name', 'company', 'role', 'status', 'marketing_opt_in', 'email_verified_at', 'last_login_at', 'created_at', 'updated_at'];

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
            'deleteWord' => self::DELETE_WORD,
            'soleAdmin' => $this->isSoleAdmin($user),
        ], 'Your account');
    }

    public function profile(Request $request): Response
    {
        $user = $this->user();
        $name = Auth::cleanLine($request->input('name'));
        $company = Auth::cleanLine($request->input('company'));
        $marketing = $request->input('marketing') === '1' ? 1 : 0;
        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Enter your name (up to 120 characters).';
        }
        if (mb_strlen($company) > 160) {
            $errors['company'] = 'Keep the company name under 160 characters.';
        }
        if ($errors !== []) {
            return $this->backWithErrors('/portal/account/#details', $request, $errors);
        }
        $set = ['name' => $name, 'company' => $company !== '' ? $company : null, 'marketing_opt_in' => $marketing];
        $changed = [];
        foreach ($set as $column => $value) {
            if ((string) ($user[$column] ?? '') !== (string) ($value ?? '')) {
                $changed[] = $column;
            }
        }
        // An answer goes to the top of the page, where its message is (and takes focus); a refusal goes to the form.
        if ($changed === []) {
            return $this->redirect('/portal/account/', 'info', 'Nothing to save: your details are already up to date.');
        }
        $this->app->db()->update('users', $set + ['updated_at' => time()], ['id' => (int) $user['id']]);
        Audit::log($this->app, (int) $user['id'], 'account.profile_updated', (string) $user['id'], ['fields' => $changed]);
        return $this->redirect('/portal/account/', 'success', 'Your details are saved.');
    }

    public function password(Request $request): Response
    {
        $user = $this->user();
        $back = '/portal/account/#password';
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
        $back = '/portal/account/#delete';
        if (!$this->app->limiter()->hit('account-delete:' . $id, 5, 3600)) {
            return $this->backWithErrors($back, $request, [], 'Too many attempts in a short time. Please wait an hour, then try again.');
        }
        if ($this->isSoleAdmin($user)) {
            // Deleting it would leave the portal with nobody who can approve accounts or issue licences.
            return $this->redirect($back, 'danger', 'Your account was not deleted. You are the only administrator. Make another account an administrator first, then try again.');
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
        $db->transaction(static function (Database $db) use ($id): void {
            $db->update('users', [
                'status' => 'disabled',
                'role' => 'client',
                'email' => self::anonymisedEmail($db, $id),
                'name' => 'Deleted user',
                'company' => null,
                'password_hash' => Auth::hash(bin2hex(random_bytes(32))),
                'marketing_opt_in' => 0,
                'failed_logins' => 0,
                'locked_until' => null,
                'updated_at' => time(),
            ], ['id' => $id]);
            $db->exec('DELETE FROM user_tokens WHERE user_id = ?', [$id]);
        });
        Audit::log($this->app, $id, 'account.deleted', (string) $id, $kept);
        $this->app->auth()->notifyAdmins('An account was deleted', 'account-deleted-admin', [
            'id' => $id,
            'name' => (string) $user['name'],
            'email' => (string) $user['email'],
            'company' => (string) ($user['company'] ?? ''),
            'kept' => $kept,
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
