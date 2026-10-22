<?php

declare(strict_types=1);

namespace Psc\Tests\Feature\Portal;

use PHPUnit\Framework\Attributes\DataProvider;
use Psc\Controllers\Admin\AdminController;
use Psc\Controllers\Admin\AdminNewsCommentController;
use Psc\Controllers\Admin\AdminNewsController;
use Psc\Http\Response;
use Psc\News\ArticleStore;
use Psc\News\Images;
use Psc\News\Validator;
use Psc\Tests\TestCase;

/**
 * Industry Updates' articles in the admin (docs/NEWS.md §11): who may open them, the list and its tabs, the editor
 * (a draft needs only its headline; publishing and scheduling take every rule; a refused save keeps everything
 * typed, each error on its own row), publishing, scheduling in London time, unpublishing, deleting, the images, the
 * preview, the click counts, the overview's card and the audit. Rows, redirects, sessions and audit rows first,
 * page words beside them.
 */
final class AdminNewsTest extends TestCase
{
    private const EM = "\u{2014}";

    // ----------------------------------------------------------------- helpers

    /** @return list<array{type: string, message: string}> */
    private static function flashes(): array
    {
        return array_values($_SESSION['_flash'] ?? []);
    }

    /** @return array{type: string, message: string} */
    private static function lastFlash(): array
    {
        $all = self::flashes();
        self::assertNotSame([], $all, 'a flash message was queued');
        return end($all);
    }

    private static function assertRedirect(Response $r, string $location, int $status = 303): void
    {
        self::assertSame($status, $r->status, 'status');
        self::assertSame($location, $r->getHeader('Location'));
    }

    /** @return list<array<string, mixed>> */
    private function auditRows(string $action): array
    {
        return $this->db->all('SELECT * FROM audit_log WHERE action = ? ORDER BY id', [$action]);
    }

    /** @return array<string, mixed> */
    private function article(int $id): array
    {
        $row = $this->db->one('SELECT * FROM news_articles WHERE id = ?', [$id]);
        self::assertNotNull($row, 'article ' . $id);
        return $row;
    }

    private function newest(): int
    {
        return (int) $this->db->value('SELECT MAX(id) FROM news_articles');
    }

    /** The page's own markup, inside the portal shell's main element. */
    private function mainOf(Response $r): string
    {
        $start = strpos($r->body, '<main class="pt-main"');
        $end = strpos($r->body, '</main>');
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        return substr($r->body, (int) $start, (int) $end - (int) $start);
    }

    /**
     * A full article as the editor sends it: enough to publish.
     *
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function form(array $over = []): array
    {
        return $over + [
            'title' => 'LED processors gain a frame sync mode for mixed walls',
            'seo_title' => '',
            'summary' => '',
            'dek' => 'A firmware update locks processors to each other without genlock, which matters for anyone running mixed walls at corporate events.',
            'body' => str_repeat('The update changes how processors lock to each other on a mixed wall. ', 6),
            'confirmed' => [['text' => 'The firmware is out now.', 'url' => 'https://maker.example/news/firmware']],
            'speculation' => [['text' => '', 'url' => '']],
            'impact' => 'Test it before your next mixed wall.',
            'verdict' => 'test',
            'verdict_note' => 'Try it on a spare processor first.',
            'categories' => ['video'],
            'tags' => 'led, processing',
            'sources' => [
                ['url' => 'https://maker.example/news/firmware', 'title' => 'Firmware 9 notes', 'publisher' => 'Maker', 'kind' => 'primary'],
                ['url' => '', 'title' => '', 'publisher' => '', 'kind' => 'other'],
            ],
            'byline' => 'Ben Rohleder',
            'comments_open' => '1',
            'publish_date' => '',
            'publish_time' => '',
        ];
    }

    /** A real picture drawn with GD, as an upload. */
    private function picture(string $name, int $w = 1200, int $h = 800): array
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, (int) imagecolorallocate($im, 30, 120, 200));
        ob_start();
        imagepng($im);
        imagedestroy($im);
        return $this->fakeUpload($name, (string) ob_get_clean());
    }

    /** @return array<string, mixed> the stored image row */
    private function addImage(int $articleId, string $alt = 'The wall at load-in'): array
    {
        $r = $this->postFiles('/portal/admin/news/' . $articleId . '/images/', ['action' => 'add', 'form' => 'image-new', 'alt' => $alt], ['image' => [$this->picture('wall.png')]]);
        self::assertSame(303, $r->status);
        $row = $this->db->one('SELECT * FROM news_images WHERE article_id = ? ORDER BY id DESC LIMIT 1', [$articleId]);
        self::assertNotNull($row, 'the image was stored: ' . json_encode(self::flashes()));
        return $row;
    }

    /** One article in each state, an AI one held with its run, a picture and a click. */
    private function everyState(): array
    {
        $live = $this->makeArticle(['slug' => 'a-live-one']);
        $scheduled = $this->makeArticle(['slug' => 'a-scheduled-one', 'published_at' => time() + 7200]);
        $draft = $this->makeArticle(['slug' => 'a-draft-one', 'status' => 'draft']);
        $run = $this->db->insert('news_runs', ['slot' => '2026-10-22 06:00', 'kind' => 'scheduled', 'status' => 'done', 'articles' => 1, 'held' => 1, 'created_at' => time() - 600]);
        $held = $this->makeArticle(['slug' => 'a-held-one', 'status' => 'draft', 'origin' => 'ai', 'story_key' => 'held-story', 'review_reason' => 'It names a private individual.']);
        $this->db->update('news_articles', ['run_id' => $run], ['id' => $held]);
        $link = (int) $this->db->value('SELECT id FROM news_links WHERE article_id = ? ORDER BY id LIMIT 1', [$live]);
        $this->db->exec('UPDATE news_links SET clicks = 7 WHERE id = ?', [$link]);
        $this->db->insert('news_link_clicks', ['link_id' => $link, 'day' => gmdate('Y-m-d'), 'clicks' => 3]);
        return ['live' => $live, 'scheduled' => $scheduled, 'draft' => $draft, 'held' => $held, 'run' => $run, 'link' => $link];
    }

    // ------------------------------------------------------------------ access

    /**
     * Every Industry Updates article page in the admin (the integrator adds these to AdminTest::adminPages()).
     *
     * @return iterable<string, array{string}>
     */
    public static function newsPages(): iterable
    {
        foreach (['/portal/admin/news/', '/portal/admin/news/?tab=held', '/portal/admin/news/new/', '/portal/admin/news/1/', '/portal/admin/news/1/preview/', '/portal/admin/news/media/' . str_repeat('a', 32) . '-480.webp'] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('newsPages')]
    public function testSignedOutVisitorsAreSentToSignIn(string $path): void
    {
        $r = $this->request('GET', $path);
        self::assertRedirect($r, '/portal/login/?next=' . rawurlencode($path), 302);
    }

    #[DataProvider('newsPages')]
    public function testClientsAreForbidden(string $path): void
    {
        $this->makeArticle();
        $this->signInClient();
        self::assertSame(403, $this->request('GET', $path)->status);
    }

    public function testClientsAndFormsWithoutTheirTokenCannotChangeArticles(): void
    {
        $id = $this->makeArticle();
        $this->signInClient();
        self::assertSame(403, $this->post('/portal/admin/news/', self::form(['action' => 'save']))->status);
        self::assertSame(403, $this->post('/portal/admin/news/' . $id . '/', ['action' => 'unpublish'])->status);
        self::assertSame(403, $this->post('/portal/admin/news/' . $id . '/images/', ['action' => 'delete', 'image' => '1'])->status);
        $_SESSION = [];
        $this->jar = [];
        $this->signInAdmin();
        self::assertSame(419, $this->post('/portal/admin/news/', self::form(['action' => 'save']), false)->status);
        self::assertSame(419, $this->post('/portal/admin/news/' . $id . '/', ['action' => 'delete', 'confirm' => '1'], false)->status);
        self::assertSame('published', $this->article($id)['status']);
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM news_articles'));
    }

    public function testEveryPageRendersWithOneTitleAndNothingInline(): void
    {
        $ids = $this->everyState();
        $this->signInAdmin();
        $this->addImage($ids['live']);
        $pages = ['/portal/admin/news/', '/portal/admin/news/new/', '/portal/admin/news/?category=video&origin=manual&q=sample'];
        foreach (array_keys(AdminNewsController::TABS) as $tab) {
            $pages[] = '/portal/admin/news/?tab=' . $tab;
        }
        foreach (['live', 'scheduled', 'draft', 'held'] as $state) {
            $pages[] = '/portal/admin/news/' . $ids[$state] . '/';
        }
        foreach ($pages as $path) {
            $r = $this->request('GET', $path);
            self::assertSame(200, $r->status, $path);
            self::assertSame(1, substr_count($r->body, '<h1 class="pt-title"'), $path . ' has one page title');
            self::assertStringNotContainsString('style="', $this->mainOf($r), $path . ' has no inline styles');
            self::assertStringNotContainsString('<script', $this->mainOf($r), $path . ' has no inline script');
            self::assertMatchesRegularExpression('#<head>.*<link rel="stylesheet" href="/assets/css/pages/admin-news\.css\?v=[^"]+">.*</head>#s', $r->body, $path . ' pushes its styles into the head');
            self::assertSame('private, no-store', $r->getHeader('Cache-Control'), $path);
            self::assertStringNotContainsString(self::EM, $this->mainOf($r), $path . ' has no em dash');
        }
        foreach (['/portal/admin/news/new/', '/portal/admin/news/' . $ids['live'] . '/'] as $path) {
            self::assertMatchesRegularExpression('#<head>.*<script type="module" src="/assets/js/admin-news\.js\?v=[^"]+"></script>.*</head>#s', $this->request('GET', $path)->body);
        }
        $nav = $this->request('GET', '/portal/admin/news/' . $ids['live'] . '/')->body;
        self::assertStringContainsString('<a href="/portal/admin/news/" aria-current="true">Industry Updates</a>', $nav);
        self::assertStringContainsString('<a href="/portal/admin/news/" aria-current="page">Industry Updates</a>', $this->request('GET', '/portal/admin/news/')->body);
    }

    /** The list and the click table stack on a phone, each cell labelled with its column's heading. */
    public function testWideTablesStackWithEveryCellLabelledByItsColumn(): void
    {
        $ids = $this->everyState();
        $this->signInAdmin();
        $tables = 0;
        foreach (['/portal/admin/news/', '/portal/admin/news/' . $ids['live'] . '/'] as $path) {
            $r = $this->request('GET', $path);
            $doc = new \DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $doc->loadHTML('<?xml encoding="utf-8">' . $this->mainOf($r) . '</main>');
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $xpath = new \DOMXPath($doc);
            foreach ($xpath->query('//table') ?: [] as $table) {
                $headings = [];
                foreach ($xpath->query('./thead/tr/th', $table) ?: [] as $th) {
                    $headings[] = trim((string) preg_replace('/\s+/u', ' ', $th->textContent));
                }
                self::assertGreaterThanOrEqual(4, count($headings), $path);
                self::assertContains('pt-table--stack', explode(' ', (string) $table->getAttribute('class')), $path);
                $tables++;
                foreach ($xpath->query('./tbody/tr', $table) ?: [] as $tr) {
                    $i = 0;
                    foreach ($tr->childNodes as $cell) {
                        if (!$cell instanceof \DOMElement || !in_array($cell->nodeName, ['td', 'th'], true)) {
                            continue;
                        }
                        if ($cell->nodeName === 'td') {
                            self::assertSame($headings[$i] ?? null, $cell->getAttribute('data-label'), $path . ': column ' . ($i + 1));
                        }
                        $i++;
                    }
                }
            }
        }
        self::assertSame(2, $tables, 'the article list and the link clicks');
    }

    // -------------------------------------------------------------------- the list

    public function testTheListCountsEachTabAndFilters(): void
    {
        $ids = $this->everyState();
        $this->makeArticle(['slug' => 'audio-story', 'title' => 'A desk for the radio mic season ahead', 'categories' => ['audio'], 'origin' => 'import']);
        $this->signInAdmin();
        $b = $this->request('GET', '/portal/admin/news/')->body;
        foreach (['all' => 5, 'live' => 2, 'scheduled' => 1, 'drafts' => 2, 'held' => 1] as $tab => $n) {
            self::assertMatchesRegularExpression('#>' . AdminNewsController::TABS[$tab] . ' <span class="pt-muted">\(' . $n . '\)</span></a>#', $b, $tab);
        }
        self::assertMatchesRegularExpression('#<td data-label="Status"><span class="pt-badge pt-badge--pending">Held for review</span></td>#', $b);
        self::assertStringContainsString('<span class="pt-small">Held because: It names a private individual.</span>', $b);
        self::assertStringContainsString('<a class="pt-btn pt-btn--primary" href="/portal/admin/news/new/">Write an article</a>', $b);

        $held = $this->request('GET', '/portal/admin/news/?tab=held')->body;
        self::assertStringContainsString('href="/portal/admin/news/' . $ids['held'] . '/"', $held);
        self::assertStringNotContainsString('href="/portal/admin/news/' . $ids['draft'] . '/"', $held);
        $scheduled = $this->request('GET', '/portal/admin/news/?tab=scheduled')->body;
        self::assertStringContainsString('<span class="pt-badge pt-badge--planned">Scheduled</span>', $scheduled);
        self::assertStringContainsString('Goes live <time', $scheduled);

        $audio = $this->request('GET', '/portal/admin/news/?category=audio')->body;
        self::assertStringContainsString('A desk for the radio mic season ahead', $audio);
        self::assertStringNotContainsString('a-live-one', $audio);
        self::assertMatchesRegularExpression('#>All <span class="pt-muted">\(1\)</span></a>#', $audio, 'tab counts follow the filters');
        $imported = $this->request('GET', '/portal/admin/news/?origin=import&tab=live')->body;
        self::assertStringContainsString('audio-story', $imported);
        self::assertStringContainsString('<td data-label="Origin">Imported</td>', $imported);
        $search = $this->request('GET', '/portal/admin/news/?q=radio+mic')->body;
        self::assertStringContainsString('audio-story', $search);
        self::assertStringNotContainsString('a-held-one', $search);
        self::assertStringContainsString('No articles match this search.', $this->request('GET', '/portal/admin/news/?q=nothing+like+this')->body);
    }

    // --------------------------------------------------------------- the editor

    public function testADraftNeedsOnlyAHeadline(): void
    {
        $admin = $this->signInAdmin();
        $r = $this->post('/portal/admin/news/', ['action' => 'save', 'title' => 'A headline is all a draft needs', 'categories' => [], 'sources' => [['url' => '', 'title' => '', 'publisher' => '', 'kind' => 'other']]]);
        $id = $this->newest();
        self::assertRedirect($r, '/portal/admin/news/' . $id . '/');
        $row = $this->article($id);
        self::assertSame(['draft', null, 'manual', $admin, 'a-headline-is-all-a-draft-needs'], [$row['status'], $row['published_at'], $row['origin'], (int) $row['author_id'], $row['slug']]);
        self::assertSame('success', self::lastFlash()['type']);
        self::assertSame('Saved as a draft. Only administrators can see it until you publish it.', self::lastFlash()['message']);
        $audit = $this->auditRows('news.created');
        self::assertCount(1, $audit);
        self::assertSame((string) $id, $audit[0]['subject']);
        self::assertSame($admin, (int) $audit[0]['user_id']);
        self::assertSame('draft', json_decode((string) $audit[0]['meta'], true)['status']);
        self::assertSame(404, $this->request('GET', '/news/' . $row['slug'] . '/')->status, 'a draft is not public');
        self::assertSame(200, $this->request('GET', '/portal/admin/news/' . $id . '/')->status);
    }

    public function testARefusedSaveKeepsEverythingTypedAndNamesEachField(): void
    {
        $this->signInAdmin();
        $body = str_repeat('A long body that keepOld would cut short. ', 200);
        self::assertGreaterThan(5000, mb_strlen($body));
        $post = self::form(['action' => 'save', 'title' => 'Short', 'body' => $body, 'categories' => ['legal', 'freelance']]);
        $r = $this->post('/portal/admin/news/', $post);
        self::assertRedirect($r, '/portal/admin/news/new/#error-summary');
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM news_articles'), 'nothing is stored');
        self::assertSame(['title'], array_keys($_SESSION['_errors'] ?? []));
        self::assertSame(['type' => 'danger', 'message' => AdminNewsController::CHECK_FIELDS], self::lastFlash());
        $kept = $_SESSION['news_draft.new'] ?? null;
        self::assertIsArray($kept);
        self::assertSame($body, $kept['body'], 'the whole body is kept');
        self::assertSame(['legal', 'freelance'], $kept['categories']);
        self::assertSame([['url' => 'https://maker.example/news/firmware', 'title' => 'Firmware 9 notes', 'publisher' => 'Maker', 'kind' => 'primary']], $kept['sources'], 'the empty row is dropped, as the draft read it');

        $page = $this->request('GET', '/portal/admin/news/new/');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('<a href="#f-title">' . Validator::TITLE . '</a>', $page->body);
        self::assertMatchesRegularExpression('#id="f-title" name="title"[^>]*aria-invalid="true"#', $page->body);
        self::assertStringContainsString('>' . e($body) . '</textarea>', $page->body);
        self::assertMatchesRegularExpression('#id="f-category-legal" name="categories\[\]" value="legal" checked#', $page->body);
        self::assertLessThan(strpos($page->body, 'id="f-category-freelance"'), strpos($page->body, 'id="f-category-legal"'), 'the main category stays first');
        self::assertStringContainsString('id="sources-0-url" name="sources[0][url]" value="https://maker.example/news/firmware"', $page->body);
        self::assertStringContainsString('id="sources-6-url"', $page->body, 'six empty rows follow the filled one');
        self::assertStringNotContainsString('id="sources-7-url"', $page->body);
        self::assertArrayNotHasKey('news_draft.new', $_SESSION, 'the copy is shown once');
        self::assertStringNotContainsString('aria-invalid', $this->request('GET', '/portal/admin/news/new/')->body, 'and the errors with it');
    }

    public function testPublishingTakesEveryRuleAndEachErrorNamesItsRow(): void
    {
        $this->signInAdmin();
        $r = $this->post('/portal/admin/news/', [
            'action' => 'publish',
            'title' => 'A headline long enough to publish',
            'confirmed' => [
                ['text' => 'First fact.', 'url' => ''],
                ['text' => '', 'url' => ''],
                ['text' => 'Second fact.', 'url' => 'https://elsewhere.example/page'],
            ],
            'sources' => [['url' => 'not a web address', 'title' => 'Bad', 'publisher' => '', 'kind' => 'press']],
        ]);
        self::assertRedirect($r, '/portal/admin/news/new/#error-summary');
        $errors = $_SESSION['_errors'] ?? [];
        foreach (['dek', 'body', 'categories', 'confirmed.0.url', 'confirmed.1.url', 'sources.0.url'] as $key) {
            self::assertArrayHasKey($key, $errors, $key);
        }
        self::assertSame(Validator::ITEM_SOURCE, $errors['confirmed.1.url'], 'the blank row between is not counted');
        $page = $this->request('GET', '/portal/admin/news/new/')->body;
        foreach (['f-dek', 'f-body', 'f-category-video', 'confirmed-0-url', 'confirmed-1-url', 'sources-0-url'] as $target) {
            self::assertStringContainsString('<a href="#' . $target . '">', $page, $target);
        }
        self::assertMatchesRegularExpression('#id="confirmed-1-url" name="confirmed\[1\]\[url\]" value="https://elsewhere\.example/page"[^>]*aria-invalid="true" aria-describedby="confirmed-1-url-error"#', $page);
        self::assertStringContainsString('<p class="pt-error" id="confirmed-1-url-error">' . e(Validator::ITEM_SOURCE) . '</p>', $page);
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM news_articles'));
    }

    public function testPublishNowPutsItLiveWithItsSourcesAndFacts(): void
    {
        $admin = $this->signInAdmin();
        $before = time();
        $r = $this->post('/portal/admin/news/', self::form(['action' => 'publish', 'title' => "Processors lock without genlock {$this->em()} at last"]));
        $id = $this->newest();
        self::assertRedirect($r, '/portal/admin/news/' . $id . '/');
        $row = $this->article($id);
        self::assertSame('published', $row['status']);
        self::assertGreaterThanOrEqual($before, (int) $row['published_at']);
        self::assertSame('Processors lock without genlock, at last', $row['title'], 'the em dash is replaced');
        self::assertSame('Ben Rohleder', $row['byline']);
        self::assertSame(1, (int) $row['comments_open']);
        self::assertSame(['video'], (new ArticleStore($this->app))->categories($id));
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM news_links WHERE article_id = ? AND is_source = 1', [$id]));
        self::assertCount(1, $this->auditRows('news.created'));
        self::assertCount(1, $this->auditRows('news.published'));
        $flashes = self::flashes();
        self::assertSame(['type' => 'success', 'message' => 'Published. The article is live at /news/' . $row['slug'] . '/.'], $flashes[count($flashes) - 2]);
        self::assertSame(['type' => 'warning', 'message' => Validator::DASHES], end($flashes));
        self::assertSame(200, $this->request('GET', '/news/' . $row['slug'] . '/')->status);
        self::assertSame($admin, (int) $row['author_id']);
    }

    private function em(): string
    {
        return self::EM;
    }

    public function testSchedulingTakesADateAndTimeInLondon(): void
    {
        $this->signInAdmin();
        $london = new \DateTimeZone('Europe/London');
        $when = (new \DateTimeImmutable('now', $london))->modify('+2 days')->setTime(6, 30);
        $r = $this->post('/portal/admin/news/', self::form(['action' => 'schedule', 'publish_date' => $when->format('Y-m-d'), 'publish_time' => '06:30']));
        $id = $this->newest();
        self::assertRedirect($r, '/portal/admin/news/' . $id . '/');
        $row = $this->article($id);
        self::assertSame(['published', $when->getTimestamp()], [$row['status'], (int) $row['published_at']]);
        self::assertSame('Scheduled. The article goes live on ' . $when->format('l j F Y \a\t H:i') . ' (London time).', self::flashes()[0]['message']);
        self::assertSame(['at' => date(DATE_ATOM, $when->getTimestamp())], json_decode((string) $this->auditRows('news.scheduled')[0]['meta'], true));
        self::assertSame(404, $this->request('GET', '/news/' . $row['slug'] . '/')->status, 'not live before its time');
        $page = $this->request('GET', '/portal/admin/news/' . $id . '/')->body;
        self::assertStringContainsString('value="' . $when->format('Y-m-d') . '"', $page);
        self::assertStringContainsString('value="06:30"', $page);
        self::assertStringContainsString('<button type="submit" name="action" value="schedule" class="pt-btn pt-btn--secondary" data-busy="Scheduling…">Change the time</button>', $page);

        // A summer date reads as British Summer Time, a winter one as GMT.
        $summer = new \DateTimeImmutable((date('Y') + 1) . '-07-01 12:00', $london);
        $winter = new \DateTimeImmutable((date('Y') + 1) . '-01-15 12:00', $london);
        foreach ([$summer, $winter] as $date) {
            if ($date->getTimestamp() > time() + 365 * 86400 || $date->getTimestamp() < time()) {
                continue;
            }
            $this->post('/portal/admin/news/' . $id . '/', self::form(['action' => 'schedule', 'publish_date' => $date->format('Y-m-d'), 'publish_time' => '12:00']));
            self::assertSame($date->getTimestamp(), (int) $this->article($id)['published_at'], $date->format('Y-m-d'));
        }

        $refused = [
            ['', '', 'Enter the date and time to publish'],
            ['2027-02-30', '09:00', 'That date and time does not exist in London. Choose another.'],
            [date('Y-m-d', time() - 86400), '09:00', 'Choose a time in the future, or use Publish now.'],
            [date('Y-m-d', time() + 400 * 86400), '09:00', 'Choose a time within the next year.'],
        ];
        foreach ($refused as [$date, $time, $message]) {
            $_SESSION['_errors'] = [];
            $r = $this->post('/portal/admin/news/', self::form(['action' => 'schedule', 'publish_date' => $date, 'publish_time' => $time]));
            self::assertRedirect($r, '/portal/admin/news/new/#error-summary');
            self::assertStringStartsWith($message, (string) ($_SESSION['_errors']['publish_at'] ?? ''), $date);
        }
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM news_articles'));
        self::assertStringContainsString('<a href="#f-publish-date">', $this->request('GET', '/portal/admin/news/new/')->body);
    }

    public function testSavingALiveArticleTakesEveryRuleAndKeepsItLive(): void
    {
        $id = $this->makeArticle(['slug' => 'live-and-kicking']);
        $this->signInAdmin();
        $path = '/portal/admin/news/' . $id . '/';
        $edit = $this->request('GET', $path)->body;
        self::assertStringContainsString('value="unpublish"', $edit);
        self::assertStringNotContainsString('value="publish"', $edit, 'a live article has no publish button');
        self::assertStringNotContainsString('name="publish_date"', $edit, 'nor a schedule');
        self::assertStringContainsString('Unpublishing takes it off the site. ' . e(AdminNewsCommentController::TAKEDOWN), $edit, 'the CDN\'s time as the flash says it');

        $r = $this->post($path, self::form(['action' => 'save', 'dek' => 'Too short']));
        self::assertRedirect($r, $path . '#error-summary');
        self::assertSame(['dek'], array_keys($_SESSION['_errors']));
        self::assertNotSame('Too short', $this->article($id)['dek']);

        $this->post($path, self::form(['action' => 'save']));
        $row = $this->article($id);
        self::assertSame(['published', 'live-and-kicking'], [$row['status'], $row['slug']], 'a live article keeps its address');
        self::assertSame('LED processors gain a frame sync mode for mixed walls', $row['title']);
        self::assertSame('Changes saved. It can take a few minutes for every reader to see them.', self::lastFlash()['message']);
        $fields = json_decode((string) $this->auditRows('news.updated')[0]['meta'], true)['fields'];
        self::assertContains('title', $fields);
        self::assertContains('sources', $fields);

        $this->post($path, self::form(['action' => 'save']));
        self::assertSame(['type' => 'info', 'message' => 'Nothing changed, so nothing was saved.'], self::lastFlash());
        self::assertCount(1, $this->auditRows('news.updated'), 'nothing changed, nothing audited');

        $this->post($path, self::form(['action' => 'schedule', 'publish_date' => date('Y-m-d', time() + 86400), 'publish_time' => '09:00']));
        self::assertSame('danger', self::lastFlash()['type']);
        self::assertSame('published', $this->article($id)['status']);
        self::assertLessThanOrEqual(time(), (int) $this->article($id)['published_at'], 'a live article is not moved to the future');
    }

    public function testUnpublishingTakesItDownAndSaysHowLongTheCdnTakes(): void
    {
        $id = $this->makeArticle(['slug' => 'coming-down']);
        $this->signInAdmin();
        $first = (int) $this->article($id)['published_at'];
        $r = $this->post('/portal/admin/news/' . $id . '/', self::form(['action' => 'unpublish', 'title' => 'Sample story number 1 from the test suite']));
        self::assertRedirect($r, '/portal/admin/news/' . $id . '/');
        $row = $this->article($id);
        self::assertSame(['draft', $first], [$row['status'], (int) $row['published_at']], 'it keeps its first time');
        self::assertStringEndsWith(AdminNewsCommentController::TAKEDOWN, self::lastFlash()['message']);
        self::assertSame(['was' => 'live'], json_decode((string) $this->auditRows('news.unpublished')[0]['meta'], true));
        self::assertSame(404, $this->request('GET', '/news/coming-down/')->status);
        self::assertSame('coming-down', $row['slug'], 'an address once public never changes');

        $this->post('/portal/admin/news/' . $id . '/', self::form(['action' => 'publish', 'title' => 'Sample story number 1 from the test suite']));
        $row = $this->article($id);
        self::assertSame(['published', $first], [$row['status'], (int) $row['published_at']], 'published again at its first time');
        self::assertCount(1, $this->auditRows('news.published'));
    }

    /**
     * A takedown is never refused: a live article whose source was banned after it went live is unpublished
     * although the form it sends back fails that rule now, and what was typed is kept to fix.
     */
    public function testUnpublishingIsNeverRefusedByARuleTheStoredArticleNowFails(): void
    {
        $id = $this->makeArticle(['slug' => 'banned-since']);
        $this->db->insert('news_settings', ['name' => 'banned_domains', 'value' => json_encode(['example.com']), 'updated_by' => null, 'updated_at' => time()]);
        $this->signInAdmin();
        $path = '/portal/admin/news/' . $id . '/';
        $page = $this->request('GET', $path)->body;
        self::assertSame(1, preg_match('#name="loaded_at" value="(\d+)"#', $page, $v));
        $stored = 'https://example.com/news/story-1';
        self::assertStringContainsString('name="sources[0][url]" value="' . $stored . '"', $page, 'the form sends the stored source back');

        $r = $this->post($path, self::form([
            'action' => 'unpublish', 'loaded_at' => $v[1], 'title' => 'Sample story number 1 from the test suite',
            'sources' => [['url' => $stored, 'title' => 'Sample source 1', 'publisher' => 'Example News', 'kind' => 'press']],
            'confirmed' => [['text' => 'The sample story was published.', 'url' => $stored]],
        ]));
        self::assertRedirect($r, $path . '#error-summary');
        self::assertSame('draft', $this->article($id)['status'], 'it is down');
        self::assertCount(1, $this->auditRows('news.unpublished'));
        self::assertSame(['was' => 'live'], json_decode((string) $this->auditRows('news.unpublished')[0]['meta'], true));
        self::assertSame([], $this->auditRows('news.updated'), 'the form was not saved');
        self::assertArrayHasKey('sources.0.url', $_SESSION['_errors'] ?? []);
        self::assertContains(['type' => 'success', 'message' => 'Unpublished. The article is a draft again, but your other changes were not saved. ' . AdminNewsCommentController::TAKEDOWN], self::flashes());
        self::assertSame(404, $this->request('GET', '/news/banned-since/')->status);
        self::assertSame(404, $this->request('GET', '/news/go/' . (int) $this->db->value('SELECT id FROM news_links WHERE article_id = ? LIMIT 1', [$id]) . '/')->status);

        // The kept form is the article as it is after its own takedown, so it can be fixed and saved.
        $kept = $this->request('GET', $path)->body;
        self::assertStringContainsString('value="' . $stored . '"', $kept, 'what was sent is kept');
        self::assertSame(1, preg_match('#name="loaded_at" value="(\d+)"#', $kept, $now));
        self::assertSame((int) $this->article($id)['updated_at'], (int) $now[1]);
        $this->post($path, self::form(['action' => 'save', 'loaded_at' => $now[1], 'title' => 'Sample story number 1 from the test suite']));
        self::assertSame(['type' => 'success', 'message' => 'Changes saved.'], self::lastFlash());
        self::assertSame('draft', $this->article($id)['status']);
    }

    /** A form opened before someone else's save, publish or takedown is refused whole, and what was typed is kept. */
    public function testAFormOpenedBeforeAnotherChangeIsRefusedAndKept(): void
    {
        $id = $this->makeArticle(['slug' => 'two-tabs']);
        $this->signInAdmin();
        $path = '/portal/admin/news/' . $id . '/';
        self::assertSame(1, preg_match('#<input type="hidden" name="loaded_at" value="(\d+)">#', $this->request('GET', $path)->body, $opened));
        self::assertSame((int) $this->article($id)['updated_at'], (int) $opened[1], 'the form carries the version it was opened on');

        // Another tab saves first: its correction stands.
        $this->post($path, self::form(['action' => 'save', 'loaded_at' => $opened[1], 'title' => 'The corrected headline from the other tab']));
        self::assertSame('Changes saved. It can take a few minutes for every reader to see them.', self::lastFlash()['message']);
        $after = $this->article($id);
        self::assertGreaterThan((int) $opened[1], (int) $after['updated_at']);

        foreach (['save' => 'published', 'unpublish' => 'draft'] as $action => $status) {
            $_SESSION['_flash'] = [];
            $r = $this->post($path, self::form(['action' => $action, 'loaded_at' => $opened[1], 'title' => 'The stale headline from the first tab']));
            self::assertRedirect($r, $path . '#error-summary');
            $row = $this->article($id);
            self::assertSame(['The corrected headline from the other tab', $status], [$row['title'], $row['status']], $action . ': nothing of the stale form is written');
            self::assertSame(['article' => AdminNewsController::CHANGED_SINCE], $_SESSION['_errors'] ?? null, $action);
            self::assertContains(['type' => 'danger', 'message' => AdminNewsController::CHANGED_SINCE], self::flashes(), $action);
            if ($action === 'unpublish') {
                self::assertContains(['type' => 'success', 'message' => 'Unpublished. The article is a draft again, but your other changes were not saved. ' . AdminNewsCommentController::TAKEDOWN], self::flashes(), 'a stale form still takes the article down');
            }
            $kept = $_SESSION['news_draft.' . $id] ?? [];
            self::assertSame(['The stale headline from the first tab', $opened[1]], [$kept['title'] ?? null, $kept['loaded_at'] ?? null], $action . ': the typed copy keeps the version it was opened on');
            $page = $this->request('GET', $path)->body;
            self::assertStringContainsString('value="The stale headline from the first tab"', $page);
            self::assertStringContainsString('<p>' . e(AdminNewsController::CHANGED_SINCE) . '</p>', $page, 'the summary says why');
            self::assertStringContainsString('name="loaded_at" value="' . $opened[1] . '"', $page, 'showing it again does not make it current');
        }
        self::assertCount(1, $this->auditRows('news.updated'), 'only the other tab\'s save');
        self::assertCount(1, $this->auditRows('news.unpublished'));

        // Publishing from a stale form publishes nothing; a form opened now publishes.
        $stale = (string) $this->article($id)['updated_at'];
        $this->db->update('news_articles', ['updated_at' => (int) $stale + 5], ['id' => $id]);
        $this->post($path, self::form(['action' => 'publish', 'loaded_at' => $stale, 'title' => 'The corrected headline from the other tab']));
        self::assertSame('draft', $this->article($id)['status']);
        self::assertSame([], $this->auditRows('news.published'));
        $this->post($path, self::form(['action' => 'publish', 'loaded_at' => (string) ((int) $stale + 5), 'title' => 'The corrected headline from the other tab']));
        self::assertSame('published', $this->article($id)['status']);
        self::assertCount(1, $this->auditRows('news.published'));
    }

    /** Unpublishing from a current form saves what was typed too: the takedown is the form's own change. */
    public function testUnpublishingFromACurrentFormSavesItsChanges(): void
    {
        $id = $this->makeArticle(['slug' => 'down-with-a-fix']);
        $this->signInAdmin();
        $path = '/portal/admin/news/' . $id . '/';
        self::assertSame(1, preg_match('#name="loaded_at" value="(\d+)"#', $this->request('GET', $path)->body, $opened));
        $r = $this->post($path, self::form(['action' => 'unpublish', 'loaded_at' => $opened[1], 'title' => 'A corrected headline, taken down']));
        self::assertRedirect($r, $path);
        $row = $this->article($id);
        self::assertSame(['draft', 'A corrected headline, taken down', 'down-with-a-fix'], [$row['status'], $row['title'], $row['slug']]);
        self::assertSame(['type' => 'success', 'message' => 'Changes saved. Unpublished. The article is a draft again. ' . AdminNewsCommentController::TAKEDOWN], self::lastFlash());
        self::assertCount(1, $this->auditRows('news.updated'));
        self::assertCount(1, $this->auditRows('news.unpublished'));
    }

    public function testADraftsAddressFollowsItsHeadlineUntilItIsFirstPublished(): void
    {
        $this->signInAdmin();
        $this->post('/portal/admin/news/', ['action' => 'save', 'title' => 'A working title for now']);
        $id = $this->newest();
        self::assertSame('a-working-title-for-now', $this->article($id)['slug']);
        $this->post('/portal/admin/news/' . $id . '/', self::form(['action' => 'save', 'title' => 'The real headline at last']));
        self::assertSame('the-real-headline-at-last', $this->article($id)['slug']);
        $this->post('/portal/admin/news/' . $id . '/', self::form(['action' => 'publish', 'title' => 'The real headline at last']));
        $this->post('/portal/admin/news/' . $id . '/', self::form(['action' => 'save', 'title' => 'A changed headline after publishing']));
        self::assertSame('the-real-headline-at-last', $this->article($id)['slug']);
    }

    public function testADraftWhoseHeadlineIsTakenKeepsItsNumberedAddress(): void
    {
        $this->signInAdmin();
        $this->post('/portal/admin/news/', ['action' => 'save', 'title' => 'The same working headline']);
        $this->post('/portal/admin/news/', ['action' => 'save', 'title' => 'The same working headline']);
        $second = $this->newest();
        self::assertSame('the-same-working-headline-2', $this->article($second)['slug']);
        $this->post('/portal/admin/news/' . $second . '/', ['action' => 'save', 'title' => 'The same working headline']);
        self::assertSame(['type' => 'info', 'message' => 'Nothing changed, so nothing was saved.'], self::lastFlash());
        self::assertSame('the-same-working-headline-2', $this->article($second)['slug']);
        self::assertSame([], $this->auditRows('news.updated'));
    }

    public function testEveryRowUpToTheLimitIsKeptAndOneMoreIsRefused(): void
    {
        $this->signInAdmin();
        $sources = [];
        $facts = [];
        for ($i = 1; $i <= 13; $i++) {
            $sources[] = ['url' => 'https://maker.example/news/' . $i, 'title' => 'Note ' . $i, 'publisher' => 'Maker', 'kind' => 'press'];
            $facts[] = ['text' => 'Fact number ' . $i . '.', 'url' => 'https://maker.example/news/' . $i];
        }
        $this->post('/portal/admin/news/', self::form(['action' => 'publish', 'sources' => array_slice($sources, 0, 12), 'confirmed' => array_slice($facts, 0, 12)]));
        $id = $this->newest();
        self::assertCount(12, (new ArticleStore($this->app))->find($id)['confirmed']);
        self::assertSame(12, (int) $this->db->value('SELECT COUNT(*) FROM news_links WHERE article_id = ? AND is_source = 1', [$id]));
        $page = $this->request('GET', '/portal/admin/news/' . $id . '/')->body;
        self::assertStringContainsString('id="confirmed-11-text"', $page);
        self::assertStringNotContainsString('id="confirmed-12-text"', $page, 'no empty row past the limit');
        self::assertStringContainsString('data-news-rows data-news-max="12"', $page);

        $r = $this->post('/portal/admin/news/' . $id . '/', self::form(['action' => 'save', 'sources' => array_slice($sources, 0, 12), 'confirmed' => $facts]));
        self::assertRedirect($r, '/portal/admin/news/' . $id . '/#error-summary');
        self::assertSame("Keep What's confirmed to 12 items.", $_SESSION['_errors']['confirmed'] ?? null);
        $page = $this->request('GET', '/portal/admin/news/' . $id . '/')->body;
        self::assertStringContainsString('<a href="#confirmed-0-text">Keep What&#039;s confirmed to 12 items.</a>', $page);
        self::assertStringContainsString('id="confirmed-12-text"', $page, 'the thirteenth row is shown again to be removed');
        self::assertCount(12, (new ArticleStore($this->app))->find($id)['confirmed']);
    }

    public function testTheTicksFollowUpsAndTheDesksOwnFieldsAreKept(): void
    {
        $earlier = $this->makeArticle(['slug' => 'the-earlier-one']);
        $ai = $this->makeArticle(['slug' => 'by-the-desk', 'origin' => 'ai', 'status' => 'draft', 'story_key' => 'desk-story-key', 'review_reason' => 'It alleges wrongdoing.']);
        $this->signInAdmin();
        $post = self::form(['action' => 'save', 'follow_up_of' => [(string) $earlier], 'comments_open' => '', 'categories' => ['legal', 'freelance'], 'byline' => '']);
        $this->post('/portal/admin/news/' . $ai . '/', $post);
        $row = $this->article($ai);
        self::assertSame(['desk-story-key', 1, 'It alleges wrongdoing.', 'ai', 0], [$row['story_key'], (int) $row['ai_written'], $row['review_reason'], $row['origin'], (int) $row['comments_open']]);
        self::assertSame([$earlier], array_map('intval', array_column($this->db->all('SELECT related_id FROM news_related WHERE article_id = ?', [$ai]), 'related_id')));
        self::assertSame(['legal', 'freelance'], (new ArticleStore($this->app))->categories($ai), 'the first ticked is the main one');
        $page = $this->request('GET', '/portal/admin/news/' . $ai . '/')->body;
        self::assertStringContainsString('<strong>Held for review.</strong> It alleges wrongdoing.', $page);
        self::assertMatchesRegularExpression('#<option value="' . $earlier . '" selected>#', $page);
        self::assertMatchesRegularExpression('#<input type="checkbox" id="f-ai-written" name="ai_written" disabled[^>]* value="1" checked>#', $page);
        self::assertLessThan(strpos($page, 'id="f-category-freelance"'), strpos($page, 'id="f-category-legal"'));

        $mine = $this->makeArticle(['slug' => 'mine']);
        $this->post('/portal/admin/news/' . $mine . '/', self::form(['action' => 'save', 'ai_written' => '1']));
        self::assertSame(1, (int) $this->article($mine)['ai_written']);
        self::assertStringNotContainsString('<option value="' . $mine . '"', $this->request('GET', '/portal/admin/news/' . $mine . '/')->body, 'an article never follows itself up');
    }

    public function testABannedTopicIsAWarningOnAManualArticle(): void
    {
        $this->db->insert('news_settings', ['name' => 'banned_topics', 'value' => json_encode(['crypto']), 'updated_by' => null, 'updated_at' => time()]);
        $this->signInAdmin();
        $this->post('/portal/admin/news/', self::form(['action' => 'publish', 'body' => str_repeat('A crypto token for shows, they say. ', 5)]));
        self::assertSame('published', $this->article($this->newest())['status']);
        self::assertContains(['type' => 'warning', 'message' => 'This article mentions a banned topic: crypto.'], self::flashes());
    }

    public function testDeletingNeedsTheConfirmBoxAndTakesEverything(): void
    {
        $id = $this->makeArticle(['slug' => 'going-for-good']);
        $this->signInAdmin();
        $image = $this->addImage($id);
        $path = '/portal/admin/news/' . $id . '/';

        $r = $this->post($path, ['action' => 'delete']);
        self::assertRedirect($r, $path . '#delete-summary');
        self::assertSame(['confirm'], array_keys($_SESSION['_errors']));
        self::assertSame('published', $this->article($id)['status']);
        $page = $this->request('GET', $path)->body;
        self::assertStringContainsString('<a href="#f-delete-confirm">Tick the box to confirm you want to delete this article.</a>', $page);
        self::assertSame(1, substr_count($page, 'data-error-summary'), 'only the delete form says so');

        $r = $this->post($path, ['action' => 'delete', 'confirm' => '1']);
        self::assertRedirect($r, '/portal/admin/news/');
        self::assertNull($this->db->one('SELECT id FROM news_articles WHERE id = ?', [$id]));
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM news_images'));
        foreach (ArticleStore::imageFiles($image) as $name) {
            self::assertFileDoesNotExist($this->storage . '/' . ArticleStore::IMAGE_DIR . '/' . $name);
        }
        self::assertSame('“Sample story number 1 from the test suite” is deleted. ' . AdminNewsCommentController::TAKEDOWN, self::lastFlash()['message']);
        $meta = json_decode((string) $this->auditRows('news.deleted')[0]['meta'], true);
        self::assertSame(['going-for-good', 'live', 'manual', 1], [$meta['slug'], $meta['state'], $meta['origin'], $meta['images']]);
        self::assertSame(404, $this->request('GET', $path)->status);
        self::assertSame(404, $this->request('GET', '/news/going-for-good/')->status);
    }

    public function testRefusedActionsAndUnknownArticles(): void
    {
        $id = $this->makeArticle();
        $this->signInAdmin();
        $r = $this->post('/portal/admin/news/' . $id . '/', ['action' => 'launch']);
        self::assertRedirect($r, '/portal/admin/news/' . $id . '/');
        self::assertSame(['type' => 'danger', 'message' => AdminNewsController::UNKNOWN_ACTION], self::lastFlash());
        self::assertArrayNotHasKey('_errors', $_SESSION, 'no field error is left for another form');
        self::assertSame(303, $this->post('/portal/admin/news/', ['action' => 'delete'])->status);
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM audit_log WHERE action LIKE ?', ['news.%']));
        foreach (['/portal/admin/news/999/', '/portal/admin/news/999/preview/'] as $path) {
            self::assertSame(404, $this->request('GET', $path)->status, $path);
        }
        self::assertSame(404, $this->post('/portal/admin/news/999/', ['action' => 'save'])->status);
        self::assertSame(404, $this->post('/portal/admin/news/999/images/', ['action' => 'lead', 'image' => '1'])->status);
    }

    // ------------------------------------------------------------------- images

    public function testImagesAreAddedDescribedLedAndDeleted(): void
    {
        $id = $this->makeArticle(['slug' => 'with-pictures']);
        $admin = $this->signInAdmin();
        $path = '/portal/admin/news/' . $id . '/';
        $first = $this->addImage($id, 'The first picture');
        self::assertSame('Image added. It is the lead image. It can take a few minutes for every reader to see it.', self::lastFlash()['message']);
        $second = $this->addImage($id, 'The second picture');
        self::assertSame(['image' => (int) $second['id'], 'name' => 'wall.png', 'lead' => false], json_decode((string) $this->auditRows('news.image_added')[1]['meta'], true));
        self::assertSame($admin, (int) $second['created_by']);

        $page = $this->request('GET', $path)->body;
        self::assertStringContainsString('<img class="pt-news-thumb" src="/portal/admin/news/media/' . $first['base'] . '-480.webp" width="480" height="320" alt="The first picture"', $page);
        self::assertStringContainsString('Only upload images you have the right to use: your own, a press kit that allows editorial use, or licensed.', $page);
        self::assertStringContainsString('Save your changes first', $page);
        self::assertGreaterThanOrEqual(5, substr_count($page, 'data-news-image-form'), 'every image form is marked for the unsaved-changes guard');

        $r = $this->post($path . 'images/', ['action' => 'update', 'image' => (string) $second['id'], 'form' => 'image-' . $second['id'], 'alt' => 'A better description', 'caption' => 'At load-in', 'credit' => 'Maker Ltd', 'credit_url' => 'https://maker.example/press']);
        self::assertRedirect($r, $path . '#image-' . $second['id']);
        $row = $this->db->one('SELECT * FROM news_images WHERE id = ?', [$second['id']]);
        self::assertSame(['A better description', 'At load-in', 'Maker Ltd', 'https://maker.example/press'], [$row['alt'], $row['caption'], $row['credit'], $row['credit_url']]);
        self::assertSame(['image' => (int) $second['id'], 'fields' => ['alt', 'caption', 'credit', 'credit_url']], json_decode((string) $this->auditRows('news.image_updated')[0]['meta'], true));

        $this->post($path . 'images/', ['action' => 'update', 'image' => (string) $second['id'], 'form' => 'image-' . $second['id'], 'alt' => '']);
        self::assertSame(['image.' . $second['id'] . '.alt'], array_keys($_SESSION['_errors']));
        $page = $this->request('GET', $path)->body;
        self::assertStringContainsString('<a href="#image-' . $second['id'] . '-alt">' . Images::ALT_REQUIRED . '</a>', $page);
        self::assertSame(1, substr_count($page, 'data-error-summary'), 'only that image form says so');
        self::assertSame('A better description', $this->db->value('SELECT alt FROM news_images WHERE id = ?', [$second['id']]));

        $this->post($path . 'images/', ['action' => 'lead', 'image' => (string) $second['id']]);
        self::assertSame('That image now leads the article. It can take a few minutes for every reader to see it.', self::lastFlash()['message']);
        self::assertSame([(int) $second['id'], (int) $first['id']], array_map(static fn (array $i): int => (int) $i['id'], (new ArticleStore($this->app))->images($id)));
        self::assertSame(['image' => (int) $second['id'], 'fields' => ['lead']], json_decode((string) $this->auditRows('news.image_updated')[1]['meta'], true));
        self::assertStringContainsString('/news/media/' . $second['base'] . '-og.jpg', $this->request('GET', '/news/with-pictures/')->body, 'the lead is the share card');

        $this->post($path . 'images/', ['action' => 'delete', 'image' => (string) $second['id']]);
        self::assertSame('Image deleted. ' . AdminNewsCommentController::TAKEDOWN, self::lastFlash()['message']);
        self::assertNull($this->db->one('SELECT id FROM news_images WHERE id = ?', [$second['id']]));
        foreach (ArticleStore::imageFiles($second) as $name) {
            self::assertFileDoesNotExist($this->storage . '/' . ArticleStore::IMAGE_DIR . '/' . $name);
        }
        self::assertSame(['image' => (int) $second['id'], 'name' => 'wall.png', 'alt' => 'A better description'], json_decode((string) $this->auditRows('news.image_deleted')[0]['meta'], true));
        self::assertSame(404, $this->request('GET', '/news/media/' . $second['base'] . '-480.webp')->status);

        // Another article's image, or one already gone, changes nothing.
        $other = $this->makeArticle();
        $r = $this->post('/portal/admin/news/' . $other . '/images/', ['action' => 'delete', 'image' => (string) $first['id']]);
        self::assertRedirect($r, '/portal/admin/news/' . $other . '/#images');
        self::assertSame('info', self::lastFlash()['type']);
        self::assertNotNull($this->db->one('SELECT id FROM news_images WHERE id = ?', [$first['id']]));
        $this->post($path . 'images/', ['action' => 'rotate', 'image' => (string) $first['id']]);
        self::assertSame('danger', self::lastFlash()['type']);
    }

    public function testUploadsThatAreNotUsablePicturesAreRefusedOnTheUploadForm(): void
    {
        $id = $this->makeArticle();
        $this->signInAdmin();
        $path = '/portal/admin/news/' . $id . '/images/';
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $chunk = 'IHDR' . pack('NN', 12000, 12000) . substr($png, 24, 5);
        $bomb = substr($png, 0, 12) . $chunk . pack('N', crc32($chunk)) . substr($png, 33);
        $cases = [
            'no file' => [[], 'Choose an image to upload.'],
            'a script named .png' => [[$this->fakeUpload('shell.png', "<?php system(\$_GET['c']); ?>")], 'The file is not the kind of image its name says. Upload a PNG, JPEG, WebP or GIF image.'],
            'a text file' => [[$this->fakeUpload('notes.txt', 'Some notes.')], 'Upload a PNG, JPEG, WebP or GIF image.'],
            'a picture too large' => [[$this->fakeUpload('bomb.png', $bomb)], "The image is 12,000 \u{00D7} 12,000 pixels, more than the 25 megapixels allowed. Resize it and upload it again."],
            'two files' => [[$this->picture('a.png'), $this->picture('b.png')], 'Upload one image at a time.'],
        ];
        foreach ($cases as $case => [$files, $message]) {
            $_SESSION['_errors'] = [];
            $r = $this->postFiles($path, ['action' => 'add', 'form' => 'image-new', 'alt' => 'A description'], $files === [] ? [] : ['image' => $files]);
            self::assertRedirect($r, '/portal/admin/news/' . $id . '/#image-new-summary');
            self::assertSame(['image.new.file' => $message], $_SESSION['_errors'], $case);
        }
        $_SESSION['_errors'] = [];
        $this->postFiles($path, ['action' => 'add', 'form' => 'image-new', 'alt' => '', 'caption' => 'Kept'], ['image' => [$this->picture('c.png')]]);
        self::assertSame(['image.new.alt' => Images::ALT_REQUIRED], $_SESSION['_errors']);
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM news_images'));
        $page = $this->request('GET', '/portal/admin/news/' . $id . '/')->body;
        self::assertStringContainsString('<a href="#image-new-alt">' . Images::ALT_REQUIRED . '</a>', $page);
        self::assertMatchesRegularExpression('#id="image-new-caption" name="caption"[^>]*value="Kept"#', $page, 'what was typed is kept');
        self::assertSame([], $this->auditRows('news.image_added'));
        $dir = $this->storage . '/' . ArticleStore::IMAGE_DIR;
        self::assertSame([], is_dir($dir) ? array_values(array_filter(scandir($dir) ?: [], static fn (string $n): bool => is_file($dir . '/' . $n))) : []);
    }

    // ------------------------------------------------------------- the rest of the page

    public function testThePreviewShowsAnyArticleWithItsImagesAndNoAnalytics(): void
    {
        $this->bootApp(['analytics' => ['ga_id' => 'G-TEST123456']]);
        $id = $this->makeArticle(['slug' => 'not-out-yet', 'status' => 'draft']);
        $this->signInAdmin();
        $image = $this->addImage($id);
        $r = $this->request('GET', '/portal/admin/news/' . $id . '/preview/');
        self::assertSame(200, $r->status);
        self::assertSame('private, no-store', $r->getHeader('Cache-Control'));
        self::assertStringContainsString('noindex', (string) $r->getHeader('X-Robots-Tag'));
        self::assertStringContainsString('Preview: not published', $r->body);
        self::assertStringContainsString('src="/portal/admin/news/media/' . $image['base'] . '-960.webp"', $r->body);
        self::assertStringNotContainsString('googletagmanager', $r->body);
        self::assertStringNotContainsString('G-TEST123456', $r->body);
        self::assertStringNotContainsString('G-TEST123456', $this->request('GET', '/portal/admin/news/' . $id . '/')->body, 'no admin page reaches Google');
        self::assertStringContainsString('href="/portal/admin/news/' . $id . '/preview/"', $this->request('GET', '/portal/admin/news/' . $id . '/')->body);
    }

    public function testLinkClicksAreShownInAllAndForTheLastThirtyDays(): void
    {
        $ids = $this->everyState();
        $this->db->insert('news_link_clicks', ['link_id' => $ids['link'], 'day' => gmdate('Y-m-d', time() - 40 * 86400), 'clicks' => 4]);
        $this->signInAdmin();
        $page = $this->request('GET', '/portal/admin/news/' . $ids['live'] . '/')->body;
        self::assertStringContainsString('<h2 class="pt-h2" id="clicks-title">Link clicks, approximate</h2>', $page);
        self::assertStringContainsString('<caption class="u-visually-hidden">Approximate clicks on this article\'s links, the sources first</caption>', $page);
        self::assertStringContainsString('<p>These counts are approximate.', $page);
        self::assertMatchesRegularExpression('#<td data-label="Where">Source 1</td>\s*<td class="pt-num" data-label="In all">7</td>\s*<td class="pt-num" data-label="Last 30 days">3</td>#', $page);
    }

    public function testTheEditPageSaysWhereAnAiArticleCameFrom(): void
    {
        $ids = $this->everyState();
        $this->signInAdmin();
        $page = $this->request('GET', '/portal/admin/news/' . $ids['held'] . '/')->body;
        self::assertStringContainsString('<a href="/portal/admin/news/desk/runs/' . $ids['run'] . '/">run ' . $ids['run'] . '</a>', $page);
        self::assertStringContainsString('<dt>Held because</dt>', $page);
        self::assertStringContainsString('<span class="pt-badge pt-badge--pending">Held for review</span>', $page);
        self::assertStringContainsString('<button type="submit" name="action" value="publish"', $page);
    }

    public function testTheOverviewHasAnIndustryUpdatesCardOfItsOwn(): void
    {
        $ids = $this->everyState();
        $client = $this->makeUser('reader@example.test');
        $this->db->insert('news_comments', ['article_id' => $ids['live'], 'user_id' => $client, 'body' => 'Reported one', 'status' => 'visible', 'reports' => 2, 'created_at' => time(), 'updated_at' => time()]);
        $this->db->insert('news_comments', ['article_id' => $ids['live'], 'user_id' => $client, 'body' => 'Waiting one', 'status' => 'pending', 'reports' => 0, 'created_at' => time(), 'updated_at' => time()]);
        $this->signInAdmin();
        $r = $this->request('GET', '/portal/admin/');
        self::assertSame(200, $r->status);
        self::assertStringContainsString('<h2 class="pt-card-title" id="ov-news">Industry Updates</h2>', $r->body);
        self::assertStringContainsString('<a href="/portal/admin/news/?tab=held">1 article held for review</a>', $r->body);
        self::assertStringContainsString('<a href="/portal/admin/news/comments/">1 reported comment</a>', $r->body);
        self::assertStringContainsString('<a href="/portal/admin/news/comments/?tab=pending">1 comment waiting for approval</a>', $r->body);
        self::assertStringContainsString('<span class="pt-badge pt-badge--disabled">Stopped: no API key is set</span>', $r->body, 'tests run without a key');
        self::assertStringContainsString('Spent this month: about 0.00 of 100 USD (estimated)', $r->body);
        self::assertSame(['approval', 'tickets', 'licences', 'messages'], array_keys(AdminController::waitingCounts($this->db)), 'the waiting counts are unchanged');
        self::assertSame(0, substr_count($this->mainOf($r), '>Industry Updates</a>'), 'only the navigation link (and the footer\'s, to the public page) is called Industry Updates');
    }

    public function testAuditRowsLinkToTheArticleOrTheModerationList(): void
    {
        self::assertSame('/portal/admin/news/12/', AdminController::subjectUrl('news.updated', '12'));
        self::assertSame('/portal/admin/news/12/', AdminController::subjectUrl('news.image_deleted', '12'));
        self::assertSame('/portal/admin/news/comments/', AdminController::subjectUrl('comment.hidden', '40'));
        self::assertNull(AdminController::subjectUrl('news.deleted', 'x'));
    }
}
