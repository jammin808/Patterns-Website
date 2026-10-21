<?php

declare(strict_types=1);

namespace Psc\Tests\Feature;

use Psc\Support\Housekeeping;
use Psc\Tests\TestCase;

/**
 * The daily clean-up keeps what the privacy policy promises: contact messages no longer than two years and download
 * records no longer than about thirteen months were never deleted, and session files outlived their sessions by a
 * month. Rows and files on the near side of each limit stay.
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
}
