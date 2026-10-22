<?php

declare(strict_types=1);

namespace Psc\Tests\Feature\News;

use Psc\Db\Database;
use Psc\Db\Migrator;
use Psc\Http\Response;
use Psc\News\Comments;
use Psc\News\Settings;
use Psc\Tests\TestCase;

/**
 * Industry Updates comments (docs/NEWS.md §9): the API a cached article page uses, the state it merges in, the
 * comment page and its forms for readers without scripting, the limits and the pending rules, reports and their
 * auto-hide, deleting one's own (hidden ones, and on an article that is not live, too), closed comments, public
 * names, 404 on every article that is not live otherwise, and the counts kept true when writers race. Each test
 * asserts the status, the stored rows and the published counts first, and the words second.
 */
final class NewsCommentsTest extends TestCase
{
    // ----------------------------------------------------------------- helpers

    /** @param array<string, mixed>|null $json */
    private function api(string $method, string $uri, ?array $json = null): Response
    {
        $headers = ['X-Requested-With' => 'fetch', 'Sec-Fetch-Site' => 'same-origin', 'Accept' => 'application/json'];
        $body = $json !== null ? json_encode($json, JSON_THROW_ON_ERROR) : ($method === 'POST' ? '{}' : null);
        return $this->request($method, $uri, [], $headers, $body);
    }

    /** @return array<string, mixed> */
    private static function data(Response $r): array
    {
        return json_decode($r->body, true, 512, JSON_THROW_ON_ERROR);
    }

    private function postComment(int $article, string $body): Response
    {
        return $this->api('POST', '/api/news/' . $article . '/comments', ['body' => $body]);
    }

    /** A new browser signed in as $email (made if missing). */
    private function switchTo(string $email, string $role = 'client'): int
    {
        $_SESSION = [];
        $this->jar = [];
        return $role === 'admin' ? $this->signInAdmin() : $this->signInClient($email);
    }

    /** Ages an account past the new-account rule (a day). */
    private function age(int $userId, int $days = 2): void
    {
        $this->db->update('users', ['created_at' => time() - $days * 86400], ['id' => $userId]);
    }

    /** Stores a comment directly, with the article's count kept true. */
    private function comment(int $article, int $user, string $body, string $status = 'visible', ?int $at = null, int $reports = 0): int
    {
        $at ??= time() - 600;
        $id = $this->db->insert('news_comments', ['article_id' => $article, 'user_id' => $user, 'body' => $body, 'status' => $status, 'reports' => $reports, 'created_at' => $at, 'updated_at' => $at]);
        Comments::recountArticles($this->db, [$article]);
        return $id;
    }

    private function visibleCount(int $article): int
    {
        return (int) $this->db->value('SELECT comment_count FROM news_articles WHERE id = ?', [$article]);
    }

    private function commentStatus(int $comment): ?string
    {
        $s = $this->db->value('SELECT status FROM news_comments WHERE id = ?', [$comment]);
        return $s !== null ? (string) $s : null;
    }

    private function slugOf(int $article): string
    {
        return (string) $this->db->value('SELECT slug FROM news_articles WHERE id = ?', [$article]);
    }

    /** @return list<array<string, mixed>> */
    private function adminMails(string $kind): array
    {
        return array_values(array_filter($this->mails(), static fn (array $m): bool => $m['template'] === 'news-comment-admin' && ($m['data']['kind'] ?? '') === $kind));
    }

    /** @return array<string, mixed> */
    private function userRow(int $id): array
    {
        return (array) $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /** One comment's list item on the comment page, or ''. */
    private static function item(string $html, int $id): string
    {
        preg_match('#<li class="[^"]*" id="comment-' . $id . '">.*?</li>#s', $html, $m);
        return $m[0] ?? '';
    }

    /**
     * Swaps in a fresh database whose connection records each transaction's statements and runs another request's
     * change at a chosen moment: just before the next transaction begins (beforeBegin), or just before a statement
     * starting with given words is prepared (before), each once. SQLite takes one writer at a time and InnoDB's locks
     * cannot be raised on it, so a race is staged by running the other side between the two steps it falls between,
     * and a lock order is read from the statements.
     */
    private function stagedDatabase(): object
    {
        $pdo = new class ('sqlite::memory:') extends \PDO {
            /** @var list<list<string>> each transaction's statements, in the order they were prepared */
            public array $transactions = [];

            /** @var array<string, \Closure> a statement's opening words => what runs just before it */
            public array $before = [];

            public ?\Closure $beforeBegin = null;

            public function beginTransaction(): bool
            {
                $run = $this->beforeBegin;
                $this->beforeBegin = null;
                if ($run !== null) {
                    $run();
                }
                $this->transactions[] = [];
                return parent::beginTransaction();
            }

            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                foreach ($this->before as $start => $run) {
                    if (str_starts_with($query, $start)) {
                        unset($this->before[$start]);
                        $run();
                    }
                }
                if ($this->inTransaction()) {
                    $this->transactions[array_key_last($this->transactions)][] = $query;
                }
                return parent::prepare($query, $options);
            }
        };
        $this->db = new Database($pdo);
        (new Migrator($this->db, PSC_ROOT . '/database/migrations', $this->storage . '/cache/schema-version'))->migrate();
        $this->app->useDatabase($this->db);
        $pdo->transactions = [];
        return $pdo;
    }

    private function partial(int $article): string
    {
        $row = (new \Psc\News\ArticleStore($this->app))->find($article);
        self::assertNotNull($row);
        return $this->app->view()->partial('news/comments', ['article' => $row, 'comments' => (new Comments($this->app))->visible($article)]);
    }

    // ------------------------------------------------------------- the API

    public function testAPostedCommentIsStoredVisibleAndCounted(): void
    {
        $article = $this->makeArticle();
        $uid = $this->signInClient();
        $r = $this->postComment($article, "  Good write-up.\r\nWe saw the same on a tour last month.  ");

        self::assertSame(201, $r->status);
        self::assertStringContainsString('no-store', (string) $r->getHeader('Cache-Control'));
        $data = self::data($r);
        self::assertTrue($data['ok']);
        self::assertSame('visible', $data['status']);
        self::assertFalse($data['repeat']);
        $c = $data['comment'];
        self::assertSame("Good write-up.\nWe saw the same on a tour last month.", $c['body'], 'cleaned: trimmed, Unix line ends');
        self::assertSame('Test C.', $c['name'], 'the short name, never the full one');
        self::assertFalse($c['staff']);
        self::assertTrue($c['own']);
        self::assertFalse($c['pending']);
        self::assertMatchesRegularExpression('/^\d{1,2} [A-Z][a-z]{2} \d{4}, \d\d:\d\d$/', $c['time_label']);
        self::assertSame(['id', 'name', 'staff', 'body', 'status', 'pending', 'own', 'time', 'time_label'], array_keys($c), 'no email, no full name, no account id');

        $row = $this->db->one('SELECT * FROM news_comments WHERE id = ?', [$c['id']]);
        self::assertSame($uid, (int) $row['user_id']);
        self::assertSame('visible', $row['status']);
        self::assertSame(['id', 'article_id', 'user_id', 'body', 'status', 'reports', 'created_at', 'updated_at'], array_keys($row), 'no network address is stored');
        self::assertSame(1, $this->visibleCount($article));
        foreach ($this->db->all('SELECT bucket FROM rate_limits') as $b) {
            self::assertStringNotContainsString('203.0.113.7', (string) $b['bucket'], 'the limit buckets are hashed');
        }
    }

    public function testTheApiIsSameOriginFetchOnlyAndAsksForSignIn(): void
    {
        $article = $this->makeArticle();
        $plain = $this->request('POST', '/api/news/' . $article . '/comments', [], ['Accept' => 'application/json'], '{"body":"Hello there"}');
        self::assertSame(403, $plain->status, 'without X-Requested-With');
        $cross = $this->request('POST', '/api/news/' . $article . '/comments', [], ['X-Requested-With' => 'fetch', 'Sec-Fetch-Site' => 'cross-site'], '{"body":"Hello there"}');
        self::assertSame(403, $cross->status);

        $signedOut = $this->postComment($article, 'Hello there');
        self::assertSame(401, $signedOut->status);
        self::assertSame(['error' => 'unauthenticated'], self::data($signedOut));
        self::assertSame(401, $this->api('POST', '/api/news/comments/1/delete')->status);
        self::assertSame(401, $this->api('POST', '/api/news/comments/1/report')->status);
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM news_comments'));
    }

    public function testTheLimitsRefuseWithWhatToFix(): void
    {
        $article = $this->makeArticle();
        $uid = $this->signInClient();
        $this->age($uid);
        $cases = [
            'x' => Comments::TOO_SHORT,
            "  \n " => Comments::TOO_SHORT,
            str_repeat('a', 2001) => 'Keep your comment to 2,000 characters or fewer. It is 2,001 now.',
            'Write to me at ada@example.com about it' => Comments::NO_EMAIL,
            'Or mailto:ada@example.com' => Comments::NO_EMAIL,
            'See https://a.example/1 and http://b.example/2 and www.c.example' => Comments::TOO_MANY_LINKS,
        ];
        foreach ($cases as $body => $message) {
            $r = $this->postComment($article, (string) $body);
            self::assertSame(422, $r->status, (string) $body);
            $data = self::data($r);
            self::assertSame('invalid', $data['error']);
            self::assertSame($message, $data['errors']['body']);
            self::assertSame($message, $data['message']);
        }
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM news_comments'), 'nothing refused is stored');

        self::assertSame(201, $this->postComment($article, 'Two links are fine: https://www.a.example/x and www.b.example')->status, 'https://www. is one address');
        self::assertSame(201, $this->postComment($article, str_repeat('b', 2000))->status, 'exactly the longest');
        self::assertSame(1, Comments::links('https://www.example.com/page'));
        self::assertSame(0, Comments::links('examplewww.thing and nohttp://'));
        self::assertSame("A\n\nB", Comments::clean("A\u{202E}\n\n\n\nB \u{200B}"), 'invisible and reordering characters go, and runs of blank lines');
    }

    public function testTheSameWordsTwiceAreTheOneComment(): void
    {
        $article = $this->makeArticle();
        $this->signInClient();
        $first = self::data($this->postComment($article, 'Worth testing on the next show.'));
        $again = $this->postComment($article, "Worth testing on the next show.\n");
        self::assertSame(200, $again->status);
        self::assertTrue(self::data($again)['repeat']);
        self::assertSame($first['comment']['id'], self::data($again)['comment']['id']);
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM news_comments'));
        self::assertSame(1, $this->visibleCount($article));

        $this->db->update('news_comments', ['created_at' => time() - 86401], ['id' => $first['comment']['id']]);
        self::assertSame(201, $this->postComment($article, 'Worth testing on the next show.')->status, 'a day later it is a new comment');
        self::assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM news_comments'));

        // Words a moderator hid are not posted again under a new id.
        $this->db->update('news_comments', ['status' => 'hidden'], ['article_id' => $article]);
        $hidden = $this->postComment($article, 'Worth testing on the next show.');
        self::assertSame(409, $hidden->status);
        self::assertSame(Comments::HIDDEN_REPEAT, self::data($hidden)['message']);
        self::assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM news_comments'));
    }

    public function testPostingIsRateLimitedPerAccountAndPerNetwork(): void
    {
        $article = $this->makeArticle();
        $this->signInClient();
        for ($i = 1; $i <= 5; $i++) {
            self::assertSame(201, $this->postComment($article, 'Comment number ' . $i)->status);
        }
        $sixth = $this->postComment($article, 'Comment number 6');
        self::assertSame(429, $sixth->status);
        self::assertSame(['error' => 'rate_limited', 'message' => Comments::SLOW_DOWN, 'errors' => []], self::data($sixth));
        self::assertSame(5, (int) $this->db->value('SELECT COUNT(*) FROM news_comments'));

        $other = $this->switchTo('other@example.test');
        for ($i = 0; $i < 30; $i++) {
            $this->app->limiter()->hit('news-comment-day:' . $other, 30, 86400);
        }
        self::assertSame(Comments::DAY_LIMIT, self::data($this->postComment($article, 'One more today'))['message']);

        $third = $this->switchTo('third@example.test');
        self::assertGreaterThan(0, $third);
        // The network bucket is shared by every account behind one address (this test's 203.0.113.7).
        for ($i = 0; $i < 20; $i++) {
            $this->app->limiter()->hit('news-comment-ip:203.0.113.7', 20, 86400);
        }
        $network = $this->postComment($article, 'From the same network');
        self::assertSame(429, $network->status);
        self::assertSame(Comments::NETWORK_LIMIT, self::data($network)['message']);
        self::assertSame(5, (int) $this->db->value('SELECT COUNT(*) FROM news_comments'));
    }

    public function testCommentsWaitInApprovalModeAndFromNewAccountsWithLinks(): void
    {
        $article = $this->makeArticle();
        $young = $this->signInClient();
        $linked = self::data($this->postComment($article, 'Details are at https://example.com/specs'));
        self::assertSame('pending', $linked['status'], 'a link from an account under a day old waits');
        self::assertTrue($linked['comment']['pending']);
        self::assertSame(201, $this->postComment($article, 'No link in this one at all')->status);
        self::assertSame('visible', $this->commentStatus((int) $this->db->value("SELECT id FROM news_comments WHERE body = 'No link in this one at all'")));
        self::assertSame(1, $this->visibleCount($article), 'pending comments are not counted');

        $this->age($young);
        self::assertSame('visible', self::data($this->postComment($article, 'Older now: https://example.com/more'))['status']);

        (new Settings($this->app))->save(['comments' => 'approval'], null);
        self::assertSame('pending', self::data($this->postComment($article, 'In approval mode every comment waits'))['status']);
        $mails = $this->adminMails('pending');
        self::assertCount(1, $mails, 'the administrators hear once a day, not once a comment');
        self::assertSame('owner@example.test', $mails[0]['to']);
        self::assertSame('pending', self::data($this->postComment($article, 'A second waiting comment'))['status']);
        self::assertCount(1, $this->adminMails('pending'));

        $this->switchTo('admin@example.test', 'admin');
        $staff = self::data($this->postComment($article, 'A word from the team, with https://example.com/notes'));
        self::assertSame('visible', $staff['status'], 'staff comments never wait');
        self::assertTrue($staff['comment']['staff']);
        self::assertSame(3, $this->visibleCount($article));
    }

    public function testStateSaysWhatThePersonMayDoAndWhatTheCachedPageLacks(): void
    {
        $article = $this->makeArticle();
        $me = $this->makeUser('me@example.test');
        $other = $this->makeUser('other@example.test');
        $drawn = time() - 300;
        $onPage = $this->comment($article, $other, 'On the page when it was drawn', 'visible', $drawn - 60);
        $mineOnPage = $this->comment($article, $me, 'Mine, on the page', 'visible', $drawn - 50);
        $hiddenSince = $this->comment($article, $other, 'Hidden since', 'visible', $drawn - 40);
        $deletedSince = $this->comment($article, $other, 'Deleted since', 'visible', $drawn - 30);
        $pendingBefore = $this->comment($article, $me, 'Mine, waiting from before', 'pending', $drawn - 20);
        $mineSince = $this->comment($article, $me, 'Mine, posted since', 'visible', $drawn + 30);
        $this->comment($article, $other, 'Someone else waiting', 'pending', $drawn + 40);
        $this->db->update('news_comments', ['status' => 'hidden', 'updated_at' => time()], ['id' => $hiddenSince]);
        $this->db->exec('DELETE FROM news_comments WHERE id = ?', [$deletedSince]);
        $url = '/api/news/' . $article . '/state?since=' . $drawn . '&ids=' . implode(',', [$onPage, $mineOnPage, $hiddenSince, $deletedSince]);

        $out = $this->api('GET', $url);
        self::assertSame(200, $out->status);
        self::assertStringContainsString('no-store', (string) $out->getHeader('Cache-Control'));
        self::assertSame([
            'signed_in' => false,
            'can_comment' => false,
            'closed_reason' => null,
            'name' => null,
            'mine' => [],
            'removed' => [$hiddenSince, $deletedSince],
            'own' => [],
            'staff' => false,
        ], self::data($out));

        $this->signInClient('me@example.test');
        $state = self::data($this->api('GET', $url));
        self::assertTrue($state['signed_in']);
        self::assertTrue($state['can_comment']);
        self::assertSame('Test M.', $state['name']);
        self::assertSame([$hiddenSince, $deletedSince], $state['removed']);
        self::assertSame([$mineOnPage, $pendingBefore, $mineSince], $state['own']);
        self::assertSame([$pendingBefore, $mineSince], array_column($state['mine'], 'id'), 'every pending one, and the visible ones since the page was drawn');
        self::assertSame([true, false], array_column($state['mine'], 'pending'));
        self::assertNotContains('Someone else waiting', array_column($state['mine'], 'body'));
        self::assertFalse($state['staff']);

        // Approved since the page was drawn: the author sees it even on the older copy.
        $this->db->update('news_comments', ['status' => 'visible', 'updated_at' => time()], ['id' => $pendingBefore]);
        self::assertContains($pendingBefore, array_column(self::data($this->api('GET', $url))['mine'], 'id'));

        $junk = self::data($this->api('GET', '/api/news/' . $article . '/state?since=abc&ids=999,x,-3,' . $onPage));
        self::assertSame([999], $junk['removed'], 'only whole ids are looked at, and one that is not a visible comment here is not to be shown');
    }

    public function testReportsHideAtThreeExceptStaffAndEmailOnTheFirst(): void
    {
        $article = $this->makeArticle();
        $author = $this->makeUser('author@example.test');
        $target = $this->comment($article, $author, 'Something people dislike');

        $first = $this->switchTo('r1@example.test');
        $r = $this->api('POST', '/api/news/comments/' . $target . '/report');
        self::assertSame(200, $r->status);
        self::assertSame('reported', self::data($r)['result']);
        self::assertSame('already', self::data($this->api('POST', '/api/news/comments/' . $target . '/report'))['result'], 'once per person');
        self::assertSame(1, (int) $this->db->value('SELECT reports FROM news_comments WHERE id = ?', [$target]));
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM news_comment_reports WHERE comment_id = ? AND user_id = ?', [$target, $first]));
        $mails = $this->adminMails('reported');
        self::assertCount(1, $mails);
        self::assertSame('A comment was reported', $mails[0]['subject']);
        self::assertStringContainsString('Something people dislike', $mails[0]['html']);
        self::assertStringContainsString('/portal/admin/news/comments/?tab=reported#comment-' . $target, $mails[0]['html']);

        $this->switchTo('r2@example.test');
        $this->api('POST', '/api/news/comments/' . $target . '/report');
        self::assertCount(1, $this->adminMails('reported'), 'only the first report emails');
        self::assertSame('visible', $this->commentStatus($target));

        $this->switchTo('r3@example.test');
        $this->api('POST', '/api/news/comments/' . $target . '/report');
        self::assertSame('hidden', $this->commentStatus($target), 'the third report hides it');
        self::assertSame(3, (int) $this->db->value('SELECT reports FROM news_comments WHERE id = ?', [$target]));
        self::assertSame(0, $this->visibleCount($article));
        self::assertCount(1, $this->adminMails('hidden'));
        self::assertSame(404, $this->api('POST', '/api/news/comments/' . $target . '/report')->status, 'a hidden comment cannot be reported');

        // Not one's own; administrators moderate instead; and a staff comment is never hidden automatically.
        $own = $this->comment($article, (int) $this->db->value("SELECT id FROM users WHERE email = 'r3@example.test'"), 'My own words');
        $refused = $this->api('POST', '/api/news/comments/' . $own . '/report');
        self::assertSame(422, $refused->status);
        self::assertSame('own', self::data($refused)['error']);
        $adminId = $this->switchTo('admin@example.test', 'admin');
        $byStaff = $this->comment($article, $adminId, 'A staff comment');
        $asStaff = $this->api('POST', '/api/news/comments/' . $own . '/report');
        self::assertSame(403, $asStaff->status);
        self::assertSame('staff', self::data($asStaff)['error']);
        foreach (['s1@example.test', 's2@example.test', 's3@example.test', 's4@example.test'] as $email) {
            $this->switchTo($email);
            self::assertSame('reported', self::data($this->api('POST', '/api/news/comments/' . $byStaff . '/report'))['result']);
        }
        self::assertSame('visible', $this->commentStatus($byStaff));
        self::assertSame(4, (int) $this->db->value('SELECT reports FROM news_comments WHERE id = ?', [$byStaff]));
    }

    public function testReportsAreRateLimitedPerDay(): void
    {
        $article = $this->makeArticle();
        $author = $this->makeUser('author@example.test');
        $target = $this->comment($article, $author, 'A comment to report');
        $uid = $this->signInClient();
        for ($i = 0; $i < 20; $i++) {
            $this->app->limiter()->hit('news-report:' . $uid, 20, 86400);
        }
        $r = $this->api('POST', '/api/news/comments/' . $target . '/report');
        self::assertSame(429, $r->status);
        self::assertSame(Comments::REPORT_LIMIT, self::data($r)['message']);
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM news_comment_reports'));
    }

    public function testAPersonDeletesTheirOwnCommentOnly(): void
    {
        $article = $this->makeArticle();
        $other = $this->makeUser('other@example.test');
        $theirs = $this->comment($article, $other, 'Not yours to delete');
        $this->signInClient();
        $mine = self::data($this->postComment($article, 'I will take this back'))['comment']['id'];
        self::assertSame(2, $this->visibleCount($article));

        self::assertSame(404, $this->api('POST', '/api/news/comments/' . $theirs . '/delete')->status);
        self::assertSame('visible', $this->commentStatus($theirs));
        $r = $this->api('POST', '/api/news/comments/' . $mine . '/delete');
        self::assertSame(200, $r->status);
        self::assertSame(['ok' => true, 'id' => $mine], self::data($r));
        self::assertNull($this->commentStatus($mine));
        self::assertSame(1, $this->visibleCount($article));
        self::assertSame(404, $this->api('POST', '/api/news/comments/' . $mine . '/delete')->status, 'gone already');
    }

    public function testAPersonSeesAndDeletesTheirOwnHiddenComment(): void
    {
        $article = $this->makeArticle(['slug' => 'hidden-but-mine']);
        $other = $this->makeUser('other@example.test');
        $uid = $this->signInClient();
        $hidden = $this->comment($article, $uid, 'Hidden by three reports', 'hidden', time() - 900, 3);
        $theirs = $this->comment($article, $other, 'Someone else, showing', 'visible', time() - 800);
        $theirsHidden = $this->comment($article, $other, 'Someone else, hidden', 'hidden', time() - 700);
        $alsoHidden = $this->comment($article, $uid, 'Hidden by a moderator too', 'hidden', time() - 600);
        self::assertSame(1, $this->visibleCount($article));

        // The comment page: the person's hidden comments, marked, each with its delete form; never someone else's.
        $page = $this->request('GET', '/news/video/hidden-but-mine/comment/');
        self::assertSame(200, $page->status);
        foreach ([$hidden, $alsoHidden] as $id) {
            self::assertStringContainsString('name="action" value="delete"', self::item($page->body, $id));
            self::assertStringContainsString('name="comment" value="' . $id . '"', self::item($page->body, $id));
            self::assertStringContainsString(Comments::HIDDEN_NOTE, self::item($page->body, $id));
            self::assertStringContainsString('news-comment--hidden', self::item($page->body, $id));
            self::assertStringNotContainsString('value="report"', self::item($page->body, $id));
        }
        self::assertStringNotContainsString(Comments::HIDDEN_NOTE, self::item($page->body, $theirs));
        self::assertSame('', self::item($page->body, $theirsHidden), 'someone else\'s hidden comment is not shown');

        // The cached page's state: in mine as hidden (whenever the page was drawn) and in own, so it gets Delete.
        $state = self::data($this->api('GET', '/api/news/' . $article . '/state?since=' . time() . '&ids=' . $theirs));
        self::assertSame([$hidden, $alsoHidden], $state['own']);
        self::assertSame([$hidden, $alsoHidden], array_column($state['mine'], 'id'));
        self::assertSame(['hidden', 'hidden'], array_column($state['mine'], 'status'));
        self::assertSame([false, false], array_column($state['mine'], 'pending'));
        self::assertSame([true, true], array_column($state['mine'], 'own'));
        self::assertNotContains('Someone else, hidden', array_column($state['mine'], 'body'));
        self::assertStringContainsString('data-comment-hidden hidden', $this->partial($article), 'the cached page carries the note, inert');

        // Deleted from the comment page and through the API alike; the visible count is unchanged.
        $r = $this->post('/news/video/hidden-but-mine/comment/', ['action' => 'delete', 'comment' => (string) $hidden]);
        self::assertSame(303, $r->status);
        self::assertSame('/news/video/hidden-but-mine/comment/#comments', $r->getHeader('Location'));
        self::assertSame('Your comment is deleted.', end($_SESSION['_flash'])['message']);
        self::assertNull($this->commentStatus($hidden));
        $api = $this->api('POST', '/api/news/comments/' . $alsoHidden . '/delete');
        self::assertSame(200, $api->status);
        self::assertSame(['ok' => true, 'id' => $alsoHidden], self::data($api));
        self::assertNull($this->commentStatus($alsoHidden));
        self::assertSame(404, $this->api('POST', '/api/news/comments/' . $theirsHidden . '/delete')->status, 'never someone else\'s');
        self::assertSame('hidden', $this->commentStatus($theirsHidden));
        self::assertSame(1, $this->visibleCount($article));
    }

    public function testAPersonDeletesTheirOwnCommentsOnceTheArticleIsNotLive(): void
    {
        $article = $this->makeArticle(['slug' => 'taken-down-later', 'title' => 'A headline that was taken down']);
        $other = $this->makeUser('other@example.test');
        $uid = $this->signInClient();
        $first = $this->comment($article, $uid, 'My first word on it', 'visible', time() - 900);
        $second = $this->comment($article, $uid, 'And a second, waiting', 'pending', time() - 800);
        $theirs = $this->comment($article, $other, 'Someone else said this', 'visible', time() - 700);
        $this->db->update('news_articles', ['status' => 'draft'], ['id' => $article]);
        $page = '/news/video/taken-down-later/comment/';

        // The comment page shows the person their own comments with delete forms, and nothing else of the article.
        $r = $this->request('GET', $page);
        self::assertSame(200, $r->status);
        self::assertStringContainsString('noindex', (string) $r->getHeader('X-Robots-Tag'));
        self::assertStringContainsString('name="comment" value="' . $first . '"', self::item($r->body, $first));
        self::assertStringContainsString('name="comment" value="' . $second . '"', self::item($r->body, $second));
        self::assertSame('', self::item($r->body, $theirs));
        self::assertStringNotContainsString('Someone else said this', $r->body);
        self::assertStringNotContainsString('A headline that was taken down', $r->body, 'not the title of an article that is not published');
        self::assertStringNotContainsString('href="/news/video/taken-down-later/"', $r->body);
        self::assertStringContainsString(e(Comments::NOT_LIVE_OWN), $r->body);
        self::assertStringNotContainsString('value="post"', $r->body);
        self::assertStringNotContainsString('value="report"', $r->body);

        // Nothing but a delete is taken there.
        self::assertSame(404, $this->post($page, ['action' => 'post', 'body' => 'Still trying'])->status);
        self::assertSame(404, $this->post($page, ['action' => 'report', 'comment' => (string) $theirs])->status);
        self::assertSame(0, (int) $this->db->value('SELECT reports FROM news_comments WHERE id = ?', [$theirs]));
        self::assertSame(3, (int) $this->db->value('SELECT COUNT(*) FROM news_comments'));

        // The API deletes the person's own; the page's form deletes the last, and the answer goes to the account page.
        $api = $this->api('POST', '/api/news/comments/' . $first . '/delete');
        self::assertSame(200, $api->status);
        self::assertSame(['ok' => true, 'id' => $first], self::data($api));
        self::assertNull($this->commentStatus($first));
        self::assertSame(1, $this->visibleCount($article));
        self::assertSame(404, $this->api('POST', '/api/news/comments/' . $theirs . '/delete')->status);
        $last = $this->post($page, ['action' => 'delete', 'comment' => (string) $second]);
        self::assertSame(303, $last->status);
        self::assertSame('/portal/account/', $last->getHeader('Location'));
        self::assertSame('success', end($_SESSION['_flash'])['type']);
        self::assertNull($this->commentStatus($second));
        self::assertSame('visible', $this->commentStatus($theirs));
        self::assertSame(404, $this->request('GET', $page)->status, 'with none of theirs left, the page is no one\'s');
    }

    // ------------------------------------------------------------ writers racing

    public function testEveryWriteToAnArticlesCommentsTakesTheArticleRowFirst(): void
    {
        $pdo = $this->stagedDatabase();
        $article = $this->makeArticle();
        $elsewhere = $this->makeArticle();
        $author = $this->makeUser('author@example.test');
        $this->age($author);
        $readers = array_map(fn (string $email): int => $this->makeUser($email), ['r1@example.test', 'r2@example.test', 'r3@example.test']);
        $comments = new Comments($this->app);
        $pdo->transactions = [];

        $posted = $comments->post($article, $this->userRow($author), 'Two of these at once queue on the article row.');
        self::assertSame('visible', $posted['status']);
        $id = (int) $posted['comment']['id'];
        $this->comment($elsewhere, $author, 'On another article');
        foreach ($readers as $reader) {
            self::assertSame('reported', $comments->report($id, $this->userRow($reader)));
        }
        self::assertSame('hidden', $this->commentStatus($id), 'the third report hid it, in the same transaction as its recount');
        self::assertTrue($comments->moveStatus($id, 'hidden', 'visible'));
        self::assertSame(3, $comments->clearReports($id));
        self::assertTrue($comments->remove($id, $this->userRow($author)));
        $this->comment($article, $author, 'Posted again');
        $kept = $this->comment($elsewhere, $readers[2], 'Someone else, elsewhere');
        $this->db->insert('news_comment_reports', ['comment_id' => $kept, 'user_id' => $readers[0], 'created_at' => time()]);
        self::assertSame(['comments' => 2, 'reports' => 0], $comments->deleteForAccount($author), 'its comments on both articles');
        self::assertSame(['comments' => 0, 'reports' => 1], $comments->deleteForAccount($readers[0]));

        $lock = 'UPDATE news_articles SET comment_count = comment_count WHERE id = ?';
        $writes = 0;
        foreach ($pdo->transactions as $i => $statements) {
            $first = null;
            foreach ($statements as $n => $sql) {
                if (preg_match('/^(?:INSERT INTO|UPDATE|DELETE FROM) news_comment(?:s|_reports)\b/', $sql) === 1) {
                    $first = $n;
                    break;
                }
            }
            if ($first === null) {
                continue;
            }
            $writes++;
            self::assertContains($lock, array_slice($statements, 0, $first), 'transaction ' . $i . ' wrote comments before it took the article row: ' . implode(' | ', $statements));
        }
        self::assertSame(1 + 3 + 1 + 1 + 1 + 2, $writes, 'post, three reports, show, clear, delete and two account deletions');
        self::assertSame(0, $this->visibleCount($article));
        self::assertSame(1, $this->visibleCount($elsewhere));
    }

    public function testADeleteThatRacesAnApprovalLeavesTheCountTrue(): void
    {
        $pdo = $this->stagedDatabase();
        $article = $this->makeArticle();
        $author = $this->makeUser('author@example.test');
        $this->comment($article, $this->makeUser('other@example.test'), 'Already showing');
        $waiting = $this->comment($article, $author, 'Waiting for approval', 'pending');
        $comments = new Comments($this->app);
        self::assertSame(1, $this->visibleCount($article));

        // The administrator's approval lands after the author's delete has read the comment as pending, before its
        // transaction begins.
        $pdo->beforeBegin = function () use ($waiting, $article): void {
            self::assertTrue((new Comments($this->app))->moveStatus($waiting, 'pending', 'visible'));
            self::assertSame(2, $this->visibleCount($article));
        };
        self::assertTrue($comments->remove($waiting, $this->userRow($author)));
        self::assertNull($pdo->beforeBegin, 'the approval ran in between');
        self::assertNull($this->commentStatus($waiting));
        self::assertSame(1, $this->visibleCount($article), 'the count is the visible comments that are left');

        // The account's deletion read its comments before an approval, and deletes the approved one too.
        $later = $this->comment($article, $author, 'Waiting again', 'pending');
        $pdo->before['DELETE FROM news_comments WHERE user_id'] = function () use ($later, $article): void {
            $this->db->exec("UPDATE news_comments SET status = 'visible' WHERE id = ?", [$later]);
            Comments::recountArticles($this->db, [$article]);
        };
        self::assertSame(['comments' => 1, 'reports' => 0], $comments->deleteForAccount($author));
        self::assertSame([], $pdo->before, 'the approval ran in between');
        self::assertSame(1, $this->visibleCount($article));
    }

    public function testClosedCommentsTakeNoneAndKeepTheOnesThere(): void
    {
        $article = $this->makeArticle();
        $other = $this->makeUser('other@example.test');
        $kept = $this->comment($article, $other, 'Posted while it was open');
        $this->signInClient();

        $this->db->update('news_articles', ['comments_open' => 0], ['id' => $article]);
        $r = $this->postComment($article, 'Too late for this one');
        self::assertSame(403, $r->status);
        self::assertSame(['error' => 'closed', 'message' => Comments::CLOSED_ARTICLE, 'errors' => []], self::data($r));
        $state = self::data($this->api('GET', '/api/news/' . $article . '/state'));
        self::assertTrue($state['signed_in']);
        self::assertFalse($state['can_comment']);
        self::assertSame(Comments::CLOSED_ARTICLE, $state['closed_reason']);
        $html = $this->partial($article);
        self::assertStringContainsString(Comments::CLOSED_ARTICLE, $html);
        self::assertStringContainsString('id="comment-' . $kept . '"', $html, 'the comments already there stay');
        self::assertStringNotContainsString('data-comment-cta', $html);

        $this->db->update('news_articles', ['comments_open' => 1], ['id' => $article]);
        (new Settings($this->app))->save(['comments' => 'closed'], null);
        self::assertSame(Comments::CLOSED_SITE, self::data($this->postComment($article, 'Closed everywhere now'))['message']);
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM news_comments'));
    }

    public function testEveryRouteAnswers404ForAnArticleThatIsNotLive(): void
    {
        $draft = $this->makeArticle(['status' => 'draft']);
        $scheduled = $this->makeArticle(['published_at' => time() + 3600]);
        $author = $this->makeUser('author@example.test');
        $this->signInClient();
        foreach ([$draft, $scheduled] as $article) {
            $slug = $this->slugOf($article);
            $c = $this->comment($article, $author, 'On an article no one can read');
            self::assertSame(404, $this->api('GET', '/api/news/' . $article . '/state')->status);
            self::assertSame(404, $this->postComment($article, 'Hello there')->status);
            self::assertSame(404, $this->api('POST', '/api/news/comments/' . $c . '/report')->status);
            foreach (['/news/video/' . $slug . '/comment/', '/news/' . $slug . '/comment/', '/news/legal/' . $slug . '/comment/'] as $page) {
                $r = $this->request('GET', $page);
                self::assertSame(404, $r->status, $page);
                self::assertNull($r->getHeader('Location'), $page . ': no hop says an article is there');
                self::assertSame(404, $this->post($page, ['action' => 'post', 'body' => 'Hello there'])->status, $page);
            }
        }
        self::assertSame(404, $this->api('GET', '/api/news/999/state')->status);
        self::assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM news_comments'));
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM news_comment_reports'));
    }

    // --------------------------------------------------------- the cached section

    public function testTheSectionIsTheSameForEveryoneAndStartsNoSession(): void
    {
        $article = $this->makeArticle(['slug' => 'radio-mic-changes']);
        $ada = $this->makeUser('ada@example.test');
        $this->db->update('users', ['name' => "Ada \u{202E}Lovelace"], ['id' => $ada]);
        $mail = $this->makeUser('mailname@example.test');
        $this->db->update('users', ['name' => 'grace@example.com'], ['id' => $mail]);
        $team = $this->makeUser('team@example.test', 'correct horse battery', 'admin');
        $this->db->update('users', ['name' => 'Ben Rohleder'], ['id' => $team]);
        $fake = $this->makeUser('fake@example.test');
        $this->db->update('users', ['name' => 'Patterns team'], ['id' => $fake]);
        $first = $this->comment($article, $ada, "<script>alert(1)</script> **not bold**\n[a link](https://evil.example/)", 'visible', time() - 900);
        $this->comment($article, $mail, 'From a name typed as an address', 'visible', time() - 800);
        $this->comment($article, $team, 'From the team', 'visible', time() - 700);
        $this->comment($article, $fake, 'Pretending to be staff', 'visible', time() - 600);
        $this->comment($article, $ada, 'Waiting, not shown', 'pending');
        $this->comment($article, $ada, 'Hidden, not shown', 'hidden');

        $before = time();
        $html = $this->partial($article);
        self::assertFalse($this->app->session()->isStarted(), 'no session for a cached page');
        self::assertMatchesRegularExpression('/data-rendered-at="(\d+)"/', $html);
        preg_match('/data-rendered-at="(\d+)"/', $html, $m);
        self::assertGreaterThanOrEqual($before, (int) $m[1]);
        self::assertStringContainsString('data-article-id="' . $article . '"', $html);
        self::assertSame(4, preg_match_all('/<li class="news-comment[^"]*" id="comment-\d+" data-comment-id="\d+">/', $html));
        self::assertStringNotContainsString('Waiting, not shown', $html);
        self::assertStringNotContainsString('Hidden, not shown', $html);
        self::assertStringContainsString('id="comment-' . $first . '"', $html);

        // Plain text: escaped, line breaks kept, no Markdown and no live links.
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; **not bold**<br />', $html);
        self::assertStringNotContainsString('href="https://evil.example/', $html);
        // Names: short, never an address, no reordering characters; the badge is an element of its own.
        self::assertStringContainsString('<bdi data-comment-author>Ada L.</bdi>', $html);
        self::assertStringContainsString('<bdi data-comment-author>A client</bdi>', $html);
        self::assertStringNotContainsString('grace@example.com', $html);
        self::assertMatchesRegularExpression('#<bdi data-comment-author>Ben R\.</bdi></span>\s*<span class="c-tag news-comment-badge">Patterns team</span>#', $html);
        self::assertSame(1, substr_count($html, '<span class="c-tag news-comment-badge">Patterns team</span>'), 'a name can never produce the badge');
        self::assertStringContainsString('<bdi data-comment-author>Patterns T.</bdi>', $html);
        self::assertStringNotContainsString('@example.test', $html);

        // Open registration: sign in (the comment page, behind sign-in) or create an account, coming back after.
        self::assertStringContainsString('<a href="/news/video/radio-mic-changes/comment/" data-comment-signin>Sign in</a> or <a href="/portal/register/?next=%2Fnews%2Fvideo%2Fradio-mic-changes%2Fcomment%2F" data-comment-register>create an account</a> to comment.', $html);
        self::assertStringContainsString('<template data-comment-form>', $html);
        self::assertStringContainsString('role="status" data-comment-status', $html);
        self::assertStringNotContainsString('_csrf', $html);
        self::assertStringNotContainsString('style="', $html);
        self::assertStringNotContainsString("\u{2014}", $html);
        self::assertStringContainsString('/assets/css/pages/news-comments.css', $this->app->view()->stack('head'));
        self::assertStringContainsString('/assets/js/news-comments.js', $this->app->view()->stack('scripts'));

        $this->bootApp(['security' => ['registration' => 'invite']]);
        $article = $this->makeArticle(['slug' => 'invitation-only']);
        self::assertStringContainsString('<a href="/news/video/invitation-only/comment/" data-comment-signin>Sign in to comment</a>.', $this->partial($article));
        self::assertStringNotContainsString('create an account', $this->partial($article));
        self::assertStringContainsString('No comments yet.', $this->partial($article));
    }

    public function testTheArticlePageCarriesTheSectionAndStaysPublicForASignedInReader(): void
    {
        $article = $this->makeArticle(['slug' => 'cached-for-everyone']);
        $this->comment($article, $this->makeUser('other@example.test'), 'A comment everyone sees');
        $this->signInClient();
        $_SESSION = [];
        $r = $this->request('GET', '/news/video/cached-for-everyone/');
        self::assertSame(200, $r->status);
        self::assertStringStartsWith('public', (string) $r->getHeader('Cache-Control'));
        self::assertSame([], $r->cookies(), 'no cookie, even with a signed-in cookie jar');
        self::assertStringContainsString('data-comments data-article-id="' . $article . '"', $r->body);
        self::assertStringContainsString('A comment everyone sees', $r->body);
    }

    // ---------------------------------------------------------- the comment page

    public function testTheCommentPageNeedsSignInAndBringsThePersonBack(): void
    {
        $this->makeArticle(['slug' => 'come-back-here']);
        $r = $this->request('GET', '/news/video/come-back-here/comment/');
        self::assertSame(302, $r->status);
        self::assertSame('/portal/login/?next=' . rawurlencode('/news/video/come-back-here/comment/'), $r->getHeader('Location'));
        $this->makeUser();
        $login = $this->post('/portal/login/', ['email' => 'client@example.test', 'password' => 'correct horse battery', 'next' => '/news/video/come-back-here/comment/']);
        self::assertSame('/news/video/come-back-here/comment/', $login->getHeader('Location'));
    }

    public function testTheCommentPageListsCommentsWithTheirForms(): void
    {
        $article = $this->makeArticle(['slug' => 'no-script-reader']);
        $other = $this->makeUser('other@example.test');
        $theirs = $this->comment($article, $other, 'Someone else said this', 'visible', time() - 900);
        $theirsWaiting = $this->comment($article, $other, 'Someone else is waiting', 'pending');
        $uid = $this->signInClient();
        $mine = $this->comment($article, $uid, 'I said this', 'visible', time() - 800);
        $waiting = $this->comment($article, $uid, 'Mine is waiting', 'pending', time() - 700);

        $r = $this->request('GET', '/news/video/no-script-reader/comment/');
        self::assertSame(200, $r->status);
        self::assertStringContainsString('no-store', (string) $r->getHeader('Cache-Control'));
        self::assertStringContainsString('noindex', (string) $r->getHeader('X-Robots-Tag'));
        self::assertStringContainsString('<meta name="robots" content="noindex', $r->body);
        self::assertSame(1, substr_count($r->body, '<h1'));
        self::assertStringContainsString('action="/news/video/no-script-reader/comment/"', $r->body);
        self::assertStringContainsString('name="_csrf"', $r->body);
        self::assertStringContainsString('Sessions end after 2 hours without activity.', $r->body);
        self::assertStringContainsString('shown as <strong><bdi>Test C.</bdi></strong>', $r->body);
        self::assertStringContainsString('href="/news/video/no-script-reader/#comments"', $r->body, 'a link back to the article');
        $main = (string) strstr($r->body, '<main');
        self::assertStringNotContainsString('style="', $main);
        self::assertStringNotContainsString('<script', $main);

        $item = static function (string $html, int $id): string {
            preg_match('#<li class="[^"]*" id="comment-' . $id . '">.*?</li>#s', $html, $m);
            return $m[0] ?? '';
        };
        self::assertStringContainsString('name="action" value="delete"', $item($r->body, $mine));
        self::assertStringContainsString('name="comment" value="' . $mine . '"', $item($r->body, $mine));
        self::assertStringContainsString(Comments::PENDING_NOTE, $item($r->body, $waiting));
        self::assertStringContainsString('name="action" value="report"', $item($r->body, $theirs));
        self::assertStringNotContainsString('value="delete"', $item($r->body, $theirs));
        self::assertSame('', $item($r->body, $theirsWaiting), 'someone else\'s waiting comment is not shown');
    }

    public function testTheCommentPageFormsAnswerWithA303BackToTheCommentPage(): void
    {
        $article = $this->makeArticle(['slug' => 'forms-without-script']);
        $other = $this->makeUser('other@example.test');
        $theirs = $this->comment($article, $other, 'A comment to report');
        $page = '/news/video/forms-without-script/comment/';
        $this->signInClient();

        self::assertSame(419, $this->post($page, ['action' => 'post', 'body' => 'No token'], false)->status);

        $r = $this->post($page, ['action' => 'post', 'body' => 'Posted without scripting']);
        $id = (int) $this->db->value("SELECT id FROM news_comments WHERE body = 'Posted without scripting'");
        self::assertSame(303, $r->status);
        self::assertSame($page . '#comment-' . $id, $r->getHeader('Location'), 'never the cached article');
        self::assertSame('Your comment is posted.', end($_SESSION['_flash'])['message']);
        self::assertSame(2, $this->visibleCount($article));
        self::assertStringContainsString('Posted without scripting', $this->request('GET', $page)->body);

        $bad = $this->post($page, ['action' => 'post', 'body' => 'Mail me: ada@example.com']);
        self::assertSame($page . '#comment-form', $bad->getHeader('Location'));
        self::assertSame(['body' => Comments::NO_EMAIL], $_SESSION['_errors']);
        $shown = $this->request('GET', $page)->body;
        self::assertStringContainsString('id="comment-errors"', $shown);
        self::assertStringContainsString('<a href="#comment-body">' . e(Comments::NO_EMAIL) . '</a>', $shown);
        self::assertMatchesRegularExpression('#<textarea id="comment-body"[^>]*aria-invalid="true"#', $shown);
        self::assertStringContainsString('Mail me: ada@example.com</textarea>', $shown, 'the words are kept');

        $report = $this->post($page, ['action' => 'report', 'comment' => (string) $theirs]);
        self::assertSame($page . '#comment-' . $theirs, $report->getHeader('Location'));
        self::assertSame('success', end($_SESSION['_flash'])['type']);
        self::assertSame(1, (int) $this->db->value('SELECT reports FROM news_comments WHERE id = ?', [$theirs]));
        $own = $this->post($page, ['action' => 'report', 'comment' => (string) $id]);
        self::assertSame('danger', end($_SESSION['_flash'])['type']);
        self::assertSame(303, $own->status);

        $notMine = $this->post($page, ['action' => 'delete', 'comment' => (string) $theirs]);
        self::assertSame($page . '#comments', $notMine->getHeader('Location'));
        self::assertSame('danger', end($_SESSION['_flash'])['type']);
        self::assertSame('visible', $this->commentStatus($theirs));
        $delete = $this->post($page, ['action' => 'delete', 'comment' => (string) $id]);
        self::assertSame($page . '#comments', $delete->getHeader('Location'));
        self::assertSame('Your comment is deleted.', end($_SESSION['_flash'])['message']);
        self::assertNull($this->commentStatus($id));
        self::assertSame(1, $this->visibleCount($article));

        $odd = $this->post($page, ['action' => 'vote']);
        self::assertSame($page, $odd->getHeader('Location'));
        self::assertSame('danger', end($_SESSION['_flash'])['type']);
        self::assertArrayNotHasKey('_errors', $_SESSION, 'a refused button leaves no field error for the next form');
    }

    public function testTheCommentPageSaysWhenCommentsAreClosed(): void
    {
        $article = $this->makeArticle(['slug' => 'closed-for-comment']);
        $this->db->update('news_articles', ['comments_open' => 0], ['id' => $article]);
        $this->signInClient();
        $page = $this->request('GET', '/news/video/closed-for-comment/comment/')->body;
        self::assertStringContainsString(Comments::CLOSED_ARTICLE, $page);
        self::assertStringNotContainsString('value="post"', $page);
        $r = $this->post('/news/video/closed-for-comment/comment/', ['action' => 'post', 'body' => 'Still trying']);
        self::assertSame(303, $r->status);
        self::assertSame(Comments::CLOSED_ARTICLE, end($_SESSION['_flash'])['message']);
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM news_comments'));
    }

    // ------------------------------------------------------------------- emails

    /** The mailer swallows a broken template (it logs and returns false), so each kind is rendered here. */
    public function testTheModerationEmailRendersForEveryKind(): void
    {
        foreach (['reported' => 'A reader reported a comment', 'hidden' => 'now hidden from the article', 'pending' => 'waiting for your approval'] as $kind => $needle) {
            self::assertTrue($this->mail->send('to@example.test', 'Subject', 'news-comment-admin', [
                'kind' => $kind,
                'article' => ['title' => 'Radio <mics>', 'url' => 'https://patternsshowcontrol.test/news/radio-mics/'],
                'authorName' => 'Nina <Stage>',
                'authorEmail' => 'nina@example.test',
                'body' => "Line one\n<b>line two</b>",
                'url' => 'https://patternsshowcontrol.test/portal/admin/news/comments/?tab=reported#comment-3',
            ]), $kind);
            $html = (string) ($this->lastMailTo('to@example.test')['html'] ?? '');
            self::assertStringContainsString($needle, $html, $kind);
            self::assertStringContainsString('Line one<br />', $html);
            self::assertStringNotContainsString('<Stage>', $html);
            self::assertStringNotContainsString('<b>line two</b>', $html);
            self::assertStringNotContainsString("\u{2014}", $html);
        }
    }
}
