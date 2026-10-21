<?php

declare(strict_types=1);

namespace Psc\Controllers\Admin;

use Psc\Controllers\ContactController;
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
 * The administration overview and the read-mostly admin pages: contact messages (with their new → read →
 * archived status), the support assistant's conversations and transcripts, and the audit log. The routes carry
 * the admin middleware; every record is still looked up by its id and answered with a 404 when it is missing.
 *
 * Visitor-written text (messages, chat turns) is printed with nl2br(e()), never as Markdown, so nothing a
 * visitor typed becomes a link or markup on an administrator's screen.
 */
final class AdminController extends Controller
{
    public const MESSAGE_STATUSES = ['new' => 'New', 'read' => 'Read', 'archived' => 'Archived'];

    /** How each stored assistant verdict reads on the admin pages. */
    public const VERDICTS = [
        'in_scope' => 'Answered',
        'needs_human' => 'Needs a person',
        'out_of_scope' => 'Out of scope',
        'code_or_internals' => 'Code or internals',
        'abuse' => 'Abuse',
        'code_guard' => 'Code withdrawn',
    ];

    /** The verdicts counted per conversation in the list, in column order. */
    public const COUNTED_VERDICTS = ['out_of_scope', 'code_or_internals', 'needs_human', 'abuse', 'code_guard'];

    /** Verdicts where the assistant declined to answer: the "only refused" filter. */
    public const REFUSED_VERDICTS = ['out_of_scope', 'code_or_internals', 'abuse', 'code_guard'];

    /** Badge colours for verdicts, from the portal's badge set (the text always says the verdict too). */
    public const VERDICT_BADGES = [
        'in_scope' => 'active',
        'needs_human' => 'pending',
        'out_of_scope' => 'declined',
        'code_or_internals' => 'declined',
        'abuse' => 'revoked',
        'code_guard' => 'declined',
    ];

    /** Licence statuses that wait on an administrator. */
    public const LICENCE_WAITING = ['requested', 'awaiting_payment'];

    private const PER_PAGE = 25;
    private const LATEST = 5;

    /** Whether the router serves a transcript's own path (worked out once per request by chatPath()). */
    private ?bool $transcriptRouted = null;

    // ---------------------------------------------------------------------------------------------------------
    // Overview

    public function index(Request $request): Response
    {
        $db = $this->app->db();
        $closed = TicketController::CLOSED;
        $closedIn = self::placeholders($closed);
        $waitingIn = self::placeholders(self::LICENCE_WAITING);

        $users = ['total' => 0, 'pending' => 0, 'approval' => 0, 'active' => 0, 'disabled' => 0];
        foreach ($db->all('SELECT status, COUNT(*) AS n FROM users GROUP BY status') as $row) {
            $users['total'] += (int) $row['n'];
            if (isset($users[(string) $row['status']])) {
                $users[(string) $row['status']] = (int) $row['n'];
            }
        }

        $openByType = array_fill_keys(array_keys(TicketController::TYPES), 0);
        foreach ($db->all('SELECT type, COUNT(*) AS n FROM tickets WHERE status NOT IN (' . $closedIn . ') GROUP BY type', $closed) as $row) {
            if (isset($openByType[(string) $row['type']])) {
                $openByType[(string) $row['type']] = (int) $row['n'];
            }
        }

        $licences = array_fill_keys(self::LICENCE_WAITING, 0);
        foreach ($db->all('SELECT status, COUNT(*) AS n FROM licences WHERE status IN (' . $waitingIn . ') GROUP BY status', self::LICENCE_WAITING) as $row) {
            $licences[(string) $row['status']] = (int) $row['n'];
        }

        $latest = [
            'approval' => $db->all("SELECT id, name, email, company, created_at FROM users WHERE status = 'approval' ORDER BY created_at DESC, id DESC LIMIT " . self::LATEST),
            'tickets' => $db->all("SELECT t.id, t.type, t.product, t.title, t.created_at, t.user_id, u.name AS owner_name FROM tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.status = 'open' ORDER BY t.created_at DESC, t.id DESC LIMIT " . self::LATEST),
            'licences' => $db->all('SELECT l.id, l.product, l.tier, l.status, l.reference, l.created_at, l.user_id, u.name AS owner_name FROM licences l LEFT JOIN users u ON u.id = l.user_id WHERE l.status IN (' . $waitingIn . ') ORDER BY l.created_at DESC, l.id DESC LIMIT ' . self::LATEST, self::LICENCE_WAITING),
            'messages' => $db->all("SELECT id, name, company, topic, product, created_at FROM contact_messages WHERE status = 'new' ORDER BY created_at DESC, id DESC LIMIT " . self::LATEST),
        ];
        $licenceNames = [];
        foreach ($latest['licences'] as $l) {
            $licenceNames[(int) $l['id']] = LicenceController::optionName((string) $l['product'], (string) $l['tier']);
        }

        return $this->portal('admin/index', [
            'users' => $users,
            'openByType' => $openByType,
            'waiting' => self::waitingCounts($db),
            'licencesWaiting' => $licences,
            'assistant' => $this->assistantUsage($db),
            'latest' => $latest,
            'licenceNames' => $licenceNames,
            'ticketTypes' => TicketController::TYPES,
            'licenceStatuses' => LicenceController::STATUSES,
            'products' => DownloadController::productNames(),
            'topics' => ContactController::TOPICS,
        ], 'Admin overview');
    }

    /**
     * What waits on an administrator: accounts to approve, tickets nobody has triaged, licence requests not yet
     * issued and unread contact messages. Shared with the dashboard's admin callout.
     *
     * @return array{approval: int, tickets: int, licences: int, messages: int}
     */
    public static function waitingCounts(Database $db): array
    {
        return [
            'approval' => (int) $db->value("SELECT COUNT(*) FROM users WHERE status = 'approval'"),
            'tickets' => (int) $db->value("SELECT COUNT(*) FROM tickets WHERE status = 'open'"),
            'licences' => (int) $db->value('SELECT COUNT(*) FROM licences WHERE status IN (' . self::placeholders(self::LICENCE_WAITING) . ')', self::LICENCE_WAITING),
            'messages' => (int) $db->value("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'"),
        ];
    }

    /**
     * Assistant use today and over the last seven days (today included). Days are UTC, as chat_usage counts them;
     * conversations are counted by when they started.
     *
     * @return array{today: array{conversations: int, requests: int, tokens_in: int, tokens_out: int}, week: array{conversations: int, requests: int, tokens_in: int, tokens_out: int}}
     */
    private function assistantUsage(Database $db): array
    {
        $now = time();
        $today = gmdate('Y-m-d', $now);
        $weekStart = gmdate('Y-m-d', $now - 6 * 86400);
        $out = [];
        foreach (['today' => $today, 'week' => $weekStart] as $key => $day) {
            $usage = $db->one('SELECT COALESCE(SUM(requests), 0) AS requests, COALESCE(SUM(tokens_in), 0) AS tokens_in, COALESCE(SUM(tokens_out), 0) AS tokens_out FROM chat_usage WHERE day >= ?', [$day]);
            $since = (int) strtotime($day . 'T00:00:00Z');
            $out[$key] = [
                'conversations' => (int) $db->value('SELECT COUNT(*) FROM chat_conversations WHERE created_at >= ?', [$since]),
                'requests' => (int) ($usage['requests'] ?? 0),
                'tokens_in' => (int) ($usage['tokens_in'] ?? 0),
                'tokens_out' => (int) ($usage['tokens_out'] ?? 0),
            ];
        }
        /** @var array{today: array{conversations: int, requests: int, tokens_in: int, tokens_out: int}, week: array{conversations: int, requests: int, tokens_in: int, tokens_out: int}} $out */
        return $out;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Contact messages

    public function messages(Request $request): Response
    {
        $db = $this->app->db();
        $status = self::pick($request->queryString('status'), self::MESSAGE_STATUSES) ?: 'new';
        $query = ['page' => $request->queryString('page')];
        if ($status !== 'new') {
            $query['status'] = $status;
        }
        $pager = Paginator::query(
            $db,
            'SELECT id, name, email, company, topic, product, message, status, created_at FROM contact_messages WHERE status = ? ORDER BY created_at DESC, id DESC',
            [$status],
            $query,
            '/portal/admin/messages/',
            self::PER_PAGE
        );
        $counts = array_fill_keys(array_keys(self::MESSAGE_STATUSES), 0);
        foreach ($db->all('SELECT status, COUNT(*) AS n FROM contact_messages GROUP BY status') as $row) {
            if (isset($counts[(string) $row['status']])) {
                $counts[(string) $row['status']] = (int) $row['n'];
            }
        }
        return $this->portal('admin/messages/index', [
            'pager' => $pager,
            'status' => $status,
            'statuses' => self::MESSAGE_STATUSES,
            'counts' => $counts,
            'topics' => ContactController::TOPICS,
            'products' => DownloadController::productNames(),
            'replySubject' => 'Re: Your message to ' . $this->app->name(),
        ], 'Messages');
    }

    /** Moves one message to new, read or archived; back to the tab (and page) it was on. */
    public function message(Request $request): Response
    {
        $admin = $this->currentUser();
        $row = $this->app->db()->one('SELECT id, status FROM contact_messages WHERE id = ?', [$request->attrInt('id')]);
        if ($row === null) {
            return $this->notFound();
        }
        $id = (int) $row['id'];
        $old = (string) $row['status'];
        $page = (int) $request->input('page');
        $back = self::messagesPath($old, $page >= 2 && $page <= 100000 ? $page : 1);
        $status = self::pick($request->input('status'), self::MESSAGE_STATUSES);
        // The buttons post fixed values, so anything else is refused with a message only: the inbox has no field
        // to show an error beside, and an error kept in the session would appear on the next form that reads one.
        if ($status === '') {
            return $this->redirect($back, 'danger', 'That message was not changed. Choose new, read or archived.');
        }
        $label = mb_strtolower(self::MESSAGE_STATUSES[$status]);
        if ($status === $old) {
            return $this->redirect($back, 'info', 'That message is already marked ' . $label . '.');
        }
        // Written only from the status this request read, so the audit row's "from" is what really changed.
        if ($this->app->db()->update('contact_messages', ['status' => $status], ['id' => $id, 'status' => $old]) !== 1) {
            return $this->redirect($back, 'info', 'That message was moved by someone else while you were looking at it, so nothing changed.');
        }
        Audit::log($this->app, (int) $admin['id'], 'message.status', (string) $id, ['from' => $old, 'to' => $status]);
        return $this->redirect($back, 'success', $status === 'archived' ? 'Message archived.' : 'Message marked as ' . $label . '.');
    }

    public static function messagesPath(string $status, int $page = 1): string
    {
        $q = [];
        if ($status !== 'new' && isset(self::MESSAGE_STATUSES[$status])) {
            $q['status'] = $status;
        }
        if ($page > 1) {
            $q['page'] = (string) $page;
        }
        return '/portal/admin/messages/' . ($q !== [] ? '?' . http_build_query($q) : '');
    }

    // ---------------------------------------------------------------------------------------------------------
    // Assistant conversations

    /**
     * The conversation list; with ?conversation=<id> one transcript (the same page chat() serves), so a
     * transcript can always be opened from the list whether or not the router can match its own path.
     */
    public function chats(Request $request): Response
    {
        $conversation = $request->queryString('conversation');
        if ($conversation !== '') {
            return $this->transcript($conversation);
        }
        $db = $this->app->db();
        $refused = $request->queryString('refused') === '1';
        $params = [];
        $where = '';
        if ($refused) {
            $where = ' WHERE EXISTS (SELECT 1 FROM chat_messages m WHERE m.conversation_id = c.id AND m.verdict IN (' . self::placeholders(self::REFUSED_VERDICTS) . '))';
            $params = self::REFUSED_VERDICTS;
        }
        $query = ['page' => $request->queryString('page')];
        if ($refused) {
            $query['refused'] = '1';
        }
        $pager = Paginator::query(
            $db,
            'SELECT c.id, c.user_id, c.page, c.turns, c.tokens_in, c.tokens_out, c.created_at, c.last_at, u.name AS user_name, u.email AS user_email'
            . ' FROM chat_conversations c LEFT JOIN users u ON u.id = c.user_id' . $where
            . ' ORDER BY c.last_at DESC, c.id DESC',
            $params,
            $query,
            '/portal/admin/chats/',
            self::PER_PAGE
        );

        // Verdict and feedback counts for this page's conversations only, in one query.
        $ids = array_map(static fn (array $c): string => (string) $c['id'], $pager->items);
        $counts = [];
        foreach ($ids as $id) {
            $counts[$id] = array_fill_keys(self::COUNTED_VERDICTS, 0) + ['feedback' => 0];
        }
        if ($ids !== []) {
            $rows = $db->all(
                "SELECT conversation_id, CASE WHEN role = 'feedback' THEN 'feedback' ELSE verdict END AS kind, COUNT(*) AS n FROM chat_messages"
                . ' WHERE conversation_id IN (' . self::placeholders($ids) . ")"
                . " AND (role = 'feedback' OR verdict IN (" . self::placeholders(self::COUNTED_VERDICTS) . '))'
                . " GROUP BY conversation_id, CASE WHEN role = 'feedback' THEN 'feedback' ELSE verdict END",
                [...$ids, ...self::COUNTED_VERDICTS]
            );
            foreach ($rows as $row) {
                $cid = (string) $row['conversation_id'];
                $kind = (string) $row['kind'];
                if (isset($counts[$cid][$kind])) {
                    $counts[$cid][$kind] = (int) $row['n'];
                }
            }
        }

        $links = [];
        foreach ($ids as $id) {
            $links[$id] = $this->chatPath($id);
        }

        return $this->portal('admin/chats/index', [
            'pager' => $pager,
            'refused' => $refused,
            'counts' => $counts,
            'links' => $links,
            'verdicts' => self::VERDICTS,
            'counted' => self::COUNTED_VERDICTS,
        ], 'Assistant chats');
    }

    /** One conversation's transcript in order: visitor, assistant (with its verdict) and feedback rows. */
    public function chat(Request $request): Response
    {
        return $this->transcript($request->attr('id'));
    }

    /**
     * Where a transcript is opened: its own route when the router matches that path to chat(), otherwise the
     * list's ?conversation= form of the same page.
     */
    public function chatPath(string $id): string
    {
        $path = '/portal/admin/chats/' . $id . '/';
        if ($this->transcriptRouted === null) {
            $match = $this->app->router->match('GET', $path);
            $this->transcriptRouted = isset($match['route']) && $match['route']['handler'] === [self::class, 'chat'];
        }
        return $this->transcriptRouted ? $path : '/portal/admin/chats/?conversation=' . rawurlencode($id);
    }

    private function transcript(string $id): Response
    {
        if (!preg_match('/^[a-f0-9]{32}\z/', $id)) {
            return $this->notFound();
        }
        $db = $this->app->db();
        $conversation = $db->one(
            'SELECT c.id, c.user_id, c.page, c.turns, c.tokens_in, c.tokens_out, c.created_at, c.last_at, u.name AS user_name, u.email AS user_email'
            . ' FROM chat_conversations c LEFT JOIN users u ON u.id = c.user_id WHERE c.id = ?',
            [$id]
        );
        if ($conversation === null) {
            return $this->notFound();
        }
        $messages = $db->all('SELECT id, role, content, verdict, tokens_in, tokens_out, created_at FROM chat_messages WHERE conversation_id = ? ORDER BY id', [$id]);
        return $this->portal('admin/chats/show', [
            'conversation' => $conversation,
            'messages' => $messages,
            'verdicts' => self::VERDICTS,
            'verdictBadges' => self::VERDICT_BADGES,
        ], 'Assistant conversation');
    }

    /**
     * A feedback row's content as its rating ("up", "down", or "" when it names neither) and note. The content is
     * "up" or "down" with an optional ": note", or a JSON object {"rating": …, "note": …}; a JSON object never
     * reaches the page as JSON.
     *
     * @return array{rating: string, note: string}
     */
    public static function feedback(string $content): array
    {
        $content = trim($content);
        if (str_starts_with($content, '{')) {
            $data = json_decode($content, true);
            if (is_array($data)) {
                $rating = is_string($data['rating'] ?? null) ? trim($data['rating']) : '';
                $note = is_string($data['note'] ?? null) ? trim($data['note']) : '';
                return ['rating' => in_array($rating, ['up', 'down'], true) ? $rating : '', 'note' => $note];
            }
        }
        $parts = explode(':', $content, 2);
        $rating = trim($parts[0]);
        if (!in_array($rating, ['up', 'down'], true)) {
            return ['rating' => '', 'note' => $content];
        }
        return ['rating' => $rating, 'note' => trim($parts[1] ?? '')];
    }

    // ---------------------------------------------------------------------------------------------------------
    // Audit log

    public function audit(Request $request): Response
    {
        $db = $this->app->db();
        $prefix = trim($request->queryString('action'));
        $typed = mb_check_encoding($prefix, 'UTF-8') ? mb_substr($prefix, 0, 64) : '';
        $invalid = $prefix !== '' && !preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $prefix);
        if ($invalid) {
            $prefix = '';
        }
        $sql = 'SELECT a.id, a.user_id, a.action, a.subject, a.meta, a.created_at, u.name AS actor_name, u.email AS actor_email'
            . ' FROM audit_log a LEFT JOIN users u ON u.id = a.user_id';
        $params = [];
        if ($prefix !== '') {
            $sql .= " WHERE a.action LIKE ? ESCAPE '!'";
            // Database::like() wraps the escaped needle in %…%; a prefix match keeps only the trailing one.
            $params[] = substr(Database::like($prefix), 1);
        }
        $sql .= ' ORDER BY a.created_at DESC, a.id DESC';
        $query = ['page' => $request->queryString('page')];
        if ($prefix !== '') {
            $query['action'] = $prefix;
        }
        $pager = Paginator::query($db, $sql, $params, $query, '/portal/admin/audit/', 50);

        $groups = [];
        foreach ($db->all('SELECT action FROM audit_log GROUP BY action ORDER BY action LIMIT 500') as $row) {
            $first = explode('.', (string) $row['action'], 2)[0];
            if ($first !== '' && preg_match('/^[A-Za-z0-9_-]{1,32}$/', $first)) {
                $groups[$first] = true;
            }
        }

        return $this->portal('admin/audit', [
            'pager' => $pager,
            'prefix' => $prefix,
            'invalid' => $invalid,
            'typed' => $typed,
            'groups' => array_keys($groups),
        ], 'Audit log');
    }

    /** An audit row's meta, pretty-printed when it is JSON; the caller escapes it. */
    public static function prettyMeta(?string $meta): string
    {
        if ($meta === null || $meta === '') {
            return '';
        }
        $data = json_decode($meta, true);
        if (!is_array($data)) {
            return $meta;
        }
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** The admin page an audit row's subject points at, when its action names a kind of record with one. */
    public static function subjectUrl(string $action, ?string $subject): ?string
    {
        if ($subject === null || !preg_match('/^\d{1,18}$/', $subject)) {
            return null;
        }
        $kind = explode('.', $action, 2)[0];
        return match ($kind) {
            'user', 'account' => '/portal/admin/users/' . $subject . '/',
            'ticket' => '/portal/tickets/' . $subject . '/',
            'licence' => '/portal/admin/licences/' . $subject . '/',
            'release' => '/portal/admin/releases/' . $subject . '/',
            'message' => '/portal/admin/messages/',
            default => null,
        };
    }

    // ---------------------------------------------------------------------------------------------------------
    // Helpers

    /**
     * The value when it is a key of the allow-list, else ''.
     *
     * @param array<string, mixed> $allowed
     */
    public static function pick(string $value, array $allowed): string
    {
        return $value !== '' && array_key_exists($value, $allowed) ? $value : '';
    }

    /**
     * A mailto: link to an address, each part percent-encoded so characters such as ? or & stay in the address;
     * none for an address under the reserved .invalid domain (a deleted account's), which can never receive mail.
     */
    public static function mailto(string $email, string $subject = ''): ?string
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || str_ends_with(strtolower($email), '.invalid')) {
            return null;
        }
        $at = strrpos($email, '@');
        $address = rawurlencode(substr($email, 0, (int) $at)) . '@' . rawurlencode(substr($email, (int) $at + 1));
        return 'mailto:' . $address . ($subject !== '' ? '?subject=' . rawurlencode($subject) : '');
    }

    /** @param list<mixed> $values */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, max(1, count($values)), '?'));
    }

    /** @return array<string, mixed> */
    private function currentUser(): array
    {
        $user = $this->app->auth()->user();
        if ($user === null) {
            throw new \LogicException('Administration needs the auth middleware');
        }
        return $user;
    }
}
