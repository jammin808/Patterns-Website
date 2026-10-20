<?php

declare(strict_types=1);

namespace Psc\Controllers\Admin;

use Psc\Controllers\Controller;
use Psc\Controllers\Portal\TicketController;
use Psc\Db\Database;
use Psc\Http\Request;
use Psc\Http\Response;
use Psc\Support\Audit;
use Psc\Support\Paginator;

/**
 * Every ticket, for administrators (the routes carry the admin middleware): a filtered list, and the update a
 * ticket page's "Manage this ticket" form posts. Replies and status changes reach the owner by email; an internal
 * note never does, and a status change email never carries one.
 */
final class AdminTicketController extends Controller
{
    /** The list's status filter: every status, plus "active" for any status that is not closed. */
    public const STATUS_FILTERS = ['active' => 'Still open (any active status)'] + TicketController::STATUSES;

    private const SORTS = [
        'updated' => 't.last_activity_at DESC, t.id DESC',
        'newest' => 't.created_at DESC, t.id DESC',
        'oldest' => 't.created_at ASC, t.id ASC',
        'votes' => 't.votes DESC, t.last_activity_at DESC, t.id DESC',
    ];

    public const SORT_LABELS = ['updated' => 'Recently active', 'newest' => 'Newest', 'oldest' => 'Oldest', 'votes' => 'Most votes'];

    public function index(Request $request): Response
    {
        $products = TicketController::products();
        $f = [
            'type' => TicketController::pick($request->queryString('type'), TicketController::TYPES),
            'product' => TicketController::pick($request->queryString('product'), $products),
            'status' => TicketController::pick($request->queryString('status'), self::STATUS_FILTERS),
            'visibility' => TicketController::pick($request->queryString('visibility'), TicketController::VISIBILITIES),
            'sort' => TicketController::pick($request->queryString('sort'), self::SORTS) ?: 'updated',
            'q' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', mb_check_encoding($request->queryString('q'), 'UTF-8') ? $request->queryString('q') : '')), 0, 100),
        ];
        $where = ['1 = 1'];
        $params = [];
        foreach (['type', 'product', 'visibility'] as $k) {
            if ($f[$k] !== '') {
                $where[] = 't.' . $k . ' = ?';
                $params[] = $f[$k];
            }
        }
        if ($f['status'] === 'active') {
            $where[] = 't.status NOT IN (' . implode(', ', array_fill(0, count(TicketController::CLOSED), '?')) . ')';
            array_push($params, ...TicketController::CLOSED);
        } elseif ($f['status'] !== '') {
            $where[] = 't.status = ?';
            $params[] = $f['status'];
        }
        if ($f['q'] !== '') {
            if (preg_match('/^#?(\d{1,9})$/', $f['q'], $m)) {
                $where[] = 't.id = ?';
                $params[] = (int) $m[1];
            } else {
                $where[] = "(t.title LIKE ? ESCAPE '!' OR u.email LIKE ? ESCAPE '!' OR u.name LIKE ? ESCAPE '!')";
                $like = Database::like($f['q']);
                array_push($params, $like, $like, $like);
            }
        }
        $sql = 'SELECT t.id, t.type, t.product, t.title, t.status, t.severity, t.visibility, t.votes, t.comment_count,'
            . ' t.last_activity_at, t.created_at, t.user_id, u.name AS owner_name, u.email AS owner_email'
            . ' FROM tickets t LEFT JOIN users u ON u.id = t.user_id'
            . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . self::SORTS[$f['sort']];
        $query = [];
        foreach ($f as $k => $v) {
            if ($v !== '' && !($k === 'sort' && $v === 'updated')) {
                $query[$k] = $v;
            }
        }
        if ($request->queryString('page') !== '') {
            $query['page'] = $request->queryString('page');
        }
        $pager = Paginator::query($this->app->db(), $sql, $params, $query, '/portal/admin/tickets/', 25);

        $counts = array_fill_keys(array_keys(TicketController::STATUSES), 0);
        foreach ($this->app->db()->all('SELECT status, COUNT(*) AS n FROM tickets GROUP BY status') as $row) {
            if (isset($counts[(string) $row['status']])) {
                $counts[(string) $row['status']] = (int) $row['n'];
            }
        }

        return $this->portal('admin/tickets/index', [
            'pager' => $pager,
            'filters' => $f,
            'filtered' => $f['type'] !== '' || $f['product'] !== '' || $f['status'] !== '' || $f['visibility'] !== '' || $f['q'] !== '',
            'counts' => $counts,
            'types' => TicketController::TYPES,
            'products' => $products,
            'statuses' => TicketController::STATUSES,
            'statusFilters' => self::STATUS_FILTERS,
            'severities' => TicketController::SEVERITIES,
            'visibilities' => TicketController::VISIBILITIES,
            'sorts' => self::SORT_LABELS,
        ], 'All tickets');
    }

    /** Status, visibility and severity, with an optional staff reply or internal note; back to the ticket page. */
    public function update(Request $request): Response
    {
        $admin = $this->app->auth()->user();
        $ticket = $this->app->db()->one('SELECT * FROM tickets WHERE id = ?', [$request->attrInt('id')]);
        if ($admin === null || $ticket === null) {
            return $this->notFound();
        }
        $id = (int) $ticket['id'];
        $adminId = (int) $admin['id'];
        $path = TicketController::path($id);
        $isBug = $ticket['type'] === 'bug';

        $status = TicketController::pick($request->input('status'), TicketController::STATUSES);
        $visibility = TicketController::pick($request->input('visibility'), TicketController::VISIBILITIES);
        $severity = $isBug ? TicketController::pick($request->input('severity'), TicketController::SEVERITIES) : '';
        $reply = TicketController::text($request, 'reply');
        $internal = $request->input('internal') === '1';
        $errors = [];
        if ($status === '') {
            $errors['status'] = 'Choose a status from the list.';
        }
        if ($visibility === '') {
            $errors['visibility'] = 'Choose who can see this ticket.';
        }
        if ($isBug && $severity === '') {
            $errors['severity'] = 'Choose a severity from the list.';
        }
        if (mb_strlen($reply) > 5000) {
            $errors['reply'] = 'Keep the reply under 5,000 characters.';
        }
        if ($errors !== []) {
            return $this->backWithErrors($path . '#manage', $request, $errors);
        }

        $oldStatus = (string) $ticket['status'];
        $now = time();
        $set = [];
        $changes = [];
        if ($status !== $oldStatus) {
            $set['status'] = $status;
            $set['last_activity_at'] = $now;
            $wasClosed = in_array($oldStatus, TicketController::CLOSED, true);
            $isClosed = in_array($status, TicketController::CLOSED, true);
            if ($isClosed && !$wasClosed) {
                $set['closed_at'] = $now;
            } elseif (!$isClosed) {
                $set['closed_at'] = null;
            }
            $changes['status'] = [$oldStatus, $status];
        }
        if ($visibility !== $ticket['visibility']) {
            $set['visibility'] = $visibility;
            $changes['visibility'] = [(string) $ticket['visibility'], $visibility];
        }
        $newSeverity = $isBug ? $severity : null;
        if ($newSeverity !== $ticket['severity']) {
            $set['severity'] = $newSeverity;
            $changes['severity'] = [$ticket['severity'], $newSeverity];
        }
        if ($set === [] && $reply === '') {
            return $this->redirect($path . '#manage', 'info', 'Nothing was different, so there was nothing to save.');
        }

        $commentId = $this->app->db()->transaction(static function (Database $db) use ($set, $now, $id, $ticket, $adminId, $reply, $internal): ?int {
            if ($set !== []) {
                $db->update('tickets', $set + ['updated_at' => $now], ['id' => $id]);
            }
            return $reply !== '' ? TicketController::addComment($db, $ticket, $adminId, true, $reply, $internal) : null;
        });
        Audit::log($this->app, $adminId, 'ticket.updated', (string) $id, $changes + ($commentId !== null ? ['comment' => $commentId, 'internal' => $internal] : []));

        $owner = $this->app->auth()->find((int) $ticket['user_id']);
        $fresh = $this->app->db()->one('SELECT * FROM tickets WHERE id = ?', [$id]) ?? $ticket;
        $publicReply = $reply !== '' && !$internal ? $reply : '';
        if (TicketController::mayEmail($owner, $adminId)) {
            if (isset($changes['status'])) {
                $this->app->mailer()->send((string) $owner['email'], 'Your ticket #' . $id . ' is now ' . mb_strtolower(TicketController::STATUSES[$status]) . ': ' . $ticket['title'], 'ticket-status', [
                    'name' => (string) $owner['name'],
                    'ticket' => $fresh,
                    'typeLabel' => TicketController::TYPES[(string) $ticket['type']] ?? 'Ticket',
                    'oldLabel' => TicketController::STATUSES[$oldStatus] ?? $oldStatus,
                    'newLabel' => TicketController::STATUSES[$status],
                    'statusHelp' => TicketController::STATUS_HELP[$status],
                    'staffName' => (string) $admin['name'],
                    'reply' => $publicReply,
                    'url' => $this->app->baseUrl() . $path,
                ]);
            } elseif ($publicReply !== '') {
                TicketController::sendReply($this->app, $fresh, $owner, (string) $admin['name'], $publicReply);
            }
        }

        $anchor = $commentId !== null ? '#comment-' . $commentId : '#manage';
        return $this->redirect($path . $anchor, 'success', $internal && $commentId !== null && $set === [] ? 'Internal note added. Only staff can see it.' : 'Ticket updated.');
    }
}
