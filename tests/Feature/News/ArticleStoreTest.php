<?php

declare(strict_types=1);

namespace Psc\Tests\Feature\News;

use Psc\News\ArticleStore;
use Psc\News\Draft;
use Psc\News\Markdown;
use Psc\News\Urls;
use Psc\Tests\TestCase;

/** The one writer of the article tables: what a save stores, what the readers get back. Rows first, words second. */
final class ArticleStoreTest extends TestCase
{
    private const EM = "\u{2014}";

    private function store(): ArticleStore
    {
        return new ArticleStore($this->app);
    }

    /** @param array<string, mixed> $over */
    private static function draft(array $over = [], string $origin = 'manual'): Draft
    {
        return Draft::fromArray($over + [
            'title' => 'Wireless mic licences change for the spring season',
            'dek' => 'The regulator has changed how shared channels are booked for events, and the deadline is earlier than last year.',
            'body' => "The change lands in March.\n\n## What it means\n\nBook early. See [the guidance](https://www.regulator.example/guidance/?utm_source=x) and [our timer guide](/workflows/conference-timing/) and [a review](https://press.example/review).",
            'impact' => 'Freelancers who book their own channels should check [the form](https://regulator.example/form).',
            'verdict' => 'note',
            'categories' => ['audio', 'legal'],
            'tags' => ['Radio Mics', 'Spectrum'],
            'sources' => [
                ['url' => 'https://regulator.example/guidance', 'title' => 'Guidance for events', 'publisher' => 'The regulator', 'kind' => 'primary'],
                ['url' => 'https://press.example/report', 'title' => 'Trade report', 'publisher' => 'Trade press', 'kind' => 'press'],
            ],
            'confirmed' => [['text' => 'Bookings open on 1 March.', 'url' => 'https://regulator.example/guidance']],
            'speculation' => [['text' => 'Prices may rise.', 'url' => 'https://press.example/report']],
            'status' => 'published',
        ], $origin);
    }

    public function testCreateStoresTheArticleItsCategoriesAndItsLinks(): void
    {
        $before = time();
        $id = $this->store()->create(self::draft(), 5, null);
        $row = $this->db->one('SELECT * FROM news_articles WHERE id = ?', [$id]);
        self::assertSame('wireless-mic-licences-change-for-the-spring-season', $row['slug']);
        self::assertSame('Wireless mic licences change for the spring season', $row['seo_title'], 'filled in from the headline');
        self::assertSame(self::draft()->dek, $row['summary'], 'filled in from the standfirst');
        self::assertSame('radio mics,spectrum', $row['tags']);
        self::assertSame('published', $row['status']);
        self::assertGreaterThanOrEqual($before, (int) $row['published_at']);
        self::assertSame('manual', $row['origin']);
        self::assertSame(5, (int) $row['author_id']);
        self::assertSame(0, (int) $row['ai_written']);
        self::assertGreaterThan(20, (int) $row['word_count']);
        self::assertSame([['text' => 'Bookings open on 1 March.', 'url' => 'https://regulator.example/guidance']], json_decode((string) $row['confirmed'], true));
        self::assertSame(
            [['article_id' => $id, 'category' => 'audio', 'position' => 0], ['article_id' => $id, 'category' => 'legal', 'position' => 1]],
            self::categoryRows($this->db->all('SELECT * FROM news_article_categories WHERE article_id = ? ORDER BY position', [$id]))
        );

        $links = $this->db->all('SELECT url, url_key, host, title, publisher, kind, is_source, position FROM news_links WHERE article_id = ? ORDER BY position', [$id]);
        self::assertSame(
            [
                ['https://regulator.example/guidance', 'regulator.example', 'Guidance for events', 'primary', 1],
                ['https://press.example/report', 'press.example', 'Trade report', 'press', 1],
                ['https://press.example/review', 'press.example', null, 'other', 0],
                ['https://regulator.example/form', 'regulator.example', null, 'other', 0],
            ],
            array_map(static fn (array $l): array => [$l['url'], $l['host'], $l['title'], $l['kind'], (int) $l['is_source']], $links),
            'the sources, then the other external links once each by canonical key; the site\'s own links are not registered'
        );
        self::assertSame(hash('sha256', 'https://regulator.example/guidance'), $links[0]['url_key']);

        $html = Markdown::render((string) $row['body'], $this->store()->linkIds($id), $this->app->baseUrl());
        self::assertStringContainsString('href="https://www.regulator.example/guidance/?utm_source=x" rel="nofollow noopener" target="_blank" data-news-link="' . (int) $this->db->value('SELECT id FROM news_links WHERE url_key = ?', [$links[0]['url_key']]) . '"', $html);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{article_id: int, category: string, position: int}>
     */
    private static function categoryRows(array $rows): array
    {
        return array_map(static fn (array $r): array => ['article_id' => (int) $r['article_id'], 'category' => (string) $r['category'], 'position' => (int) $r['position']], $rows);
    }

    public function testNoStoredTextHoldsAnEmDash(): void
    {
        $dash = self::EM;
        $id = $this->store()->create(Draft::fromArray([
            'title' => "Comms {$dash} the new belt packs",
            'dek' => "A standfirst {$dash} with a dash in it, long enough to be a standfirst on the site.",
            'body' => "Body {$dash} text.\n\n- item {$dash} one",
            'impact' => "Impact {$dash} here.",
            'verdict_note' => "Note {$dash} here.",
            'tags' => ["tag {$dash} one"],
            'byline' => "Ben {$dash} R",
            'sources' => [['url' => 'https://example.com/a', 'title' => "Title {$dash} x", 'publisher' => "Pub {$dash} y"]],
            'confirmed' => [['text' => "Fact {$dash} one.", 'url' => 'https://example.com/a']],
            'speculation' => [['text' => "Guess {$dash} two.", 'url' => 'https://example.com/a']],
        ], 'manual'), null);
        foreach ([$this->db->one('SELECT * FROM news_articles WHERE id = ?', [$id]), ...$this->db->all('SELECT * FROM news_links WHERE article_id = ?', [$id])] as $row) {
            foreach ($row as $column => $value) {
                self::assertStringNotContainsString($dash, (string) $value, $column);
                self::assertStringNotContainsString("\u{2013}", (string) $value, $column);
            }
        }
        self::assertSame('Comms, the new belt packs', $this->store()->find($id)['title']);
    }

    public function testSlugsAreUnique(): void
    {
        $store = $this->store();
        $a = $store->create(self::draft(), null);
        $b = $store->create(self::draft(), null);
        $c = $store->create(self::draft(), null);
        $d = $store->create(self::draft(['slug' => 'Mic Licences!']), null);
        self::assertSame(
            ['wireless-mic-licences-change-for-the-spring-season', 'wireless-mic-licences-change-for-the-spring-season-2', 'wireless-mic-licences-change-for-the-spring-season-3', 'mic-licences'],
            array_map(static fn (int $id): string => (string) $store->find($id)['slug'], [$a, $b, $c, $d])
        );
        self::assertSame(['slug'], $store->update($b, self::draft(['slug' => 'mic-licences']), null));
        self::assertSame('mic-licences-2', $store->find($b)['slug'], 'an update never takes another article\'s slug');
    }

    public function testUpdateSaysWhatChangedAndKeepsTheClicksOfLinksThatStay(): void
    {
        $store = $this->store();
        $id = $store->create(self::draft(), null);
        $guidance = (int) $this->db->value('SELECT id FROM news_links WHERE article_id = ? AND url = ?', [$id, 'https://regulator.example/guidance']);
        $this->db->update('news_links', ['clicks' => 5], ['id' => $guidance]);
        $this->db->insert('news_link_clicks', ['link_id' => $guidance, 'day' => '2026-10-21', 'clicks' => 5]);
        $this->db->update('news_articles', ['updated_at' => 1000], ['id' => $id]);

        self::assertSame([], $store->update($id, self::draft(), null), 'the same draft changes nothing');
        self::assertSame(1000, (int) $this->db->value('SELECT updated_at FROM news_articles WHERE id = ?', [$id]));

        $changed = $store->update($id, self::draft([
            'title' => 'Wireless mic licences change, and sooner than expected',
            'impact' => 'Nothing to click now.',
            'categories' => ['legal', 'audio'],
            'sources' => [['url' => 'https://regulator.example/guidance/', 'title' => 'Guidance for events', 'publisher' => 'The regulator', 'kind' => 'primary']],
            'speculation' => [],
            'status' => 'draft',
        ]), null);
        self::assertSame(['title', 'seo_title', 'speculation', 'impact', 'categories', 'sources'], $changed);
        $row = $this->db->one('SELECT * FROM news_articles WHERE id = ?', [$id]);
        self::assertSame('published', $row['status'], 'only publish() and unpublish() change the status');
        self::assertGreaterThan(1000, (int) $row['updated_at']);
        self::assertSame(['legal', 'audio'], $store->categories($id));
        self::assertSame(5, (int) $this->db->value('SELECT clicks FROM news_links WHERE id = ?', [$guidance]), 'the link stayed, with its count');
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM news_link_clicks WHERE link_id = ?', [$guidance]));
        self::assertSame(
            ['https://regulator.example/guidance/', 'https://press.example/review'],
            array_column($store->links($id), 'url'),
            'the report and the form went'
        );
        $this->expectException(\InvalidArgumentException::class);
        $store->update(999, self::draft(), null);
    }

    public function testPublishScheduleAndUnpublish(): void
    {
        $store = $this->store();
        $id = $store->create(self::draft(['status' => 'draft']), null);
        self::assertNull($store->find($id)['published_at']);
        self::assertFalse($store->isLive($store->find($id)));
        self::assertTrue($store->publish($id));
        $first = (int) $store->find($id)['published_at'];
        self::assertTrue($store->isLive($store->find($id)));
        self::assertFalse($store->publish($id), 'already published at that time');
        self::assertTrue($store->unpublish($id));
        self::assertFalse($store->unpublish($id), 'already a draft');
        self::assertSame($first, (int) $store->find($id)['published_at'], 'a draft keeps its time');
        self::assertNull($store->findBySlug((string) $store->find($id)['slug']), 'not live');
        self::assertNotNull($store->findBySlug((string) $store->find($id)['slug'], false));
        self::assertTrue($store->publish($id));
        self::assertSame($first, (int) $store->find($id)['published_at'], 'published again at its first time');

        $later = time() + 3600;
        self::assertTrue($store->publish($id, $later));
        $row = $store->find($id);
        self::assertSame(['published', $later], [$row['status'], (int) $row['published_at']]);
        self::assertFalse($store->isLive($row), 'scheduled');
        self::assertTrue($store->isLive($row, $later));
        self::assertSame(0, $store->live(null, null)['total']);
        self::assertFalse($store->publish(999));
    }

    public function testTheLiveListFiltersSearchesAndPages(): void
    {
        $store = $this->store();
        $ids = [];
        foreach (range(1, 5) as $n) {
            $ids[$n] = $this->makeArticle(['title' => 'Story ' . $n . ' about LED walls and more', 'categories' => $n % 2 === 0 ? ['audio'] : ['video', 'audio'], 'published_at' => time() - 1000 + $n]);
        }
        $this->makeArticle(['title' => 'A draft that is not live yet', 'status' => 'draft']);
        $this->makeArticle(['title' => 'A scheduled story for tomorrow', 'published_at' => time() + 86400]);
        $this->db->insert('news_images', ['article_id' => $ids[5], 'position' => 0, 'base' => str_repeat('a', 32), 'widths' => '480,960', 'width' => 960, 'height' => 540, 'alt' => 'A wall', 'original_name' => 'wall.png', 'bytes' => 10, 'created_at' => time()]);

        $all = $store->live(null, null, 1, 2);
        self::assertSame(5, $all['total']);
        self::assertSame(3, $all['pages']);
        self::assertSame([$ids[5], $ids[4]], array_map(static fn (array $a): int => (int) $a['id'], $all['items']), 'the newest first');
        self::assertSame('A wall', $all['items'][0]['image']['alt'] ?? null, 'each item has its lead image');
        self::assertNull($all['items'][1]['image']);
        self::assertSame(['video', 'audio'], $all['items'][0]['categories']);
        self::assertArrayNotHasKey('body', $all['items'][0]);
        self::assertSame([], $store->live(null, null, 4, 2)['items'], 'a page past the end is empty, for a 404');

        $video = $store->live('video', null, 1, 12);
        self::assertSame([$ids[5], $ids[3], $ids[1]], array_map(static fn (array $a): int => (int) $a['id'], $video['items']));
        self::assertSame([$ids[3]], array_map(static fn (array $a): int => (int) $a['id'], $store->live(null, 'STORY 3', 1, 12)['items']), 'the headline searched');
        self::assertSame(0, $store->live(null, '100%_', 1, 12)['total'], 'LIKE wildcards are matched literally');
        self::assertSame(['video' => 3, 'audio' => 5], $store->categoryCounts(), 'live articles only, in category order');
    }

    public function testRecentListsEveryStatusWithSourceKeys(): void
    {
        $live = $this->makeArticle(['story_key' => 'a-story']);
        $draft = $this->makeArticle(['status' => 'draft']);
        $scheduled = $this->makeArticle(['published_at' => time() + 3600]);
        $old = $this->makeArticle(['published_at' => time() - 200 * 86400]);
        $recent = $this->store()->recent(120, 150);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $recent);
        self::assertSame([$scheduled, $draft, $live], $ids, 'scheduled first, the old one left out');
        self::assertNotContains($old, $ids);
        $byId = array_column($recent, null, 'id');
        self::assertSame('a-story', $byId[$live]['story_key']);
        self::assertSame([hash('sha256', 'https://example.com/news/story-1')], $byId[$live]['source_keys']);
        self::assertSame(['video'], $byId[$live]['categories']);
        self::assertIsInt($byId[$live]['date']);
    }

    public function testFollowUpsAreStoredBothWays(): void
    {
        $store = $this->store();
        $first = $this->makeArticle(['slug' => 'first-story']);
        $hidden = $this->makeArticle(['slug' => 'hidden-story', 'status' => 'draft']);
        $second = $store->create(self::draft(['follow_up_of' => ['first-story', 'hidden-story', 'no-such-story']], 'import'), null);
        self::assertSame([[(string) $first], [(string) $hidden]], array_map(static fn (array $r): array => [(string) $r['related_id']], $this->db->all('SELECT related_id FROM news_related WHERE article_id = ? ORDER BY related_id', [$second])));
        self::assertSame([$first], array_map(static fn (array $r): int => (int) $r['id'], $store->related($second)['earlier']), 'live ones only');
        self::assertSame([$first, $hidden], array_map(static fn (array $r): int => (int) $r['id'], $store->related($second, false)['earlier']));
        self::assertSame([$second], array_map(static fn (array $r): int => (int) $r['id'], $store->related($first)['later']));
        self::assertSame(['follow_up_of'], $store->update($second, self::draft(['follow_up_of' => [$first]], 'import'), null));
        self::assertSame([], $store->related($first, false)['earlier']);
    }

    public function testDeleteRemovesEverythingButTheImportRecordAndTheFiles(): void
    {
        $store = $this->store();
        $id = $this->makeArticle();
        $user = $this->makeUser();
        $base = str_repeat('b', 32);
        $other = str_repeat('c', 32);
        $dir = $this->app->storage->dir(ArticleStore::IMAGE_DIR);
        foreach (ArticleStore::imageFiles(['base' => $base]) as $name) {
            file_put_contents($dir . '/' . $name, 'x');
        }
        file_put_contents($dir . '/' . $other . '-480.webp', 'x');
        $this->db->insert('news_images', ['article_id' => $id, 'position' => 0, 'base' => $base, 'widths' => '480,960,1600', 'width' => 1600, 'height' => 900, 'alt' => 'A', 'original_name' => 'a.png', 'bytes' => 1, 'created_at' => time()]);
        $comment = $this->db->insert('news_comments', ['article_id' => $id, 'user_id' => $user, 'body' => 'Nice', 'status' => 'visible', 'reports' => 0, 'created_at' => time(), 'updated_at' => time()]);
        $this->db->insert('news_comment_reports', ['comment_id' => $comment, 'user_id' => $user, 'created_at' => time()]);
        $link = (int) $this->db->value('SELECT id FROM news_links WHERE article_id = ?', [$id]);
        $this->db->insert('news_link_clicks', ['link_id' => $link, 'day' => '2026-10-22', 'clicks' => 1]);
        $later = $this->makeArticle(['follow_up_of' => [$id]]);
        $this->db->insert('news_imports', ['import_key' => 'launch-1', 'article_id' => $id, 'imported_at' => time()]);

        $row = $store->delete($id);
        self::assertSame($id, (int) ($row['id'] ?? 0), 'the row as it was, for the audit');
        foreach (['news_articles WHERE id', 'news_article_categories WHERE article_id', 'news_links WHERE article_id', 'news_images WHERE article_id', 'news_comments WHERE article_id', 'news_related WHERE related_id'] as $where) {
            self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM ' . $where . ' = ?', [$id]), $where);
        }
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM news_link_clicks'));
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM news_comment_reports'));
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM news_imports WHERE import_key = 'launch-1'"), 'the file is never brought back');
        self::assertNotNull($store->find($later));
        foreach (ArticleStore::imageFiles(['base' => $base]) as $name) {
            self::assertFileDoesNotExist($dir . '/' . $name);
        }
        self::assertFileExists($dir . '/' . $other . '-480.webp', 'another image is untouched');
        self::assertNull($store->delete($id));
        self::assertSame([], ArticleStore::imageFiles(['base' => '../../etc']));
    }

    public function testAStoredArticleComesBackAsTheSameDraft(): void
    {
        $store = $this->store();
        $first = $this->makeArticle();
        $id = $store->create(self::draft(['follow_up_of' => [$first], 'byline' => 'Ben R', 'comments_open' => false, 'ai_written' => true]), 7);
        $draft = $store->draft($id);
        self::assertNotNull($draft);
        self::assertSame([$first], $draft->followUps);
        self::assertSame(['https://regulator.example/guidance', 'https://press.example/report'], array_column($draft->sources, 'url'));
        self::assertFalse($draft->commentsOpen);
        self::assertTrue($draft->aiWritten);
        self::assertSame('Ben R', $draft->byline);
        self::assertSame([], $store->update($id, $draft, 7), 'nothing changed');
        self::assertSame([], $store->update($id, Draft::fromArray($draft->toArray(), 'manual'), 7), 'nor through the editor\'s copy');
        self::assertNull($store->draft(999));
    }

    public function testAnAiArticleStaysAiWritten(): void
    {
        $id = $this->makeArticle(['origin' => 'ai']);
        $row = $this->store()->find($id);
        self::assertSame(['ai', 1, 'Industry Updates desk'], [$row['origin'], (int) $row['ai_written'], $row['byline']]);
        $draft = $this->store()->draft($id);
        $draft->aiWritten = false;
        $this->store()->update($id, $draft, null);
        self::assertSame(1, (int) $this->store()->find($id)['ai_written']);
        self::assertSame(Urls::key('https://example.com/news/story-1'), $this->store()->links($id)[0]['url_key']);
    }
}
