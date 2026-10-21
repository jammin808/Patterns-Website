<?php

declare(strict_types=1);

namespace Psc\Controllers\Admin;

use Psc\Auth\Auth;
use Psc\Controllers\Controller;
use Psc\Controllers\Portal\DownloadController;
use Psc\Controllers\Portal\LicenceController;
use Psc\Controllers\Portal\TicketController;
use Psc\Db\Database;
use Psc\Http\Request;
use Psc\Http\Response;
use Psc\Support\Audit;
use Psc\Support\Paginator;

/**
 * Accounts, for administrators: a searchable list, inviting a client, one account's page with its tickets,
 * licences and recent audit rows, and the account actions.
 *
 * Actions are an allow-list (approve, disable, enable, make_admin, make_client, resend_verification), each
 * allowed only from the states where it means something, and each audited. An administrator never changes their
 * own account here, so the acting administrator always stays an active administrator and the site can never be
 * left without one by this page. A deleted account (its owner's data erased, its address under .invalid) is
 * never changed either: enabled or promoted, it would count as an administrator nobody can sign in as.
 *
 * Every change is written only from the state the request read (the old value is part of the UPDATE's WHERE), so
 * when another administrator acted first nothing is written, audited or emailed a second time.
 */
final class AdminUserController extends Controller
{
    public const STATUSES = [
        'pending' => 'Pending',
        'approval' => 'Waiting for approval',
        'active' => 'Active',
        'disabled' => 'Disabled',
    ];

    /** What each status means, on the account's page. */
    public const STATUS_HELP = [
        'pending' => 'Has not confirmed their email address or chosen a password yet, so cannot sign in.',
        'approval' => 'Confirmed their email address and is waiting for an administrator to approve the account.',
        'active' => 'Can sign in and use the portal.',
        'disabled' => 'Cannot sign in until an administrator enables the account again.',
    ];

    public const ROLES = ['client' => 'Client', 'admin' => 'Administrator'];

    public const ACTIONS = ['approve', 'disable', 'enable', 'make_admin', 'make_client', 'resend_verification'];

    private const PER_PAGE = 25;

    /** Invitations per administrator: [hits, window seconds]. */
    private const LIMIT_INVITES = [30, 3600];

    /** Confirmation or invitation emails resent per account: [hits, window seconds]. */
    private const LIMIT_RESENDS = [5, 3600];

    public function index(Request $request): Response
    {
        $db = $this->app->db();
        $raw = $request->queryString('q');
        $f = [
            'q' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', mb_check_encoding($raw, 'UTF-8') ? $raw : '')), 0, 100),
            'status' => AdminController::pick($request->queryString('status'), self::STATUSES),
            'role' => AdminController::pick($request->queryString('role'), self::ROLES),
        ];
        $where = ['1 = 1'];
        $params = [];
        if ($f['q'] !== '') {
            $where[] = "(u.name LIKE ? ESCAPE '!' OR u.email LIKE ? ESCAPE '!' OR u.company LIKE ? ESCAPE '!')";
            $like = Database::like($f['q']);
            array_push($params, $like, $like, $like);
        }
        foreach (['status', 'role'] as $k) {
            if ($f[$k] !== '') {
                $where[] = 'u.' . $k . ' = ?';
                $params[] = $f[$k];
            }
        }
        $sql = 'SELECT u.id, u.name, u.email, u.company, u.role, u.status, u.created_at, u.last_login_at,'
            . ' (SELECT COUNT(*) FROM tickets t WHERE t.user_id = u.id) AS ticket_count,'
            . ' (SELECT COUNT(*) FROM licences l WHERE l.user_id = u.id) AS licence_count'
            . ' FROM users u WHERE ' . implode(' AND ', $where) . ' ORDER BY u.created_at DESC, u.id DESC';
        $query = array_filter($f + ['page' => $request->queryString('page')], static fn (string $v): bool => $v !== '');
        $pager = Paginator::query($db, $sql, $params, $query, '/portal/admin/users/', self::PER_PAGE);

        $counts = ['' => 0] + array_fill_keys(array_keys(self::STATUSES), 0);
        foreach ($db->all('SELECT status, COUNT(*) AS n FROM users GROUP BY status') as $row) {
            $counts[''] += (int) $row['n'];
            if (isset($counts[(string) $row['status']])) {
                $counts[(string) $row['status']] = (int) $row['n'];
            }
        }

        return $this->portal('admin/users/index', [
            'pager' => $pager,
            'filters' => $f,
            'filtered' => $f['q'] !== '' || $f['status'] !== '' || $f['role'] !== '',
            'counts' => $counts,
            'statuses' => self::STATUSES,
            'roles' => self::ROLES,
            'errors' => $this->takeErrors(),
            'old' => $this->app->session()->takeOld(),
        ], 'Users');
    }

    /** Creates a pending account and emails its owner a link to choose a password (Auth::invite). */
    public function invite(Request $request): Response
    {
        $admin = $this->currentUser();
        $back = '/portal/admin/users/#invite';
        if (!$this->app->limiter()->hit('invite:' . (int) $admin['id'], self::LIMIT_INVITES[0], self::LIMIT_INVITES[1])) {
            return $this->backWithErrors($back, $request, [], 'You have sent a lot of invitations in the last hour. Please wait a while before sending more.');
        }
        // Invisible and reordering characters go (TicketController::visible), so a name can neither look empty on
        // every admin list nor read backwards, as a right-to-left override would make it.
        $name = TicketController::visible(TicketController::line($request, 'name'));
        $email = trim($request->input('email'));
        $company = TicketController::visible(TicketController::line($request, 'company'));
        $role = $request->input('role');

        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Enter their name (up to 120 characters).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (mb_strlen($company) > 160) {
            $errors['company'] = 'Keep the company name under 160 characters.';
        }
        if (!isset(self::ROLES[$role])) {
            $errors['role'] = 'Choose client or administrator.';
        }
        if ($errors !== []) {
            return $this->backWithErrors($back, $request, $errors);
        }
        $result = $this->app->auth()->invite($name, $email, $company !== '' ? $company : null, $role);
        if (!$result['ok'] || !isset($result['id'])) {
            return $this->backWithErrors($back, $request, $result['errors'] !== [] ? $result['errors'] : ['email' => 'The invitation could not be created.']);
        }
        // Said as sent only when the mailer took it, once Auth::invite reports that (a "sent" flag).
        if (($result['sent'] ?? true) === false) {
            return $this->redirect(
                self::path($result['id']),
                'warning',
                'The account is created, but the invitation email could not be sent. Use “Send a new invitation link” on this page to try again.'
            );
        }
        return $this->redirect(
            self::path($result['id']),
            'success',
            'Invitation sent to ' . mb_strtolower($email) . '. The account stays pending until they choose a password.'
        );
    }

    public function show(Request $request): Response
    {
        $admin = $this->currentUser();
        $db = $this->app->db();
        $user = $db->one('SELECT id, email, name, company, role, status, email_verified_at, marketing_opt_in, last_login_at, created_at, updated_at FROM users WHERE id = ?', [$request->attrInt('id')]);
        if ($user === null) {
            return $this->notFound();
        }
        $id = (int) $user['id'];
        $tickets = $db->all('SELECT id, type, product, title, status, last_activity_at FROM tickets WHERE user_id = ? ORDER BY last_activity_at DESC, id DESC LIMIT 10', [$id]);
        $licences = $db->all('SELECT id, product, tier, status, reference, created_at FROM licences WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 10', [$id]);
        $licenceNames = [];
        foreach ($licences as $l) {
            $licenceNames[(int) $l['id']] = LicenceController::optionName((string) $l['product'], (string) $l['tier']);
        }
        $audit = $db->all(
            'SELECT a.id, a.user_id, a.action, a.subject, a.meta, a.created_at, u.name AS actor_name, u.email AS actor_email'
            . ' FROM audit_log a LEFT JOIN users u ON u.id = a.user_id'
            . " WHERE a.user_id = ? OR (a.subject = ? AND a.action LIKE 'user.%')"
            . ' ORDER BY a.created_at DESC, a.id DESC LIMIT 15',
            [$id, (string) $id]
        );

        return $this->portal('admin/users/show', [
            'account' => $user,
            'isSelf' => $id === (int) $admin['id'],
            'deleted' => self::isDeleted($user),
            'enableTo' => $user['status'] === 'disabled' ? $this->enableTarget($user) : null,
            'invited' => $this->wasInvited($id),
            'adminEmail' => $this->app->auth()->isAdminEmail((string) $user['email']),
            'tickets' => $tickets,
            'ticketCount' => (int) $db->value('SELECT COUNT(*) FROM tickets WHERE user_id = ?', [$id]),
            'licences' => $licences,
            'licenceCount' => (int) $db->value('SELECT COUNT(*) FROM licences WHERE user_id = ?', [$id]),
            'licenceNames' => $licenceNames,
            'audit' => $audit,
            'statuses' => self::STATUSES,
            'statusHelp' => self::STATUS_HELP,
            'roles' => self::ROLES,
            'ticketTypes' => TicketController::TYPES,
            'ticketStatuses' => TicketController::STATUSES,
            'licenceStatuses' => LicenceController::STATUSES,
            'products' => TicketController::products() + DownloadController::productNames(),
        ], (string) $user['name']);
    }

    /** One allow-listed action on an account, audited; back to the account's page with a message. */
    public function update(Request $request): Response
    {
        $admin = $this->currentUser();
        $user = $this->app->db()->one('SELECT * FROM users WHERE id = ?', [$request->attrInt('id')]);
        if ($user === null) {
            return $this->notFound();
        }
        $id = (int) $user['id'];
        $adminId = (int) $admin['id'];
        $path = self::path($id);
        $action = $request->input('action');
        // The buttons post fixed values, so anything else is refused with a message only. Nothing is kept for a
        // form (this page has no field to show it beside), or the next form that reads errors would show it.
        if (!in_array($action, self::ACTIONS, true)) {
            return $this->redirect($path, 'danger', 'That action is not available. Use one of the buttons on this page.');
        }
        if ($id === $adminId) {
            return $this->redirect($path, 'danger', 'You cannot change your own role or status. Ask another administrator if it needs to change.');
        }
        if (self::isDeleted($user)) {
            return $this->redirect($path, 'danger', 'This account was deleted by its owner, so it cannot be changed.');
        }
        $status = (string) $user['status'];

        return match ($action) {
            'approve' => $this->approve($user, $adminId),
            'disable' => $this->setStatus($user, $adminId, 'disabled'),
            'enable' => $status === 'disabled'
                ? $this->setStatus($user, $adminId, $this->enableTarget($user))
                : $this->redirect($path, 'info', 'This account is not disabled, so there was nothing to enable.'),
            'make_admin' => $this->setRole($user, $adminId, 'admin'),
            'make_client' => $this->setRole($user, $adminId, 'client'),
            default => $status === 'pending'
                ? $this->resend($user, $adminId)
                : $this->redirect($path, 'info', 'This account has already confirmed its email address, so there is nothing to resend.'),
        };
    }

    public static function path(int $id): string
    {
        return '/portal/admin/users/' . $id . '/';
    }

    /**
     * Whether the account was deleted by its owner: deletion leaves the row (tickets and licences keep their
     * owner) with an address under the reserved .invalid domain, which no account can otherwise have.
     *
     * @param array<string, mixed> $user
     */
    public static function isDeleted(array $user): bool
    {
        return str_ends_with(Auth::normaliseEmail((string) ($user['email'] ?? '')), '.invalid');
    }

    // ---------------------------------------------------------------------------------------------------------
    // Actions

    /** @param array<string, mixed> $user */
    private function approve(array $user, int $adminId): Response
    {
        $id = (int) $user['id'];
        if ($user['status'] !== 'approval'
            || $this->app->db()->update('users', ['status' => 'active', 'updated_at' => time()], ['id' => $id, 'status' => 'approval']) !== 1) {
            return $this->redirect(self::path($id), 'info', 'This account is not waiting for approval, so nothing changed.');
        }
        Audit::log($this->app, $adminId, 'user.approved', (string) $id);
        $sent = $this->app->mailer()->send((string) $user['email'], 'Your account is approved', 'account-approved', ['name' => (string) $user['name']]);
        return $this->redirect(
            self::path($id),
            'success',
            $sent ? 'Account approved. We have emailed ' . $user['name'] . ' to say they can sign in.' : 'Account approved, but the email to tell them could not be sent. Please let them know yourself.'
        );
    }

    /** @param array<string, mixed> $user */
    private function setStatus(array $user, int $adminId, string $status): Response
    {
        $id = (int) $user['id'];
        $old = (string) $user['status'];
        if ($old === $status) {
            return $this->redirect(self::path($id), 'info', 'This account is already ' . mb_strtolower(self::STATUSES[$status]) . '.');
        }
        $changed = $this->app->db()->transaction(static function (Database $db) use ($id, $old, $status): bool {
            if ($db->update('users', ['status' => $status, 'updated_at' => time()], ['id' => $id, 'status' => $old]) !== 1) {
                return false;
            }
            if ($status === 'disabled') {
                // Every way back in ends, as after a password change (Auth::endOtherCredentials): sessions on their
                // next request (Auth::user() needs "active"), remember-me and reset tokens now, and any invitation
                // or confirmation link still out is spent (kept as a row, so the account is still known as
                // invited). A link used while disabled could otherwise set a password or confirm the address.
                $db->exec("DELETE FROM user_tokens WHERE user_id = ? AND purpose IN ('remember', 'reset')", [$id]);
                $db->exec("UPDATE user_tokens SET used_at = ? WHERE user_id = ? AND purpose IN ('invite', 'verify') AND used_at IS NULL", [time(), $id]);
            }
            return true;
        });
        if (!$changed) {
            return $this->redirect(self::path($id), 'info', 'This account changed while you were looking at it, so nothing changed. Check its status and try again.');
        }
        Audit::log($this->app, $adminId, $status === 'disabled' ? 'user.disabled' : 'user.enabled', (string) $id, ['from' => $old, 'to' => $status]);
        return $this->redirect(self::path($id), 'success', match ($status) {
            'disabled' => 'Account disabled. They are signed out, cannot sign in, and the links we emailed them no longer work.',
            'pending' => 'Account enabled. It is pending until they confirm their email address. Their earlier links no longer work, so send them a new one from this page.',
            'approval' => 'Account enabled. It is waiting for approval again, so approve it when you are ready.',
            default => 'Account enabled. They can sign in again.',
        });
    }

    /**
     * The status enabling a disabled account gives it back: pending while its address is unconfirmed, waiting for
     * approval when it was disabled while it waited (enabling is not approving), otherwise active.
     *
     * @param array<string, mixed> $user
     */
    private function enableTarget(array $user): string
    {
        if ($user['email_verified_at'] === null) {
            return 'pending';
        }
        $meta = $this->app->db()->value(
            "SELECT meta FROM audit_log WHERE action = 'user.disabled' AND subject = ? ORDER BY id DESC LIMIT 1",
            [(string) (int) $user['id']]
        );
        $before = is_string($meta) ? json_decode($meta, true) : null;
        return is_array($before) && ($before['from'] ?? null) === 'approval' ? 'approval' : 'active';
    }

    /** @param array<string, mixed> $user */
    private function setRole(array $user, int $adminId, string $role): Response
    {
        $id = (int) $user['id'];
        $old = (string) $user['role'];
        if ($old === $role) {
            return $this->redirect(self::path($id), 'info', 'This account is already ' . ($role === 'admin' ? 'an administrator' : 'a client') . '.');
        }
        if ($this->app->db()->update('users', ['role' => $role, 'updated_at' => time()], ['id' => $id, 'role' => $old]) !== 1) {
            return $this->redirect(self::path($id), 'info', 'This account changed while you were looking at it, so nothing changed. Check its role and try again.');
        }
        Audit::log($this->app, $adminId, 'user.role_changed', (string) $id, ['from' => $old, 'to' => $role]);
        if ($role === 'admin') {
            return $this->redirect(self::path($id), 'success', $user['name'] . ' is now an administrator.');
        }
        $message = $user['name'] . ' is now a client.';
        if ($this->app->auth()->isAdminEmail((string) $user['email'])) {
            return $this->redirect(self::path($id), 'warning', $message . ' Their email address is on the administrator list in the site settings, so they become an administrator again the next time they sign in.');
        }
        return $this->redirect(self::path($id), 'success', $message);
    }

    /**
     * A pending account gets its link again: an invited account (it has no password of its own yet) a fresh
     * invitation, any other the email-confirmation link (Auth::sendVerification). A new invitation replaces the
     * earlier links only once the mailer has taken it; when it could not be sent, the earlier link keeps working.
     *
     * @param array<string, mixed> $user
     */
    private function resend(array $user, int $adminId): Response
    {
        $id = (int) $user['id'];
        if (!$this->app->limiter()->hit('admin-resend:' . $id, self::LIMIT_RESENDS[0], self::LIMIT_RESENDS[1])) {
            return $this->redirect(self::path($id), 'warning', 'Several emails have gone to this account in the last hour. Please wait a while before sending another.');
        }
        if ($this->wasInvited($id)) {
            $db = $this->app->db();
            $token = $this->app->auth()->issue($id, 'invite');
            $selector = explode('.', $token, 2)[0];
            $sent = $this->app->mailer()->send((string) $user['email'], 'Your ' . $this->app->name() . ' account', 'invite', [
                'name' => (string) $user['name'],
                'url' => $this->app->baseUrl() . '/portal/reset/?invite=1&token=' . rawurlencode($token),
            ]);
            if (!$sent) {
                $db->exec("DELETE FROM user_tokens WHERE selector = ? AND purpose = 'invite'", [$selector]);
                return $this->redirect(self::path($id), 'warning', 'The new invitation could not be sent, so nothing changed and any earlier link still works. Please try again later.');
            }
            $db->exec("DELETE FROM user_tokens WHERE user_id = ? AND purpose = 'invite' AND used_at IS NULL AND selector <> ?", [$id, $selector]);
            Audit::log($this->app, $adminId, 'user.invite_resent', (string) $id);
            return $this->redirect(self::path($id), 'success', 'A new invitation link is on its way to ' . $user['email'] . '. The earlier link no longer works.');
        }
        $this->app->auth()->sendVerification($id);
        Audit::log($this->app, $adminId, 'user.verification_resent', (string) $id);
        return $this->redirect(self::path($id), 'success', 'A new confirmation link is on its way to ' . $user['email'] . '.');
    }

    // ---------------------------------------------------------------------------------------------------------
    // Helpers

    /** Whether an administrator created the account: an invite token was issued for it at some point. */
    private function wasInvited(int $userId): bool
    {
        return $this->app->db()->value("SELECT 1 FROM user_tokens WHERE user_id = ? AND purpose = 'invite' LIMIT 1", [$userId]) !== null;
    }

    /** @return array<string, mixed> */
    private function currentUser(): array
    {
        $user = $this->app->auth()->user();
        if ($user === null) {
            throw new \LogicException('User administration needs the auth middleware');
        }
        return $user;
    }
}
