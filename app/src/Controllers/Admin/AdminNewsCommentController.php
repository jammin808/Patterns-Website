<?php

declare(strict_types=1);

namespace Psc\Controllers\Admin;

use Psc\Controllers\Controller;
use Psc\Http\Request;
use Psc\Http\Response;
use Psc\News\ArticleStore;
use Psc\News\Comments;
use Psc\Support\Audit;
use Psc\Support\Paginator;

/**
 * Moderation of Industry Updates comments, for administrators (docs/NEWS.md §11): four tabs (reported, pending,
 * visible, hidden), each comment with its article, its author's full name and email, and the actions its state
 * allows: approve a pending one, hide, show a hidden one, delete, or clear its reports. Every change is written only
 * from the state the page showed, audited as comment.*, and answered with a 303 back to the tab and page it was on.
 */
final class AdminNewsCommentController extends Controller
{
    public const TABS = ['reported' => 'Reported', 'pending' => 'Pending', 'visible' => 'Visible', 'hidden' => 'Hidden'];

    /** Each action: the states it may start from, the state it leaves, the audit action, the answers. */
    public const ACTIONS = [
        'approve' => ['from' => ['pending'], 'to' => 'visible', 'audit' => 'comment.approved', 'done' => 'Comment approved. It now shows on the article.', 'already' => 'Nothing changed: that comment is not waiting for approval.'],
        'hide' => ['from' => ['visible', 'pending'], 'to' => 'hidden', 'audit' => 'comment.hidden', 'done' => 'Comment hidden.', 'already' => 'Nothing changed: that comment is hidden already.'],
        'show' => ['from' => ['hidden'], 'to' => 'visible', 'audit' => 'comment.shown', 'done' => 'Comment shown again on the article.', 'already' => 'Nothing changed: that comment is not hidden.'],
        'delete' => ['from' => ['visible', 'pending', 'hidden'], 'to' => null, 'audit' => 'comment.deleted', 'done' => 'Comment deleted.', 'already' => ''],
        'clear_reports' => ['from' => ['visible', 'pending', 'hidden'], 'to' => null, 'audit' => 'comment.reports_cleared', 'done' => 'Reports cleared. The same people can report the comment again.', 'already' => 'Nothing changed: that comment has no reports.'],
    ];

    /**
     * Said after a change that takes a comment, an article or an image off the site (docs/NEWS.md §11, takedown
     * actions; the article editor says it too). Each figure is the longest a CDN may serve what its responses were
     * sent with: a page's max-age and then its stale-while-revalidate (NewsController::CACHE, 120 + 60 seconds) and an
     * image's s-maxage (NewsMediaController::PUBLIC_CACHE).
     */
    public const TAKEDOWN = 'Pages can take up to about 3 minutes to leave the CDN, and images up to an hour. Run Flush CDN to remove both at once.';

    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $db = $this->app->db();
        $tab = AdminController::pick($request->queryString('tab'), self::TABS) ?: 'reported';
        $articleId = ctype_digit($request->queryString('article')) ? (int) $request->queryString('article') : 0;
        $article = $articleId > 0 ? (new ArticleStore($this->app))->find($articleId) : null;
        $where = match ($tab) {
            'reported' => 'c.reports > 0',
            default => 'c.status = ?',
        };
        $params = $tab === 'reported' ? [] : [$tab];
        if ($article !== null) {
            $where .= ' AND c.article_id = ?';
            $params[] = (int) $article['id'];
        }
        $order = match ($tab) {
            'reported' => 'c.reports DESC, c.created_at DESC, c.id DESC',
            'pending' => 'c.created_at, c.id',
            'hidden' => 'c.updated_at DESC, c.id DESC',
            default => 'c.created_at DESC, c.id DESC',
        };
        $query = array_filter([
            'tab' => $tab !== 'reported' ? $tab : '',
            'article' => $article !== null ? (string) $article['id'] : '',
            'page' => $request->queryString('page'),
        ], static fn (string $v): bool => $v !== '');
        $pager = Paginator::query(
            $db,
            'SELECT c.id, c.article_id, c.user_id, c.body, c.status, c.reports, c.created_at, c.updated_at,'
            . ' u.name AS author_name, u.email AS author_email, u.role AS author_role,'
            . ' a.title AS article_title, a.slug AS article_slug, a.status AS article_status, a.published_at AS article_published_at,'
            . ' (SELECT ac.category FROM news_article_categories ac WHERE ac.article_id = a.id ORDER BY ac.position, ac.category LIMIT 1) AS article_category'
            . ' FROM news_comments c JOIN users u ON u.id = c.user_id JOIN news_articles a ON a.id = c.article_id'
            . ' WHERE ' . $where . ' ORDER BY ' . $order,
            $params,
            $query,
            '/portal/admin/news/comments/',
            self::PER_PAGE
        );
        $filter = $article !== null ? ' AND article_id = ' . (int) $article['id'] : '';
        $counts = array_fill_keys(array_keys(self::TABS), 0);
        $counts['reported'] = (int) $db->value('SELECT COUNT(*) FROM news_comments WHERE reports > 0' . $filter);
        foreach ($db->all('SELECT status, COUNT(*) AS n FROM news_comments WHERE 1 = 1' . $filter . ' GROUP BY status') as $row) {
            if (in_array((string) $row['status'], Comments::STATUSES, true)) {
                $counts[(string) $row['status']] = (int) $row['n'];
            }
        }
        $store = new ArticleStore($this->app);
        $items = array_map(static fn (array $c): array => $c + [
            'public_name' => Comments::publicName(['name' => $c['author_name'], 'email' => $c['author_email']]),
            'staff' => $c['author_role'] === 'admin',
            'article_live' => $store->isLive(['status' => $c['article_status'], 'published_at' => $c['article_published_at']]),
        ], $pager->items);
        return $this->portal('admin/news/comments', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'counts' => $counts,
            'pager' => $pager,
            'items' => $items,
            'article' => $article,
            'mode' => (new Comments($this->app))->mode(),
        ], 'Comments');
    }

    public function update(Request $request): Response
    {
        $admin = $this->currentUser();
        $comments = new Comments($this->app);
        $comment = $comments->find($request->attrInt('id'));
        if ($comment === null) {
            return $this->notFound();
        }
        $id = (int) $comment['id'];
        $from = (string) $comment['status'];
        $back = self::listPath($request->input('tab'), (int) $request->input('page'), (int) $request->input('article'));
        $action = $request->input('action');
        $rule = self::ACTIONS[$action] ?? null;
        // The buttons post fixed values, so anything else is refused with a message only, keeping no field error.
        if ($rule === null) {
            return $this->redirect($back, 'danger', 'That comment was not changed. Use one of the buttons beside it.');
        }
        if (!in_array($from, $rule['from'], true) || ($action === 'clear_reports' && (int) $comment['reports'] === 0)) {
            return $this->redirect($back, 'info', (string) $rule['already']);
        }
        $meta = ['article' => (int) $comment['article_id']];
        $takedown = false;
        if ($action === 'delete') {
            if (!$comments->remove($id, $admin)) {
                return $this->redirect($back, 'info', 'That comment was deleted by someone else while you were looking at it.');
            }
            // The row is gone, so the audit keeps enough to say what it was.
            $meta += ['author' => (int) $comment['user_id'], 'status' => $from, 'reports' => (int) $comment['reports']];
            $takedown = $from === 'visible';
        } elseif ($action === 'clear_reports') {
            $meta += ['reports' => $comments->clearReports($id)];
        } else {
            if (!$comments->moveStatus($id, $from, (string) $rule['to'])) {
                return $this->redirect($back, 'info', 'That comment was changed by someone else while you were looking at it, so nothing changed.');
            }
            $meta += ['from' => $from, 'to' => $rule['to']];
            $takedown = $from === 'visible';
        }
        Audit::log($this->app, (int) $admin['id'], (string) $rule['audit'], (string) $id, $meta);
        return $this->redirect($back, 'success', $rule['done'] . ($takedown ? ' ' . self::TAKEDOWN : ''));
    }

    /** The moderation list on a tab, page and article (each left out when it is the default). */
    public static function listPath(string $tab, int $page = 1, int $article = 0): string
    {
        $q = [];
        if ($tab !== 'reported' && isset(self::TABS[$tab])) {
            $q['tab'] = $tab;
        }
        if ($article > 0) {
            $q['article'] = (string) $article;
        }
        if ($page > 1 && $page <= 100000) {
            $q['page'] = (string) $page;
        }
        return '/portal/admin/news/comments/' . ($q !== [] ? '?' . http_build_query($q) : '');
    }

    /** @return array<string, mixed> the signed-in administrator (the middleware guarantees one) */
    private function currentUser(): array
    {
        $user = $this->app->auth()->user();
        if ($user === null) {
            throw new \LogicException('Admin pages need the auth middleware');
        }
        return $user;
    }
}
