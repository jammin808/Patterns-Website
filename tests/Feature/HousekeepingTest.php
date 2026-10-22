<?php

declare(strict_types=1);

namespace Psc\Tests\Feature;

use Psc\News\ArticleStore;
use Psc\Support\Housekeeping;
use Psc\Tests\TestCase;

/**
 * The daily clean-up keeps what the privacy policy promises: contact messages no longer than two years and download
 * records no longer than about thirteen months were never deleted, and session files outlived their sessions by a
 * month. Industry Updates adds link clicks (about thirteen months), the desk's runs (six months) and images no
 * article holds (a day), with their files and no others: an image file no row names stays, since a database that
 * names none may be the wrong one. Rows and files on the near side of each limit stay, and the report reaches the log
 * with every count readable.
 */
final class HousekeepingTest extends TestCase
{
    private const DAY = 86400;

    private function contact(int $age): void
    {
        $this->db->insert('contact_messages', ['name' => 'N', 'email' => 'n@example.test', 'topic' => 'other', 'message' => 'Hello there, a message.', 'ip_hash' => 'h', 'status' => 'archived', 'created_at' => time() - $age]);
    }

    public function testContactMessagesAndDownloadRecordsGoAtTheirPromisedAge(): void
    {
        $this->contact(731 * self::DAY);
        $this->contact(700 * self::DAY);
        $release = $this->db->insert('releases', ['product' => 'stagewatch', 'version' => '2.16.0', 'title' => 'T', 'file_name' => 'a.zip', 'file_path' => 'x/a.zip', 'created_at' => time(), 'updated_at' => time()]);
        foreach ([401, 30] as $days) {
            $this->db->insert('download_events', ['release_id' => $release, 'user_id' => null, 'ip_hash' => 'h', 'created_at' => time() - $days * self::DAY]);
        }

        $report = Housekeeping::run($this->app);
        self::assertSame(1, $report['contact_messages']);
        self::assertSame(1, $report['download_events']);
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM contact_messages'));
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM download_events'));
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM releases'), 'the release itself stays');

        $again = Housekeeping::run($this->app);
        self::assertSame(0, $again['contact_messages'] + $again['download_events'], 'safe to run again');
    }

    public function testSessionFilesGoOnceTheirSessionHasEnded(): void
    {
        $dir = $this->app->storage->dir('sessions');
        $files = ['sess_old' => 2 * self::DAY, 'sess_recent' => 3600, 'sess_edge' => 43200 + 1800];
        foreach ($files as $name => $age) {
            file_put_contents($dir . '/' . $name, 'x');
            touch($dir . '/' . $name, time() - $age);
        }
        $report = Housekeeping::run($this->app);
        self::assertSame(1, $report['sessions']);
        self::assertFileDoesNotExist($dir . '/sess_old', 'a two-day-old session file is past every session limit');
        self::assertFileExists($dir . '/sess_recent');
        self::assertFileExists($dir . '/sess_edge', 'within the absolute limit plus its margin');
    }

    public function testSpentAndExpiredSignInCredentialsGoUnderAnUnmaskedName(): void
    {
        $user = $this->makeUser();
        $token = fn (string $selector, int $expires, ?int $used): int => $this->db->insert('user_tokens', ['user_id' => $user, 'purpose' => 'reset', 'selector' => $selector, 'token_hash' => 'x', 'expires_at' => $expires, 'used_at' => $used, 'created_at' => time()]);
        $token('expired', time() - 10, null);
        $token('spent', time() + 3600, time() - 2 * self::DAY);
        $token('live', time() + 3600, null);

        $report = Housekeeping::run($this->app);
        self::assertSame(2, $report['expired_credentials']);
        self::assertArrayNotHasKey('tokens', $report);
        self::assertSame(['live'], array_column($this->db->all('SELECT selector FROM user_tokens'), 'selector'));

        // tools/cron.php logs the report as it is; the Logger masks keys that look like secrets, so none may.
        $this->app->log->info('cron', $report);
        $logged = (string) file_get_contents($this->app->storage->path('logs') . '/site-' . gmdate('Y-m-d') . '.log');
        self::assertStringNotContainsString('***', $logged, 'every count in the report is readable in the log');
        self::assertStringContainsString('"expired_credentials":2', $logged);
        self::assertStringContainsString('"news_image_files":0', $logged);
    }

    public function testIndustryUpdatesRecordsGoAtTheirPromisedAge(): void
    {
        $article = $this->makeArticle();
        $link = (int) $this->db->value('SELECT id FROM news_links WHERE article_id = ? ORDER BY id LIMIT 1', [$article]);
        foreach ([401, 399, 0] as $days) {
            $this->db->insert('news_link_clicks', ['link_id' => $link, 'day' => gmdate('Y-m-d', time() - $days * self::DAY), 'clicks' => 3]);
        }
        foreach (['old' => 181, 'recent' => 179] as $slot => $days) {
            $this->db->insert('news_runs', ['slot' => 'manual-' . $slot, 'kind' => 'manual', 'status' => 'done', 'created_at' => time() - $days * self::DAY]);
        }

        $report = Housekeeping::run($this->app);
        self::assertSame(1, $report['news_link_clicks']);
        self::assertSame(1, $report['news_runs']);
        self::assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM news_link_clicks'));
        self::assertSame(['manual-recent'], array_column($this->db->all('SELECT slot FROM news_runs'), 'slot'));
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM news_links WHERE id = ?', [$link]), 'the link and its total stay');

        $again = Housekeeping::run($this->app);
        self::assertSame(0, $again['news_link_clicks'] + $again['news_runs'], 'safe to run again');
    }

    public function testImagesNoArticleHoldsGoAfterADayWithTheirFiles(): void
    {
        $article = $this->makeArticle();
        $dir = $this->app->storage->dir(ArticleStore::IMAGE_DIR);
        $image = function (?int $articleId, int $age) use ($dir): array {
            $base = bin2hex(random_bytes(16));
            $id = $this->db->insert('news_images', ['article_id' => $articleId, 'position' => 0, 'base' => $base, 'widths' => '480,960,1600', 'width' => 1600, 'height' => 900, 'alt' => 'A test image', 'original_name' => 'a.png', 'bytes' => 10, 'created_at' => time() - $age]);
            foreach (ArticleStore::imageFiles(['base' => $base]) as $name) {
                file_put_contents($dir . '/' . $name, 'x');
                touch($dir . '/' . $name, time() - $age);
            }
            return [$id, $base];
        };
        [$orphan, $orphanBase] = $image(null, 2 * self::DAY);
        [$fresh, $freshBase] = $image(null, 3600);
        [$held, $heldBase] = $image($article, 30 * self::DAY);
        // Files named like an image's that no row names (a month old), and an unrelated file.
        $stray = bin2hex(random_bytes(16));
        foreach ([$stray . '-960.webp' => 30 * self::DAY, $stray . '-og.jpg' => 30 * self::DAY, 'notes.txt' => 30 * self::DAY] as $name => $age) {
            file_put_contents($dir . '/' . $name, 'x');
            touch($dir . '/' . $name, time() - $age);
        }

        $report = Housekeeping::run($this->app);
        self::assertSame(1, $report['news_images']);
        self::assertSame(4, $report['news_image_files'], 'the orphan\'s four files, and no others');
        self::assertNull($this->db->value('SELECT id FROM news_images WHERE id = ?', [$orphan]));
        self::assertSame([$fresh, $held], array_map('intval', array_column($this->db->all('SELECT id FROM news_images ORDER BY id'), 'id')));
        self::assertSame([], glob($dir . '/' . $orphanBase . '-*') ?: []);
        self::assertCount(2, glob($dir . '/' . $stray . '-*') ?: [], 'a file no row names is not this database\'s to judge');
        self::assertCount(4, glob($dir . '/' . $freshBase . '-*') ?: [], 'an image uploaded within the day stays');
        self::assertCount(4, glob($dir . '/' . $heldBase . '-*') ?: [], 'an article\'s image stays however old');
        self::assertFileExists($dir . '/notes.txt');

        $again = Housekeeping::run($this->app);
        self::assertSame(0, $again['news_images'] + $again['news_image_files'], 'safe to run again');
    }

    public function testImageFilesStayWhenTheConnectedDatabaseNamesNone(): void
    {
        // The site on its empty SQLite fallback (db.dsn missing from the config) beside the real uploads folder:
        // every article's images, a month old, and not one news_images row.
        $dir = $this->app->storage->dir(ArticleStore::IMAGE_DIR);
        $names = [];
        foreach ([1, 2, 3] as $n) {
            foreach (ArticleStore::imageFiles(['base' => bin2hex(random_bytes(16))]) as $name) {
                file_put_contents($dir . '/' . $name, 'image ' . $n);
                touch($dir . '/' . $name, time() - 30 * self::DAY);
                $names[] = $name;
            }
        }
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM news_images'));

        $report = Housekeeping::run($this->app);
        self::assertSame(0, $report['news_images']);
        self::assertSame(0, $report['news_image_files']);
        foreach ($names as $name) {
            self::assertFileExists($dir . '/' . $name, 'the files are the only copies; the database may be the wrong one');
        }
    }
}
