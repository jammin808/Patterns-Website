<?php

declare(strict_types=1);

namespace Psc\Tests\Feature\News;

use Psc\News\ArticleStore;
use Psc\News\Importer;
use Psc\Tests\TestCase;

/** Article files are published once each, a bad one never stops the others, and the repository's files all pass. */
final class ImporterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = $this->storage . '/articles';
        mkdir($this->dir, 0777, true);
    }

    /** @param array<string, mixed> $over */
    private function file(string $name, array $over = []): void
    {
        $n = count(glob($this->dir . '/*.json') ?: []) + 1;
        $article = $over + [
            'key' => 'launch-' . $n,
            'title' => 'Launch article ' . $n . ' on a subject of its own number ' . $n,
            'seo_title' => '',
            'dek' => 'The standfirst of launch article ' . $n . ', which says what happened and why a crew chief should care.',
            'summary' => '',
            'body' => str_repeat('Launch article ' . $n . ' has a body that is long enough to publish, written in plain words for the test. ', 4),
            'confirmed' => [['text' => 'Something happened in case ' . $n . '.', 'url' => 'https://source' . $n . '.example/news']],
            'speculation' => [],
            'impact' => 'It changes little for a freelancer, which is the point of the test.',
            'verdict' => 'note',
            'verdict_note' => 'Good to know.',
            'categories' => ['tips'],
            'tags' => ['launch'],
            'sources' => [['url' => 'https://source' . $n . '.example/news', 'title' => 'Source ' . $n, 'publisher' => 'Source', 'kind' => 'press']],
            'follow_up_of' => [],
            'story_key' => 'launch-story-' . $n,
        ];
        file_put_contents($this->dir . '/' . $name, json_encode($article, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function importer(): Importer
    {
        return new Importer($this->app);
    }

    public function testAFileIsImportedOnce(): void
    {
        $this->file('2026-10-20-first.json', ['published_at' => '2026-10-20T08:30:00+01:00']);
        $report = $this->importer()->run($this->dir);
        self::assertSame([], $report['failed']);
        self::assertCount(1, $report['imported']);
        $id = $report['imported'][0]['id'];
        $row = $this->db->one('SELECT * FROM news_articles WHERE id = ?', [$id]);
        self::assertSame(['import', 'published', 1792481400, 1, 'Industry Updates desk'], [$row['origin'], $row['status'], (int) $row['published_at'], (int) $row['ai_written'], $row['byline']]);
        self::assertSame($id, (int) $this->db->value("SELECT article_id FROM news_imports WHERE import_key = 'launch-1'"));
        self::assertSame(['tips'], (new ArticleStore($this->app))->categories($id));

        $again = $this->importer()->run($this->dir);
        self::assertSame([], $again['imported']);
        self::assertSame([['file' => '2026-10-20-first.json', 'key' => 'launch-1']], $again['skipped']);
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM news_articles'));
    }

    public function testADeletedImportIsNeverBroughtBack(): void
    {
        $this->file('a.json');
        $id = $this->importer()->run($this->dir)['imported'][0]['id'];
        (new ArticleStore($this->app))->delete($id);
        $report = $this->importer()->run($this->dir);
        self::assertSame([], $report['imported']);
        self::assertCount(1, $report['skipped']);
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM news_articles'));
    }

    public function testABadFileIsLoggedAndSkippedWhileTheOthersImport(): void
    {
        $this->file('1-good.json');
        file_put_contents($this->dir . '/2-broken.json', '{"key": "broken", "title": ');
        file_put_contents($this->dir . '/3-list.json', '[1, 2]');
        $this->file('4-short.json', ['key' => 'short', 'title' => 'Too short']);
        $this->file('5-nokey.json', ['key' => '']);
        $this->file('6-good.json');
        $report = $this->importer()->run($this->dir);
        self::assertSame(['1-good.json', '6-good.json'], array_column($report['imported'], 'file'));
        self::assertSame(['2-broken.json', '3-list.json', '4-short.json', '5-nokey.json'], array_column($report['failed'], 'file'));
        self::assertStringStartsWith('The file is not valid JSON', $report['failed'][0]['reasons'][0]);
        self::assertSame(['The file must hold one JSON object.'], $report['failed'][1]['reasons']);
        self::assertSame(['title: Enter a headline of 10 to 160 characters.'], $report['failed'][2]['reasons']);
        self::assertSame(['key: Give the file a key of up to 190 characters.'], $report['failed'][3]['reasons']);
        self::assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM news_imports WHERE import_key IN ('broken', 'short')"), 'a refused file is not marked imported');
        $log = (string) file_get_contents((string) (glob($this->storage . '/logs/*.log') ?: [''])[0]);
        self::assertStringContainsString('"msg":"news import failed","ctx":{"file":"4-short.json"', $log);
    }

    public function testFilesWithoutATimeGoLiveTenToThirtyMinutesApart(): void
    {
        $this->file('a.json');
        $this->file('b.json');
        $this->file('c.json');
        $before = time();
        $times = array_column($this->importer()->run($this->dir)['imported'], 'published_at');
        self::assertGreaterThanOrEqual($before, $times[0]);
        self::assertLessThanOrEqual(time(), $times[0], 'the first goes live now');
        foreach ([1, 2] as $i) {
            self::assertGreaterThanOrEqual(600, $times[$i] - $times[$i - 1]);
            self::assertLessThanOrEqual(1800, $times[$i] - $times[$i - 1]);
        }
        self::assertSame(1, (new ArticleStore($this->app))->live(null, null)['total'], 'the others are scheduled');

        self::rmrf($this->storage);
        $this->bootApp(['news' => ['import_stagger' => false]]);
        mkdir($this->dir = $this->storage . '/articles', 0777, true);
        $this->file('a.json');
        $this->file('b.json');
        $report = $this->importer()->run($this->dir);
        self::assertSame(2, (new ArticleStore($this->app))->live(null, null)['total'], 'without the stagger every article is live at once');
        self::assertCount(2, $report['imported']);
    }

    public function testAFollowUpNamesItsEarlierArticleBySlugAndAFileMayDeclineTheAiNote(): void
    {
        $this->file('1.json');
        $this->file('2.json', [
            'follow_up_of' => ['launch-article-1-on-a-subject-of-its-own-number-1'],
            'ai_written' => false,
            'byline' => 'Ben Rohleder',
        ]);
        $report = $this->importer()->run($this->dir);
        self::assertSame([], $report['failed']);
        [$first, $second] = array_column($report['imported'], 'id');
        self::assertSame([$first], array_map('intval', array_column($this->db->all('SELECT related_id FROM news_related WHERE article_id = ?', [$second]), 'related_id')));
        $row = $this->db->one('SELECT ai_written, byline FROM news_articles WHERE id = ?', [$second]);
        self::assertSame([0, 'Ben Rohleder'], [(int) $row['ai_written'], $row['byline']]);
    }

    public function testADuplicateFileIsRefused(): void
    {
        $this->file('1.json');
        $this->file('2.json', ['story_key' => 'launch-story-1', 'sources' => [['url' => 'https://source1.example/news', 'title' => 'Same']], 'confirmed' => []]);
        $report = $this->importer()->run($this->dir);
        self::assertCount(1, $report['imported']);
        self::assertSame('2.json', $report['failed'][0]['file'] ?? null);
        self::assertStringStartsWith('duplicate: Duplicates article ', $report['failed'][0]['reasons'][0]);
    }

    /**
     * The fence: every article file in the repository imports with origin import, in file-name order, into an
     * empty database. Launch articles are being added to content/news/articles/, so it holds for whatever files are
     * there, and none at all.
     */
    public function testEveryArticleFileInTheRepositoryImports(): void
    {
        $dir = Importer::directory($this->app);
        $files = glob($dir . '/*.json') ?: [];
        $report = $this->importer()->run();
        self::assertSame([], self::failures($report), 'every file in content/news/articles/ passes the rules');
        self::assertCount(count($files), $report['imported']);
        foreach ($report['imported'] as $article) {
            $row = $this->db->one('SELECT * FROM news_articles WHERE id = ?', [$article['id']]);
            foreach ($row as $column => $value) {
                self::assertStringNotContainsString("\u{2014}", (string) $value, $article['file'] . ': ' . $column);
            }
        }
    }

    /** The fence fails when a file breaks a rule: proven on a folder holding a good file and a bad one. */
    public function testTheFenceFailsOnAnInvalidFile(): void
    {
        $this->file('good.json');
        $this->file('bad.json', ['sources' => [], 'confirmed' => []]);
        $failures = self::failures($this->importer()->run($this->dir));
        self::assertSame(['bad.json' => ['sources: Add at least one source.']], $failures);
    }

    /**
     * @param array{failed: list<array{file: string, reasons: list<string>}>} $report
     * @return array<string, list<string>>
     */
    private static function failures(array $report): array
    {
        return array_column($report['failed'], 'reasons', 'file');
    }
}
