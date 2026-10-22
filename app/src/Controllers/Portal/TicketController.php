<?php

declare(strict_types=1);

namespace Psc\Controllers\Portal;

use Psc\App;
use Psc\Content\Content;
use Psc\Controllers\Controller;
use Psc\Db\Database;
use Psc\Http\Request;
use Psc\Http\Response;
use Psc\Support\Audit;
use Psc\Support\Names;
use Psc\Support\Paginator;
use Psc\Support\Uploads;

/**
 * Tickets (bug reports, feature requests, questions) and the feature-request board.
 *
 * Who may do what is decided here from the stored ticket and the signed-in account, never from the form:
 *   read     the owner, administrators, and every signed-in user when the ticket is public
 *   comment  the owner and administrators; any signed-in user on a public feature request
 *   attach   the owner and administrators
 *   vote     any signed-in user, on public feature requests only
 * Anyone else gets a 404, so a ticket's existence is never revealed. Internal comments and their files are for
 * administrators only: never shown to clients, never counted in comment_count, never emailed, and they leave the
 * ticket row untouched (its columns, last_activity_at and updated_at among them, reach the client). Bugs and
 * questions start private; feature requests start public.
 */
final class TicketController extends Controller
{
    public const TYPES = ['bug' => 'Bug report', 'feature' => 'Feature request', 'question' => 'Question'];

    public const STATUSES = [
        'open' => 'Open',
        'triaged' => 'Triaged',
        'planned' => 'Planned',
        'in_progress' => 'In progress',
        'done' => 'Done',
        'declined' => 'Declined',
        'closed' => 'Closed',
    ];

    /**
     * What each status means, in the client's words (ticket page and status emails). Every type of ticket uses the
     * same words, so none of them claims more than the status itself says (a done question has no download).
     */
    public const STATUS_HELP = [
        'open' => 'Waiting to be read.',
        'triaged' => 'Read and sorted, and in the queue.',
        'planned' => 'On the list for a future update.',
        'in_progress' => 'Being worked on now.',
        'done' => 'Finished: fixed, built or answered. Any details are in the conversation.',
        'declined' => 'Not going ahead. Any reasons are in the conversation.',
        'closed' => 'Closed. Add a reply if you need to pick it up again.',
    ];

    /** Statuses that end a ticket: entering one sets closed_at, leaving them clears it. */
    public const CLOSED = ['done', 'declined', 'closed'];

    public const SEVERITIES = [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'show_stopper' => 'Show-stopper',
    ];

    public const SEVERITY_HELP = [
        'low' => 'Cosmetic, or a small annoyance.',
        'normal' => 'Something does not work, but there is a way round it.',
        'high' => 'A feature I rely on does not work.',
        'show_stopper' => 'It stops a show or a rehearsal.',
    ];

    public const VISIBILITIES = ['private' => 'Private', 'public' => 'Public'];

    public const MAX_FILES = 5;

    /** Per-user limits on the endpoints that write rows: [hits, window seconds]. */
    private const LIMIT_TICKETS = [20, 86400];
    private const LIMIT_COMMENTS = [60, 3600];
    private const LIMIT_VOTES = [100, 3600];

    private const BOARD_SORTS = [
        'votes' => 't.votes DESC, t.last_activity_at DESC, t.id DESC',
        'newest' => 't.created_at DESC, t.id DESC',
        'updated' => 't.last_activity_at DESC, t.id DESC',
    ];

    public const BOARD_SORT_LABELS = ['votes' => 'Most votes', 'newest' => 'Newest', 'updated' => 'Recently active'];

    private const PRODUCT_NAMES = [
        'patterns' => 'Patterns',
        'stagewatch' => 'StageWatch',
        'agmessage' => 'AGMessage',
        'playmap' => 'PlayMap',
    ];

    private const PER_PAGE = 20;

    // ---------------------------------------------------------------------------------------------------------
    // Pages

    /** My tickets: tabs by type, a status filter, the most recently active first. */
    public function index(Request $request): Response
    {
        $user = $this->currentUser();
        $uid = (int) $user['id'];
        $type = self::pick($request->queryString('type'), self::TYPES);
        $status = self::pick($request->queryString('status'), self::STATUSES);

        $where = ['user_id = ?'];
        $params = [$uid];
        if ($type !== '') {
            $where[] = 'type = ?';
            $params[] = $type;
        }
        if ($status !== '') {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        $sql = 'SELECT id, type, product, title, status, severity, visibility, votes, comment_count, last_activity_at, created_at'
            . ' FROM tickets WHERE ' . implode(' AND ', $where) . ' ORDER BY last_activity_at DESC, id DESC';
        $query = array_filter(['type' => $type, 'status' => $status, 'page' => $request->queryString('page')], static fn (string $v): bool => $v !== '');
        $pager = Paginator::query($this->app->db(), $sql, $params, $query, '/portal/tickets/', self::PER_PAGE);

        $counts = ['' => 0] + array_fill_keys(array_keys(self::TYPES), 0);
        foreach ($this->app->db()->all('SELECT type, COUNT(*) AS n FROM tickets WHERE user_id = ? GROUP BY type', [$uid]) as $row) {
            if (isset($counts[(string) $row['type']])) {
                $counts[(string) $row['type']] = (int) $row['n'];
            }
            $counts[''] += (int) $row['n'];
        }

        return $this->portal('portal/tickets/index', [
            'pager' => $pager,
            'type' => $type,
            'status' => $status,
            'counts' => $counts,
            'types' => self::TYPES,
            'statuses' => self::STATUSES,
            'products' => self::products(),
        ], 'Tickets');
    }

    /** Without a valid ?type= the chooser; with one, that type's form (?product= preselects the product). */
    public function create(Request $request): Response
    {
        $products = self::products();
        $type = self::pick($request->queryString('type'), self::TYPES);
        $product = self::pick($request->queryString('product'), $products);
        $session = $this->app->session();
        if ($type === '') {
            $errors = $this->takeErrors();
            $session->takeOld();
            return $this->portal('portal/tickets/new', ['product' => $product, 'errors' => $errors], 'New ticket');
        }
        $titles = ['bug' => 'Report a bug', 'feature' => 'Suggest a feature', 'question' => 'Ask a question'];
        return $this->portal('portal/tickets/create', [
            'type' => $type,
            'product' => $product,
            'products' => $products,
            'severities' => self::SEVERITIES,
            'severityHelp' => self::SEVERITY_HELP,
            'errors' => $this->takeErrors(),
            'old' => $session->takeOld(),
            'allowed' => $this->allowedExtensions(),
            'maxMb' => $this->maxMb(),
            'maxFiles' => self::MAX_FILES,
        ], $titles[$type]);
    }

    public function store(Request $request): Response
    {
        $user = $this->currentUser();
        $uid = (int) $user['id'];
        $type = $request->input('type');
        if (!isset(self::TYPES[$type])) {
            return $this->backWithErrors('/portal/tickets/new/', $request, ['type' => 'Choose what kind of ticket this is.'], 'Choose what kind of ticket you want to open.');
        }
        $form = '/portal/tickets/new/?type=' . $type . '#form';
        [$data, $errors] = $this->validateTicket($type, $request);
        // Only the bug form offers attachments; files sent with any other type are not read.
        $files = $type === 'bug' ? Uploads::files(self::fileEntry($request, 'attachments')) : [];
        if (count($files) > self::MAX_FILES) {
            $errors['attachments'] = 'Attach up to ' . self::MAX_FILES . ' files.';
        }
        if ($errors !== []) {
            return $this->backWithErrors($form, $request, $errors);
        }
        [$limit, $window] = self::LIMIT_TICKETS;
        if (!$this->app->limiter()->hit('ticket:' . $uid, $limit, $window)) {
            return $this->backWithErrors($form, $request, [], 'You have opened ' . $limit . ' tickets today, which is the daily limit. Add to one of your open tickets, or try again tomorrow.');
        }
        $saved = $this->storeUploads($files);
        if ($saved['error'] !== null) {
            return $this->backWithErrors($form, $request, ['attachments' => $saved['error']]);
        }

        $now = time();
        $row = [
            'user_id' => $uid,
            'type' => $type,
            'product' => $data['product'],
            'title' => $data['title'],
            'body' => $data['body'],
            'status' => 'open',
            'severity' => $type === 'bug' ? $data['severity'] : null,
            'app_version' => $type === 'bug' && $data['app_version'] !== '' ? $data['app_version'] : null,
            'environment' => $type === 'bug' && $data['environment'] !== '' ? $data['environment'] : null,
            'visibility' => $type === 'feature' ? 'public' : 'private',
            'votes' => 0,
            'comment_count' => 0,
            'last_activity_at' => $now,
            'closed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        try {
            $id = $this->app->db()->transaction(static function (Database $db) use ($row, $saved, $uid, $now): int {
                $id = $db->insert('tickets', $row);
                self::insertAttachments($db, $id, null, $uid, $saved['files'], $now);
                return $id;
            });
        } catch (\Throwable $e) {
            self::discard($saved['files']);
            throw $e;
        }
        Audit::log($this->app, $uid, 'ticket.created', (string) $id, ['type' => $type, 'files' => count($saved['files'])]);

        $this->app->auth()->notifyAdmins('New ' . mb_strtolower(self::TYPES[$type]) . ' #' . $id . ': ' . $data['title'], 'ticket-new-admin', [
            'ticket' => ['id' => $id] + $row,
            'typeLabel' => self::TYPES[$type],
            'productLabel' => self::products()[$data['product']],
            'severityLabel' => $row['severity'] !== null ? self::SEVERITIES[$row['severity']] : '',
            'authorName' => (string) $user['name'],
            'authorEmail' => (string) $user['email'],
            'fileCount' => count($saved['files']),
            'url' => $this->app->baseUrl() . self::path($id),
        ]);

        $done = [
            'bug' => 'Thanks — your bug report is in. You will get an email when someone replies.',
            'feature' => 'Thanks — your idea is on the board, where other clients can vote for it.',
            'question' => 'Thanks — your question is in. You will get an email when someone replies.',
        ];
        return $this->redirect(self::path($id), 'success', $done[$type]);
    }

    public function show(Request $request): Response
    {
        $user = $this->currentUser();
        $ticket = $this->findTicket($request->attrInt('id'));
        if ($ticket === null || !self::canView($ticket, $user)) {
            return $this->notFound();
        }
        $db = $this->app->db();
        $id = (int) $ticket['id'];
        $admin = self::isAdmin($user);
        $uid = (int) $user['id'];

        $comments = $db->all(
            'SELECT c.id, c.user_id, c.body, c.is_staff, c.is_internal, c.created_at, u.name AS author_name,'
            . " CASE WHEN u.id IS NULL OR u.email LIKE '%.invalid' THEN 1 ELSE 0 END AS author_gone"
            . ' FROM ticket_comments c LEFT JOIN users u ON u.id = c.user_id'
            . ' WHERE c.ticket_id = ?' . ($admin ? '' : ' AND c.is_internal = 0')
            . ' ORDER BY c.created_at, c.id',
            [$id]
        );
        $attachments = $db->all(
            'SELECT a.id, a.comment_id, a.original_name, a.size, a.created_at'
            . ' FROM ticket_attachments a LEFT JOIN ticket_comments c ON c.id = a.comment_id'
            . ' WHERE a.ticket_id = ?' . ($admin ? '' : ' AND (a.comment_id IS NULL OR c.is_internal = 0)')
            . ' ORDER BY a.id',
            [$id]
        );
        $filesByComment = [];
        $ticketFiles = [];
        foreach ($attachments as $a) {
            if ($a['comment_id'] === null) {
                $ticketFiles[] = $a;
            } else {
                $filesByComment[(int) $a['comment_id']][] = $a;
            }
        }
        foreach ($comments as $i => $c) {
            $comments[$i]['author'] = self::commentAuthor($c, $uid, $admin);
            $comments[$i]['files'] = $filesByComment[(int) $c['id']] ?? [];
        }

        $owner = $this->app->auth()->find((int) $ticket['user_id']);
        $publicFeature = self::isPublicFeature($ticket);
        $voted = $publicFeature && $db->value('SELECT COUNT(*) FROM ticket_votes WHERE ticket_id = ? AND user_id = ?', [$id, $uid]) > 0;
        $old = $this->app->session()->takeOld();
        $form = $old['form'] ?? '';

        return $this->portal('portal/tickets/show', [
            'ticket' => $ticket,
            'comments' => $comments,
            'ticketFiles' => $ticketFiles,
            'isAdmin' => $admin,
            'isOwner' => (int) $ticket['user_id'] === $uid,
            'ownerLabel' => self::ownerLabel($owner, $uid, $admin),
            'owner' => $admin ? $owner : null,
            'canComment' => self::canComment($ticket, $user),
            'canAttach' => self::canAttach($ticket, $user),
            'canVote' => $publicFeature,
            'voted' => $voted,
            'errors' => $this->takeErrors(),
            'oldComment' => $form === 'comment' ? $old : [],
            'oldManage' => $form === 'manage' ? $old : [],
            'types' => self::TYPES,
            'statuses' => self::STATUSES,
            'statusHelp' => self::STATUS_HELP,
            'severities' => self::SEVERITIES,
            'visibilities' => self::VISIBILITIES,
            'products' => self::products(),
            'allowed' => $this->allowedExtensions(),
            'maxMb' => $this->maxMb(),
            'maxFiles' => self::MAX_FILES,
        ], (string) $ticket['title']);
    }

    public function comment(Request $request): Response
    {
        $user = $this->currentUser();
        $ticket = $this->findTicket($request->attrInt('id'));
        if ($ticket === null || !self::canView($ticket, $user)) {
            return $this->notFound();
        }
        if (!self::canComment($ticket, $user)) {
            return $this->forbidden();
        }
        $id = (int) $ticket['id'];
        $uid = (int) $user['id'];
        $admin = self::isAdmin($user);
        $back = self::path($id) . '#reply';

        $body = self::text($request, 'body');
        $internal = $admin && $request->input('internal') === '1';
        $files = Uploads::files(self::fileEntry($request, 'attachments'));
        $errors = [];
        $len = mb_strlen($body);
        if ($len < 2 || $len > 5000) {
            $errors['body'] = $len < 2 ? 'Write your reply.' : 'Keep your reply under 5,000 characters.';
        }
        if ($files !== [] && !self::canAttach($ticket, $user)) {
            $errors['attachments'] = 'Only the person who opened this ticket can attach files to it.';
        } elseif (count($files) > self::MAX_FILES) {
            $errors['attachments'] = 'Attach up to ' . self::MAX_FILES . ' files at a time.';
        }
        if ($errors !== []) {
            return $this->backWithErrors($back, $request, $errors);
        }
        [$limit, $window] = self::LIMIT_COMMENTS;
        if (!$this->app->limiter()->hit('ticket-comment:' . $uid, $limit, $window)) {
            return $this->backWithErrors($back, $request, [], 'You have sent a lot of replies in the last hour. Please wait a little, then try again.');
        }
        $saved = $this->storeUploads($files);
        if ($saved['error'] !== null) {
            return $this->backWithErrors($back, $request, ['attachments' => $saved['error']]);
        }
        try {
            $commentId = self::addComment($this->app->db(), $ticket, $uid, $admin, $body, $internal, $saved['files']);
        } catch (\Throwable $e) {
            self::discard($saved['files']);
            throw $e;
        }

        $owner = $this->app->auth()->find((int) $ticket['user_id']);
        if ($admin) {
            Audit::log($this->app, $uid, 'ticket.staff_comment', (string) $id, ['comment' => $commentId, 'internal' => $internal]);
            if (!$internal && self::mayEmail($owner, $uid)) {
                self::sendReply($this->app, $ticket, $owner, (string) $user['name'], $body);
            }
        } else {
            $this->app->auth()->notifyAdmins('New reply on #' . $id . ': ' . $ticket['title'], 'ticket-comment-admin', [
                'ticket' => $ticket,
                'typeLabel' => self::TYPES[(string) $ticket['type']] ?? 'Ticket',
                'authorName' => (string) $user['name'],
                'authorEmail' => (string) $user['email'],
                'isOwner' => (int) $ticket['user_id'] === $uid,
                'body' => $body,
                'fileCount' => count($saved['files']),
                'url' => $this->app->baseUrl() . self::path($id) . '#comment-' . $commentId,
            ]);
        }
        return $this->redirect(self::path($id) . '#comment-' . $commentId, 'success', $internal ? 'Internal note added. Only staff can see it.' : 'Reply added.');
    }

    /** Toggles the signed-in user's vote on a public feature request; votes is recounted in the same transaction. */
    public function vote(Request $request): Response
    {
        $user = $this->currentUser();
        $ticket = $this->findTicket($request->attrInt('id'));
        if ($ticket === null || !self::canView($ticket, $user) || !self::isPublicFeature($ticket)) {
            return $this->notFound();
        }
        $id = (int) $ticket['id'];
        $uid = (int) $user['id'];
        [$limit, $window] = self::LIMIT_VOTES;
        if (!$this->app->limiter()->hit('ticket-vote:' . $uid, $limit, $window)) {
            return $this->redirect($this->voteReturn($request->input('return'), $id), 'warning', 'You have changed your votes many times in the last hour. Please wait a little, then try again.');
        }
        $added = $this->app->db()->transaction(static function (Database $db) use ($id, $uid): bool {
            $added = false;
            if ($db->exec('DELETE FROM ticket_votes WHERE ticket_id = ? AND user_id = ?', [$id, $uid]) === 0) {
                try {
                    $db->insert('ticket_votes', ['ticket_id' => $id, 'user_id' => $uid, 'created_at' => time()]);
                    $added = true;
                } catch (\PDOException $e) {
                    // Only a duplicate key means a second click raced this one and its vote is already in (the
                    // toggle lands on "voted"); any other failure is not a vote and must not be reported as one.
                    if ((string) $e->getCode() !== '23000') {
                        throw $e;
                    }
                    $added = true;
                }
            }
            $db->exec('UPDATE tickets SET votes = (SELECT COUNT(*) FROM ticket_votes WHERE ticket_id = ?) WHERE id = ?', [$id, $id]);
            return $added;
        });
        return $this->redirect($this->voteReturn($request->input('return'), $id), 'success', $added ? 'Thanks — your vote is in.' : 'Your vote was taken back.');
    }

    /** Sends an attachment to someone who may see its ticket (and, for an internal note's file, only to staff). */
    public function attachment(Request $request): Response
    {
        $user = $this->currentUser();
        $db = $this->app->db();
        $file = $db->one('SELECT * FROM ticket_attachments WHERE id = ?', [$request->attrInt('id')]);
        if ($file === null) {
            return $this->notFound();
        }
        $ticket = $this->findTicket((int) $file['ticket_id']);
        if ($ticket === null || !self::canView($ticket, $user)) {
            return $this->notFound();
        }
        if ($file['comment_id'] !== null) {
            $comment = $db->one('SELECT is_internal FROM ticket_comments WHERE id = ? AND ticket_id = ?', [(int) $file['comment_id'], (int) $ticket['id']]);
            if ($comment === null || ((int) $comment['is_internal'] === 1 && !self::isAdmin($user))) {
                return $this->notFound();
            }
        }
        $stored = (string) $file['stored_name'];
        if (!preg_match('/^[a-f0-9]{32}(\.[a-z0-9]{1,10})?$/', $stored)) {
            return $this->notFound();
        }
        $path = $this->app->storage->path('uploads/tickets/' . $stored);
        if (!is_file($path)) {
            return $this->notFound();
        }
        $mime = self::servedMime((string) $file['mime']);
        $response = Response::stream(static function () use ($path): void {
            readfile($path);
        }, $mime);
        foreach (Uploads::downloadHeaders((string) $file['original_name'], (int) filesize($path), $mime) as $name => $value) {
            $response->header($name, $value);
        }
        return $response;
    }

    /** The public feature requests: filter by product and status, search titles, sort by votes, newest or activity. */
    public function board(Request $request): Response
    {
        $user = $this->currentUser();
        $f = self::boardFilters($request->query);
        $where = ["t.type = 'feature'", "t.visibility = 'public'"];
        $params = [(int) $user['id']];
        if ($f['product'] !== '') {
            $where[] = 't.product = ?';
            $params[] = $f['product'];
        }
        if ($f['status'] !== '') {
            $where[] = 't.status = ?';
            $params[] = $f['status'];
        }
        if ($f['q'] !== '') {
            $where[] = "t.title LIKE ? ESCAPE '!'";
            $params[] = Database::like($f['q']);
        }
        $sql = 'SELECT t.id, t.title, t.product, t.status, t.votes, t.comment_count, t.created_at, t.last_activity_at,'
            . ' (SELECT COUNT(*) FROM ticket_votes v WHERE v.ticket_id = t.id AND v.user_id = ?) AS voted'
            . ' FROM tickets t WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . self::BOARD_SORTS[$f['sort']];
        $query = self::boardQuery($f) + array_filter(['page' => $request->queryString('page')], static fn (string $v): bool => $v !== '');
        $pager = Paginator::query($this->app->db(), $sql, $params, $query, '/portal/ideas/', self::PER_PAGE);
        $here = '/portal/ideas/';
        $hereQuery = self::boardQuery($f) + ($pager->page > 1 ? ['page' => (string) $pager->page] : []);
        if ($hereQuery !== []) {
            $here .= '?' . http_build_query($hereQuery);
        }

        return $this->portal('portal/tickets/board', [
            'pager' => $pager,
            'filters' => $f,
            'filtered' => $f['product'] !== '' || $f['status'] !== '' || $f['q'] !== '',
            'returnTo' => $here,
            'products' => self::products(),
            'statuses' => self::STATUSES,
            'sorts' => self::BOARD_SORT_LABELS,
        ], 'Feature requests');
    }

    // ---------------------------------------------------------------------------------------------------------
    // Permissions (shared with the administration pages)

    /** @param array<string, mixed> $user */
    public static function isAdmin(array $user): bool
    {
        return ($user['role'] ?? '') === 'admin';
    }

    /** @param array<string, mixed> $ticket */
    public static function isPublicFeature(array $ticket): bool
    {
        return $ticket['type'] === 'feature' && $ticket['visibility'] === 'public';
    }

    /**
     * @param array<string, mixed> $ticket
     * @param array<string, mixed> $user
     */
    public static function canView(array $ticket, array $user): bool
    {
        return self::isAdmin($user)
            || (int) $ticket['user_id'] === (int) $user['id']
            || $ticket['visibility'] === 'public';
    }

    /**
     * @param array<string, mixed> $ticket
     * @param array<string, mixed> $user
     */
    public static function canComment(array $ticket, array $user): bool
    {
        return self::isAdmin($user) || (int) $ticket['user_id'] === (int) $user['id'] || self::isPublicFeature($ticket);
    }

    /**
     * @param array<string, mixed> $ticket
     * @param array<string, mixed> $user
     */
    public static function canAttach(array $ticket, array $user): bool
    {
        return self::isAdmin($user) || (int) $ticket['user_id'] === (int) $user['id'];
    }

    // ---------------------------------------------------------------------------------------------------------
    // Shared helpers

    /** @return array<string, string> product key => name: the four products, then the website */
    public static function products(): array
    {
        $out = [];
        foreach (Content::PRODUCTS as $key) {
            $name = Content::product($key)['name'] ?? null;
            $out[$key] = is_string($name) && $name !== '' ? $name : (self::PRODUCT_NAMES[$key] ?? ucfirst($key));
        }
        $out['website'] = 'This website and the client portal';
        return $out;
    }

    public static function path(int $id): string
    {
        return '/portal/tickets/' . $id . '/';
    }

    /**
     * The value when it is a key of the allow-list, else ''.
     *
     * @param array<string, mixed> $allowed
     */
    public static function pick(string $value, array $allowed): string
    {
        return $value !== '' && array_key_exists($value, $allowed) ? $value : '';
    }

    /** A form's multi-line text: UTF-8 only, Unix line ends, no control characters other than tab and newline. */
    public static function text(Request $request, string $key): string
    {
        $v = str_replace(["\r\n", "\r"], "\n", $request->input($key));
        if (!mb_check_encoding($v, 'UTF-8')) {
            return '';
        }
        return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v));
    }

    /** A single-line value: whitespace runs (newlines included) become one space, so it is safe in a subject line. */
    public static function line(Request $request, string $key): string
    {
        $v = $request->input($key);
        if (!mb_check_encoding($v, 'UTF-8')) {
            return '';
        }
        return trim((string) preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $v));
    }

    /** A single-line value without invisible or reordering characters (Names::INVISIBLE), its spaces tidied again. */
    public static function visible(string $value): string
    {
        return Names::visible($value);
    }

    /**
     * Adds a comment with its stored files, keeping comment_count (public comments only), last_activity_at and
     * updated_at in step in the same transaction. Returns the comment id. An internal note leaves the ticket row as
     * it was: every column of it reaches the owner somewhere (the ticket page, the lists, their data export), so a
     * changed one would tell them that hidden activity exists.
     *
     * @param array<string, mixed> $ticket
     * @param list<array{original_name: string, stored_name: string, size: int, mime: string, path: string}> $files
     */
    public static function addComment(Database $db, array $ticket, int $authorId, bool $isStaff, string $body, bool $internal, array $files = []): int
    {
        $ticketId = (int) $ticket['id'];
        return $db->transaction(static function (Database $db) use ($ticketId, $authorId, $isStaff, $body, $internal, $files): int {
            $now = time();
            $commentId = $db->insert('ticket_comments', [
                'ticket_id' => $ticketId,
                'user_id' => $authorId,
                'body' => $body,
                'is_staff' => $isStaff ? 1 : 0,
                'is_internal' => $internal ? 1 : 0,
                'created_at' => $now,
            ]);
            self::insertAttachments($db, $ticketId, $commentId, $authorId, $files, $now);
            if (!$internal) {
                $db->exec(
                    'UPDATE tickets SET comment_count = (SELECT COUNT(*) FROM ticket_comments WHERE ticket_id = ? AND is_internal = 0),'
                    . ' updated_at = ?, last_activity_at = ? WHERE id = ?',
                    [$ticketId, $now, $now, $ticketId]
                );
            }
            return $commentId;
        });
    }

    /**
     * Whether the ticket's owner should get an email about something another person did.
     *
     * @param array<string, mixed>|null $owner
     */
    public static function mayEmail(?array $owner, int $actorId): bool
    {
        return $owner !== null && (int) $owner['id'] !== $actorId && $owner['status'] !== 'disabled';
    }

    /**
     * Emails the owner a staff reply (never called for an internal note).
     *
     * @param array<string, mixed> $ticket
     * @param array<string, mixed> $owner
     */
    public static function sendReply(App $app, array $ticket, array $owner, string $staffName, string $body): void
    {
        $id = (int) $ticket['id'];
        $app->mailer()->send((string) $owner['email'], 'New reply on your ticket #' . $id . ': ' . $ticket['title'], 'ticket-reply', [
            'name' => (string) $owner['name'],
            'ticket' => $ticket,
            'typeLabel' => self::TYPES[(string) $ticket['type']] ?? 'Ticket',
            'staffName' => $staffName,
            'body' => $body,
            'url' => $app->baseUrl() . self::path($id),
        ]);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Internals

    /** @return array<string, mixed> the signed-in account (the auth middleware guarantees one) */
    private function currentUser(): array
    {
        $user = $this->app->auth()->user();
        if ($user === null) {
            throw new \LogicException('Ticket pages need the auth middleware');
        }
        return $user;
    }

    /** @return array<string, mixed>|null */
    private function findTicket(int $id): ?array
    {
        return $id > 0 ? $this->app->db()->one('SELECT * FROM tickets WHERE id = ?', [$id]) : null;
    }

    /**
     * Checks one type's form and builds the Markdown body.
     *
     * @return array{0: array{title: string, product: string, body: string, severity: string, app_version: string, environment: string}, 1: array<string, string>}
     */
    private function validateTicket(string $type, Request $request): array
    {
        $errors = [];
        $title = self::visible(self::line($request, 'title'));
        $tlen = mb_strlen($title);
        if ($tlen < 5 || $tlen > 150) {
            $errors['title'] = $tlen < 5 ? 'Give it a short title of at least 5 characters.' : 'Keep the title under 150 characters.';
        }
        $product = self::pick($request->input('product'), self::products());
        if ($product === '') {
            $errors['product'] = 'Choose the product this is about.';
        }
        $long = static function (string $key, bool $required, string $missing) use ($request, &$errors): string {
            $v = self::text($request, $key);
            $len = mb_strlen($v);
            if ($required && $len < 10) {
                $errors[$key] = $missing;
            } elseif ($len > 5000) {
                $errors[$key] = 'Keep this under 5,000 characters.';
            }
            return $v;
        };
        $severity = '';
        $version = '';
        $environment = '';
        if ($type === 'bug') {
            $version = self::visible(self::line($request, 'app_version'));
            if (mb_strlen($version) > 40) {
                $errors['app_version'] = 'Keep the version under 40 characters.';
            }
            $environment = self::text($request, 'environment');
            // The column is VARCHAR(255) in MySQL, which refuses a longer value in strict mode.
            if (mb_strlen($environment) > 255) {
                $errors['environment'] = 'Keep this under 255 characters.';
            }
            $severity = self::pick($request->input('severity'), self::SEVERITIES);
            if ($severity === '') {
                $errors['severity'] = 'Choose how much this affects you.';
            }
            $what = $long('what_happened', true, 'Tell us what happened, in at least 10 characters.');
            $expected = $long('expected', false, '');
            $steps = $long('steps', false, '');
            $body = "### What happened\n\n" . $what;
            if ($expected !== '') {
                $body .= "\n\n### What I expected\n\n" . $expected;
            }
            if ($steps !== '') {
                $body .= "\n\n### Steps to reproduce\n\n" . $steps;
            }
        } elseif ($type === 'feature') {
            $problem = $long('problem', true, 'Describe the problem it would solve, in at least 10 characters.');
            $idea = $long('idea', true, 'Describe your idea, in at least 10 characters.');
            $body = "### The problem it solves\n\n" . $problem . "\n\n### The idea\n\n" . $idea;
        } else {
            $body = $long('question', true, 'Write your question, in at least 10 characters.');
        }
        return [[
            'title' => $title,
            'product' => $product,
            'body' => $body,
            'severity' => $severity,
            'app_version' => $version,
            'environment' => $environment,
        ], $errors];
    }

    /**
     * The request's $_FILES entry for a field, as one file or a flat list. A crafted form can nest names
     * (attachments[a][b]); those items are left out, since no form of ours sends them and reading them as
     * strings would only produce warnings and a misleading error.
     *
     * @return array<string, mixed>|null
     */
    private static function fileEntry(Request $request, string $field): ?array
    {
        $entry = $request->files[$field] ?? null;
        if (!is_array($entry) || !isset($entry['name'])) {
            return null;
        }
        $keys = ['name', 'type', 'tmp_name', 'error', 'size'];
        if (!is_array($entry['name'])) {
            foreach ($keys as $k) {
                if (isset($entry[$k]) && !is_scalar($entry[$k])) {
                    return null;
                }
            }
            return $entry;
        }
        $flat = array_fill_keys($keys, []);
        foreach (array_keys($entry['name']) as $i) {
            $item = [];
            foreach ($keys as $k) {
                $v = is_array($entry[$k] ?? null) ? ($entry[$k][$i] ?? null) : null;
                if ($v !== null && !is_scalar($v)) {
                    continue 2;
                }
                $item[$k] = $v;
            }
            foreach ($keys as $k) {
                $flat[$k][] = $item[$k];
            }
        }
        return $flat;
    }

    /** @return list<string> */
    private function allowedExtensions(): array
    {
        $out = [];
        foreach ($this->app->config->list('uploads.allowed') as $ext) {
            if (is_string($ext) && preg_match('/^[a-z0-9]{1,10}$/', strtolower($ext))) {
                $out[] = strtolower($ext);
            }
        }
        return array_values(array_unique($out));
    }

    private function maxMb(): int
    {
        return max(1, $this->app->config->int('uploads.max_mb', 10));
    }

    /**
     * Stores every upload or none: on the first refusal the files already stored are removed.
     *
     * @param list<array{name: string, type: string, tmp_name: string, error: int, size: int}> $files
     * @return array{files: list<array{original_name: string, stored_name: string, size: int, mime: string, path: string}>, error: ?string}
     */
    private function storeUploads(array $files): array
    {
        $stored = [];
        if ($files === []) {
            return ['files' => [], 'error' => null];
        }
        $dir = $this->app->storage->dir('uploads/tickets');
        $trust = $this->app->config->string('app.env') === 'testing';
        foreach ($files as $file) {
            $result = Uploads::store($file, $dir, $this->allowedExtensions(), $this->maxMb() * 1024 * 1024, $trust);
            if ($result['ok'] !== true) {
                self::discard($stored);
                return ['files' => [], 'error' => self::visible(Uploads::cleanName($file['name'])) . ': ' . $result['error']];
            }
            $name = self::visible($result['original_name']);
            $stored[] = [
                'original_name' => $name !== '' ? $name : 'file',
                'stored_name' => $result['stored_name'],
                'size' => $result['size'],
                'mime' => $result['mime'],
                'path' => $result['path'],
            ];
        }
        return ['files' => $stored, 'error' => null];
    }

    /** @param list<array{path: string}> $files */
    private static function discard(array $files): void
    {
        foreach ($files as $f) {
            if (is_file($f['path'])) {
                @unlink($f['path']);
            }
        }
    }

    /** @param list<array{original_name: string, stored_name: string, size: int, mime: string, path: string}> $files */
    private static function insertAttachments(Database $db, int $ticketId, ?int $commentId, int $userId, array $files, int $now): void
    {
        foreach ($files as $f) {
            $db->insert('ticket_attachments', [
                'ticket_id' => $ticketId,
                'comment_id' => $commentId,
                'user_id' => $userId,
                'original_name' => $f['original_name'],
                'stored_name' => $f['stored_name'],
                'mime' => mb_substr($f['mime'], 0, 100),
                'size' => $f['size'],
                'created_at' => $now,
            ]);
        }
    }

    /** The Content-Type a download is sent with: the stored type for raster images and PDFs, else a plain byte stream. */
    private static function servedMime(string $mime): string
    {
        if ($mime === 'application/pdf' || (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml' && preg_match('#^image/[a-z0-9.+-]+$#', $mime))) {
            return $mime;
        }
        return 'application/octet-stream';
    }

    /**
     * The board's filters from a query, every value checked against its allow-list.
     *
     * @param array<string, mixed> $query
     * @return array{product: string, status: string, sort: string, q: string}
     */
    private static function boardFilters(array $query): array
    {
        $get = static fn (string $k): string => isset($query[$k]) && is_string($query[$k]) ? $query[$k] : '';
        $q = trim((string) preg_replace('/\s+/u', ' ', mb_check_encoding($get('q'), 'UTF-8') ? $get('q') : ''));
        $sort = self::pick($get('sort'), self::BOARD_SORTS);
        return [
            'product' => self::pick($get('product'), self::products()),
            'status' => self::pick($get('status'), self::STATUSES),
            'sort' => $sort !== '' ? $sort : 'votes',
            'q' => mb_substr($q, 0, 100),
        ];
    }

    /**
     * @param array{product: string, status: string, sort: string, q: string} $f
     * @return array<string, string> the non-default filters, for links
     */
    private static function boardQuery(array $f): array
    {
        $out = [];
        foreach (['q', 'product', 'status'] as $k) {
            if ($f[$k] !== '') {
                $out[$k] = $f[$k];
            }
        }
        if ($f['sort'] !== 'votes') {
            $out['sort'] = $f['sort'];
        }
        return $out;
    }

    /**
     * Where a vote returns to: the board (its filters re-checked) or the ticket page, each with an anchor. Any other
     * value goes to the ticket, so the field can never redirect off the portal.
     */
    private function voteReturn(string $value, int $id): string
    {
        $board = '/portal/ideas/';
        if ($value === $board || str_starts_with($value, $board . '?')) {
            parse_str((string) parse_url($value, PHP_URL_QUERY), $query);
            $clean = self::boardQuery(self::boardFilters($query));
            $page = isset($query['page']) && is_string($query['page']) ? (int) $query['page'] : 0;
            if ($page > 1 && $page < 100000) {
                $clean['page'] = (string) $page;
            }
            return $board . ($clean !== [] ? '?' . http_build_query($clean) : '') . '#ticket-' . $id;
        }
        return self::path($id) . '#vote';
    }

    /**
     * How a comment's author is shown: "You", staff by name, and other clients by first name and initial (on a
     * public ticket other clients read the thread; their full names and email addresses stay private). The rule is
     * Names::commentAuthor, shared with every other place clients read each other's names.
     *
     * @param array<string, mixed> $comment
     */
    private static function commentAuthor(array $comment, int $viewerId, bool $viewerIsAdmin): string
    {
        return Names::commentAuthor($comment, $viewerId, $viewerIsAdmin);
    }

    /** @param array<string, mixed>|null $owner */
    private static function ownerLabel(?array $owner, int $viewerId, bool $viewerIsAdmin): string
    {
        if ($owner === null) {
            return 'A former account';
        }
        if ((int) $owner['id'] === $viewerId) {
            return 'You';
        }
        if ($viewerIsAdmin) {
            return (string) $owner['name'];
        }
        return str_ends_with((string) $owner['email'], '.invalid') ? 'A former client' : self::shortName((string) $owner['name']);
    }

    /** "Ada Lovelace" becomes "Ada L."; a word with an @ in it is left out (Names::shortName). */
    public static function shortName(string $name): string
    {
        return Names::shortName($name);
    }
}
