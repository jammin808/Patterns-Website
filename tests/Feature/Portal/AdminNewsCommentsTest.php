<?php

declare(strict_types=1);

namespace Psc\Tests\Feature\Portal;

use Psc\Controllers\Admin\AdminNewsCommentController;
use Psc\Http\Response;
use Psc\News\Comments;
use Psc\Tests\TestCase;

/**
 * Moderation of Industry Updates comments (docs/NEWS.md §11): the four tabs, the author's full name and email for
 * administrators only, and each action (approve, hide, show, delete, clear reports) changing exactly the state it
 * names, from the state the page showed, with the article's visible count kept true, an audit row (comment.*) and a
 * 303 back to the tab it was sent from.
 */
final class AdminNewsCommentsTest extends TestCase
{
    private const LIST = '/portal/admin/news/comments/';

    private function comment(int $article, int $user, string $body, string $status = 'visible', int $reports = 0): int
    {
        $at = time() - 600;
        $id = $this->db->insert('news_comments', ['article_id' => $article, 'user_id' => $user, 'body' => $body, 'status' => $status, 'reports' => $reports, 'created_at' => $at, 'updated_at' => $at]);
        Comments::recountArticles($this->db, [$article]);
        return $id;
    }

    private function visibleCount(int $article): int
    {
        return (int) $this->db->value('SELECT comment_count FROM news_articles WHERE id = ?', [$article]);
    }

    private function commentStatus(int $id): ?string
    {
        $s = $this->db->value('SELECT status FROM news_comments WHERE id = ?', [$id]);
        return $s !== null ? (string) $s : null;
    }

    /** @return list<array<string, mixed>> */
    private function auditRows(string $action): array
    {
        return $this->db->all('SELECT * FROM audit_log WHERE action = ? ORDER BY id', [$action]);
    }

    /** @return array{type: string, message: string} */
    private static function lastFlash(): array
    {
        $flashes = $_SESSION['_flash'] ?? [];
        return $flashes !== [] ? end($flashes) : ['type' => '', 'message' => ''];
    }

    private static function assertRedirect(Response $r, string $location): void
    {
        self::assertSame(303, $r->status, 'status');
        self::assertSame($location, $r->getHeader('Location'));
    }

    /** The page's own markup, inside the portal shell's main element. */
    private static function mainOf(Response $r): string
    {
        $start = strpos($r->body, '<main class="pt-main"');
        $end = strpos($r->body, '</main>', (int) $start);
        return $start === false || $end === false ? '' : substr($r->body, $start, $end - $start);
    }

    public function testOnlyAdministratorsModerate(): void
    {
        $article = $this->makeArticle();
        $author = $this->makeUser('author@example.test');
        $id = $this->comment($article, $author, 'A comment');
        self::assertSame(302, $this->request('GET', self::LIST)->status);
        self::assertSame('/portal/login/?next=' . rawurlencode(self::LIST . '?tab=pending'), $this->request('GET', self::LIST . '?tab=pending')->getHeader('Location'));

        $this->signInClient();
        self::assertSame(403, $this->request('GET', self::LIST)->status);
        self::assertSame(403, $this->post(self::LIST . $id . '/', ['action' => 'hide'])->status);
        self::assertSame('visible', $this->commentStatus($id));

        $_SESSION = [];
        $this->jar = [];
        $this->signInAdmin();
        self::assertSame(419, $this->post(self::LIST . $id . '/', ['action' => 'hide'], false)->status);
        self::assertSame('visible', $this->commentStatus($id));
        self::assertSame(404, $this->post(self::LIST . '999/', ['action' => 'hide'])->status);
        self::assertSame([], $this->db->all("SELECT * FROM audit_log WHERE action LIKE 'comment.%'"));
    }

    public function testEveryTabListsItsCommentsWithTheAuthorForAdministrators(): void
    {
        $this->signInAdmin();
        $article = $this->makeArticle(['title' => 'LED processors get a firmware update']);
        $other = $this->makeArticle(['title' => 'A second article about stage audio']);
        $nina = $this->makeUser('nina@example.test');
        $this->db->update('users', ['name' => 'Nina Stage'], ['id' => $nina]);
        $visible = $this->comment($article, $nina, 'Plainly visible <b>words</b>');
        $reported = $this->comment($article, $nina, 'Reported twice', 'visible', 2);
        $pending = $this->comment($article, $nina, 'Waiting for a look', 'pending');
        $hidden = $this->comment($article, $nina, 'Hidden by reports', 'hidden', 3);
        $elsewhere = $this->comment($other, $nina, 'On the other article');

        $expect = [
            '' => [$reported, $hidden],
            '?tab=pending' => [$pending],
            '?tab=visible' => [$elsewhere, $reported, $visible],
            '?tab=hidden' => [$hidden],
        ];
        foreach ($expect as $query => $ids) {
            $r = $this->request('GET', self::LIST . $query);
            self::assertSame(200, $r->status, $query);
            self::assertStringContainsString('no-store', (string) $r->getHeader('Cache-Control'));
            self::assertSame(1, substr_count($r->body, '<h1 class="pt-title"'), $query);
            $main = self::mainOf($r);
            self::assertStringNotContainsString('style="', $main, $query);
            self::assertStringNotContainsString('<script', $main, $query);
            preg_match_all('/<article class="pt-card" id="comment-(\d+)"/', $main, $m);
            self::assertEqualsCanonicalizing($ids, array_map('intval', $m[1]), $query);
            self::assertStringContainsString('Nina Stage', $main, 'the full name, for administrators');
            self::assertStringContainsString('nina@example.test', $main);
            self::assertStringContainsString('Nina S.', $main, 'and the name readers see');
        }
        $tabs = $this->request('GET', self::LIST)->body;
        self::assertStringContainsString('Reported <span class="pt-muted">(2)</span>', $tabs);
        self::assertStringContainsString('Pending <span class="pt-muted">(1)</span>', $tabs);
        self::assertStringContainsString('Visible <span class="pt-muted">(3)</span>', $tabs);
        self::assertStringContainsString('Hidden <span class="pt-muted">(1)</span>', $tabs);
        self::assertStringContainsString('Plainly visible &lt;b&gt;words&lt;/b&gt;', $this->request('GET', self::LIST . '?tab=visible')->body, 'plain text');

        $filtered = $this->request('GET', self::LIST . '?tab=visible&article=' . $other);
        preg_match_all('/<article class="pt-card" id="comment-(\d+)"/', self::mainOf($filtered), $m);
        self::assertSame([$elsewhere], array_map('intval', $m[1]));
        self::assertStringContainsString('Showing the comments on <strong>A second article about stage audio</strong> only.', $filtered->body);
        self::assertStringContainsString('Visible <span class="pt-muted">(1)</span>', $filtered->body);

        $empty = $this->request('GET', self::LIST . '?tab=pending&article=' . $other);
        self::assertStringContainsString('Nothing is waiting for approval.', $empty->body);
    }

    public function testEachActionChangesOnlyItsStateAndIsAudited(): void
    {
        $adminId = $this->signInAdmin();
        $article = $this->makeArticle();
        $author = $this->makeUser('author@example.test');
        $pending = $this->comment($article, $author, 'Waiting', 'pending');
        $visible = $this->comment($article, $author, 'Showing', 'visible', 2);
        $this->db->insert('news_comment_reports', ['comment_id' => $visible, 'user_id' => $adminId, 'created_at' => time()]);
        self::assertSame(1, $this->visibleCount($article));

        $r = $this->post(self::LIST . $pending . '/', ['action' => 'approve', 'tab' => 'pending']);
        self::assertRedirect($r, self::LIST . '?tab=pending');
        self::assertSame('visible', $this->commentStatus($pending));
        self::assertSame(2, $this->visibleCount($article));
        self::assertSame('success', self::lastFlash()['type']);
        $audit = $this->auditRows('comment.approved');
        self::assertCount(1, $audit);
        self::assertSame($adminId, (int) $audit[0]['user_id']);
        self::assertSame((string) $pending, $audit[0]['subject']);
        self::assertSame(['article' => $article, 'from' => 'pending', 'to' => 'visible'], json_decode((string) $audit[0]['meta'], true));

        $this->post(self::LIST . $visible . '/', ['action' => 'hide', 'tab' => 'reported', 'page' => '2']);
        self::assertSame('hidden', $this->commentStatus($visible));
        self::assertSame(1, $this->visibleCount($article));
        self::assertStringContainsString(AdminNewsCommentController::TAKEDOWN, self::lastFlash()['message'], 'it leaves a cached page');
        self::assertCount(1, $this->auditRows('comment.hidden'));

        $this->post(self::LIST . $visible . '/', ['action' => 'show', 'tab' => 'hidden']);
        self::assertSame('visible', $this->commentStatus($visible));
        self::assertSame(2, $this->visibleCount($article));
        self::assertCount(1, $this->auditRows('comment.shown'));

        $cleared = $this->post(self::LIST . $visible . '/', ['action' => 'clear_reports', 'tab' => 'reported']);
        self::assertRedirect($cleared, self::LIST);
        self::assertSame(0, (int) $this->db->value('SELECT reports FROM news_comments WHERE id = ?', [$visible]));
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM news_comment_reports WHERE comment_id = ?', [$visible]));
        self::assertSame(['article' => $article, 'reports' => 1], json_decode((string) $this->auditRows('comment.reports_cleared')[0]['meta'], true));
        self::assertSame('visible', $this->commentStatus($visible), 'clearing reports changes nothing else');

        $deleted = $this->post(self::LIST . $visible . '/', ['action' => 'delete', 'tab' => 'visible', 'article' => (string) $article]);
        self::assertRedirect($deleted, self::LIST . '?tab=visible&article=' . $article);
        self::assertNull($this->commentStatus($visible));
        self::assertSame(1, $this->visibleCount($article));
        self::assertStringContainsString(AdminNewsCommentController::TAKEDOWN, self::lastFlash()['message']);
        self::assertSame(['article' => $article, 'author' => $author, 'status' => 'visible', 'reports' => 0], json_decode((string) $this->auditRows('comment.deleted')[0]['meta'], true));
    }

    public function testAnActionFromAnotherStateOrUnknownChangesNothing(): void
    {
        $this->signInAdmin();
        $article = $this->makeArticle();
        $author = $this->makeUser('author@example.test');
        $visible = $this->comment($article, $author, 'Already showing');

        foreach (['approve' => 'not waiting for approval', 'show' => 'not hidden', 'clear_reports' => 'has no reports'] as $action => $words) {
            $r = $this->post(self::LIST . $visible . '/', ['action' => $action, 'tab' => 'visible']);
            self::assertRedirect($r, self::LIST . '?tab=visible');
            self::assertSame('info', self::lastFlash()['type'], $action);
            self::assertStringContainsString($words, self::lastFlash()['message']);
        }
        $this->post(self::LIST . $visible . '/', ['action' => 'publish']);
        self::assertSame('danger', self::lastFlash()['type']);
        self::assertArrayNotHasKey('_errors', $_SESSION);
        self::assertSame('visible', $this->commentStatus($visible));
        self::assertSame([], $this->db->all("SELECT * FROM audit_log WHERE action LIKE 'comment.%'"));
        self::assertSame(1, $this->visibleCount($article));
    }

    public function testAStateChangedByAnotherAdministratorIsNotOverwritten(): void
    {
        $this->signInAdmin();
        $article = $this->makeArticle();
        $author = $this->makeUser('author@example.test');
        $id = $this->comment($article, $author, 'Racing');
        $comments = new Comments($this->app);
        self::assertTrue($comments->moveStatus($id, 'visible', 'hidden'));
        self::assertFalse($comments->moveStatus($id, 'visible', 'hidden'), 'written only from the state that was read');
        self::assertFalse($comments->moveStatus($id, 'hidden', 'nonsense'));
        self::assertSame(0, $this->visibleCount($article));
    }
}
