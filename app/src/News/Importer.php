<?php

declare(strict_types=1);

namespace Psc\News;

use Psc\App;
use Psc\Db\Database;

/**
 * Publishes the article files in content/news/articles/*.json (the launch articles, and any written in a session
 * on the repository), each once: its key goes into news_imports in the same transaction as the article, first, so
 * a file whose key is there (a primary-key conflict) was imported already and is skipped, even after its article
 * was deleted. A file is checked by the Validator with origin import before anything is written; a file that
 * cannot be read or fails a rule is logged and skipped, and the others go on.
 *
 * A file's published_at is kept; files without one go live from now, 10 to 30 minutes apart when
 * news.import_stagger is on (it is off in the browser tests, so every launch article is live at once).
 */
final class Importer
{
    /** The largest article file read, in bytes. */
    public const MAX_BYTES = 512 * 1024;

    public function __construct(private readonly App $app)
    {
    }

    /** Where the article files are. */
    public static function directory(App $app): string
    {
        return $app->root . '/content/news/articles';
    }

    /**
     * Imports every file in $dir (default: directory()) in file-name order.
     *
     * @return array{
     *     imported: list<array{file: string, key: string, id: int, slug: string, published_at: int}>,
     *     skipped: list<array{file: string, key: string}>,
     *     failed: list<array{file: string, reasons: list<string>}>
     * }
     */
    public function run(?string $dir = null): array
    {
        $dir = rtrim($dir ?? self::directory($this->app), '/');
        $report = ['imported' => [], 'skipped' => [], 'failed' => []];
        $files = is_dir($dir) ? (glob($dir . '/*.json') ?: []) : [];
        sort($files, SORT_STRING);
        if ($files === []) {
            return $report;
        }
        $store = new ArticleStore($this->app);
        $validator = new Validator($store);
        $context = (new Settings($this->app))->context('import');
        $stagger = $this->app->config->bool('news.import_stagger', true);
        $next = time();
        foreach ($files as $file) {
            $name = basename($file);
            try {
                $data = self::read($file);
                $key = is_string($data['key'] ?? null) ? trim($data['key']) : '';
                if ($key !== '' && $this->imported($key)) {
                    $report['skipped'][] = ['file' => $name, 'key' => $key];
                    continue;
                }
                $result = $validator->check(Draft::fromArray($data, 'import'), $context);
                if (!$result->ok()) {
                    $this->fail($report, $name, $result->reasons());
                    continue;
                }
                $article = $result->draft;
                $article->status = 'published';
                if ($article->publishAt === null) {
                    $article->publishAt = $next;
                    if ($stagger) {
                        $next += random_int(10, 30) * 60;
                    }
                }
                $id = $this->app->db()->transaction(static function (Database $db) use ($key, $article, $store): ?int {
                    try {
                        $db->insert('news_imports', ['import_key' => $key, 'article_id' => null, 'imported_at' => time()]);
                    } catch (\PDOException $e) {
                        if (ArticleStore::isDuplicate($e)) {
                            return null;
                        }
                        throw $e;
                    }
                    $id = $store->create($article, null, null);
                    $db->update('news_imports', ['article_id' => $id], ['import_key' => $key]);
                    return $id;
                });
                if ($id === null) {
                    $report['skipped'][] = ['file' => $name, 'key' => $key];
                    continue;
                }
                $row = $store->find($id);
                $report['imported'][] = ['file' => $name, 'key' => $key, 'id' => $id, 'slug' => (string) ($row['slug'] ?? ''), 'published_at' => (int) ($row['published_at'] ?? 0)];
                foreach ($result->warnings as $warning) {
                    $this->app->log->info('news import note', ['file' => $name, 'note' => $warning]);
                }
            } catch (\Throwable $e) {
                $this->app->log->exception($e, 'news import ' . $name);
                $this->fail($report, $name, [$e->getMessage()]);
            }
        }
        if ($report['imported'] !== [] || $report['failed'] !== []) {
            $this->app->log->info('news import', ['imported' => count($report['imported']), 'skipped' => count($report['skipped']), 'failed' => count($report['failed'])]);
        }
        return $report;
    }

    /** Whether a file with this key was imported already (its article may since have been deleted). */
    public function imported(string $key): bool
    {
        return $this->app->db()->value('SELECT 1 FROM news_imports WHERE import_key = ?', [$key]) !== null;
    }

    /**
     * An article file's JSON object.
     *
     * @return array<string, mixed>
     */
    public static function read(string $file): array
    {
        $size = @filesize($file);
        if ($size === false || $size > self::MAX_BYTES) {
            throw new \RuntimeException('The file is missing or larger than 512 KB.');
        }
        $json = (string) @file_get_contents($file);
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('The file is not valid JSON: ' . $e->getMessage() . '.');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new \RuntimeException('The file must hold one JSON object.');
        }
        return $data;
    }

    /**
     * @param array{imported: list<mixed>, skipped: list<mixed>, failed: list<array{file: string, reasons: list<string>}>} $report
     * @param list<string> $reasons
     */
    private function fail(array &$report, string $file, array $reasons): void
    {
        $report['failed'][] = ['file' => $file, 'reasons' => $reasons];
        $this->app->log->warning('news import failed', ['file' => $file, 'reasons' => $reasons]);
    }
}
