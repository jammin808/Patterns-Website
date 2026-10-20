<?php

declare(strict_types=1);

namespace Psc\Controllers\Portal;

use Psc\Controllers\Admin\AdminController;
use Psc\Controllers\Controller;
use Psc\Http\Request;
use Psc\Http\Response;

/**
 * The portal's home page: a welcome, quick actions, the account's own open tickets and licences, the newest
 * published release of each product, and for administrators a summary of what is waiting.
 *
 * Every list is read with the signed-in account's id in the query itself, so nothing another client owns can
 * appear here. A ticket is flagged as having a reply when its newest public staff comment (by someone other than
 * the owner) came after the owner's own newest comment; internal notes never count.
 */
final class DashboardController extends Controller
{
    private const LIST_SIZE = 5;

    public function index(Request $request): Response
    {
        $user = $this->app->auth()->user();
        if ($user === null) {
            throw new \LogicException('The dashboard needs the auth middleware');
        }
        $db = $this->app->db();
        $userId = (int) $user['id'];
        $isAdmin = ($user['role'] ?? '') === 'admin';

        $closed = TicketController::CLOSED;
        $closedIn = implode(', ', array_fill(0, count($closed), '?'));
        $tickets = $db->all(
            'SELECT t.id, t.type, t.product, t.title, t.status, t.comment_count, t.last_activity_at,'
            . ' (SELECT MAX(c.id) FROM ticket_comments c WHERE c.ticket_id = t.id AND c.is_staff = 1 AND c.is_internal = 0 AND c.user_id <> t.user_id) AS last_staff_id,'
            . ' (SELECT MAX(c.id) FROM ticket_comments c WHERE c.ticket_id = t.id AND c.user_id = t.user_id) AS last_own_id'
            . ' FROM tickets t WHERE t.user_id = ? AND t.status NOT IN (' . $closedIn . ')'
            . ' ORDER BY t.last_activity_at DESC, t.id DESC LIMIT ' . self::LIST_SIZE,
            [$userId, ...$closed]
        );
        foreach ($tickets as &$t) {
            $t['has_reply'] = $t['last_staff_id'] !== null && (int) $t['last_staff_id'] > (int) ($t['last_own_id'] ?? 0);
        }
        unset($t);
        $openTickets = (int) $db->value('SELECT COUNT(*) FROM tickets WHERE user_id = ? AND status NOT IN (' . $closedIn . ')', [$userId, ...$closed]);
        $allTickets = (int) $db->value('SELECT COUNT(*) FROM tickets WHERE user_id = ?', [$userId]);

        $licences = $db->all(
            'SELECT id, product, tier, status, reference, quantity, created_at, issued_at FROM licences'
            . ' WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . self::LIST_SIZE,
            [$userId]
        );
        $licenceCount = (int) $db->value('SELECT COUNT(*) FROM licences WHERE user_id = ?', [$userId]);
        $licenceNames = [];
        foreach ($licences as $l) {
            $licenceNames[(int) $l['id']] = LicenceController::optionName((string) $l['product'], (string) $l['tier']);
        }

        return $this->portal('portal/dashboard', [
            'firstName' => self::firstName((string) $user['name']),
            'isAdmin' => $isAdmin,
            'tickets' => $tickets,
            'openTickets' => $openTickets,
            'allTickets' => $allTickets,
            'licences' => $licences,
            'licenceCount' => $licenceCount,
            'licenceNames' => $licenceNames,
            'releases' => $this->latestReleases($userId, $isAdmin),
            'waiting' => $isAdmin ? AdminController::waitingCounts($db) : null,
            'products' => DownloadController::productNames(),
            'ticketTypes' => TicketController::TYPES,
            'ticketStatuses' => TicketController::STATUSES,
            'licenceStatuses' => LicenceController::STATUSES,
            'channels' => DownloadController::CHANNELS,
        ], 'Dashboard');
    }

    /**
     * The newest published release of each product, in ecosystem order, with whether this account may download
     * it (a licence-only build is listed for everyone, as on the downloads page, but marked).
     *
     * @return list<array<string, mixed>>
     */
    private function latestReleases(int $userId, bool $isAdmin): array
    {
        $db = $this->app->db();
        $rows = $db->all('SELECT id, product, version, channel, title, access, published_at FROM releases'
            . ' WHERE is_published = 1 ORDER BY published_at DESC, id DESC');
        $licensed = $isAdmin ? DownloadController::productKeys() : DownloadController::licensedProducts($db, $userId);
        $latest = [];
        foreach ($rows as $row) {
            $key = (string) $row['product'];
            if (!isset($latest[$key])) {
                $row['may_download'] = $row['access'] !== 'licensed' || in_array($key, $licensed, true);
                $latest[$key] = $row;
            }
        }
        $out = [];
        foreach (DownloadController::productKeys() as $key) {
            if (isset($latest[$key])) {
                $out[] = $latest[$key];
            }
        }
        return $out;
    }

    /** The first word of a name, for the greeting; the whole name when it has no spaces. */
    public static function firstName(string $name): string
    {
        $name = trim($name);
        $first = preg_split('/\s+/u', $name, 2)[0] ?? '';
        return $first !== '' ? $first : $name;
    }
}
