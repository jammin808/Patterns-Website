<?php

declare(strict_types=1);

namespace Psc\Tests\Feature\Portal;

use Psc\Controllers\Admin\AdminReleaseController;
use Psc\Controllers\Portal\DownloadController;
use Psc\Http\Response;
use Psc\Tests\TestCase;

/**
 * Downloads, releases and licences: who may download what, how a file is served (streamed with ranges, or handed
 * to LiteSpeed), the administrator's release and licence pages, and the licence request from form to file.
 */
final class DownloadsLicencesTest extends TestCase
{
    private const CLIENT = 'client@example.test';
    private const OTHER = 'other@example.test';
    private const ADMIN = 'admin@example.test';
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz';

    private string $vault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vault = sys_get_temp_dir() . '/psc-vault-' . bin2hex(random_bytes(6));
        mkdir($this->vault, 0777, true);
        $this->boot();
    }

    protected function tearDown(): void
    {
        if (isset($this->vault) && is_dir($this->vault)) {
            self::rmrf($this->vault);
        }
        parent::tearDown();
    }

    /** Boots a fresh app whose vault is this test's temporary folder (the first boot's storage is removed). */
    private function boot(array $config = []): void
    {
        $previous = $this->storage;
        $this->bootApp(array_replace_recursive(['downloads' => ['vault_path' => $this->vault]], $config));
        if ($previous !== $this->storage && is_dir($previous)) {
            self::rmrf($previous);
        }
    }

    // ---------------------------------------------------------------------------------------------------------
    // Helpers

    /** Puts a file in the vault and a release row in the database. */
    private function makeRelease(array $row = [], string $contents = self::ALPHABET): int
    {
        $folder = bin2hex(random_bytes(16));
        $name = $row['file_name'] ?? 'StageWatch-Setup.exe';
        // In the vault a file name has safe characters only; the name people see is kept as file_name.
        $stored = (string) preg_replace('/[^A-Za-z0-9._+-]+/', '-', $name);
        mkdir($this->vault . '/' . $folder, 0777, true);
        file_put_contents($this->vault . '/' . $folder . '/' . $stored, $contents);
        $now = time();
        return $this->db->insert('releases', $row + [
            'product' => 'stagewatch',
            'version' => '2.16.0',
            'channel' => 'stable',
            'title' => 'Faster start-up',
            'notes' => "Highlights:\n\n- **Running order** import\n- Fewer clicks",
            'file_name' => $name,
            'file_path' => $folder . '/' . $stored,
            'file_size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'access' => 'registered',
            'is_published' => 1,
            'download_count' => 0,
            'published_at' => $now,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function makeLicence(int $userId, array $row = []): int
    {
        $now = time();
        return $this->db->insert('licences', $row + [
            'user_id' => $userId,
            'product' => 'stagewatch',
            'tier' => 'pro',
            'status' => 'requested',
            'hardware_ids' => json_encode(['HW-ABCDEF-1234']),
            'nonprofit' => 0,
            'quantity' => 1,
            'customer_note' => null,
            'admin_note' => null,
            'reference' => 'SW-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6)),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** Signs in as someone else: the browser's session and cookies are dropped first. */
    private function signInAs(string $email): void
    {
        $_SESSION = [];
        $this->jar = [];
        $r = $this->signIn($email);
        self::assertSame(303, $r->status, 'sign in as ' . $email);
    }

    /** The validation errors kept for the form the last POST was sent back to. */
    private static function errorKeys(): array
    {
        $errors = $_SESSION['_errors'] ?? [];
        return is_array($errors) ? array_keys($errors) : [];
    }

    private static function zipBytes(): string
    {
        // An empty zip archive: just the end-of-central-directory record.
        return "PK\x05\x06" . str_repeat("\0", 18);
    }

    private function downloads(int $releaseId): int
    {
        return (int) $this->db->value('SELECT download_count FROM releases WHERE id = ?', [$releaseId]);
    }

    private function events(int $releaseId): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM download_events WHERE release_id = ?', [$releaseId]);
    }

    /** @return array<string, string> a valid request for StageWatch Standard */
    private static function standardRequest(array $over = []): array
    {
        return $over + ['product' => 'stagewatch', 'tier' => 'standard', 'hardware_id_1' => 'SW-HW-9F2A-77C1', 'note' => ''];
    }

    // ---------------------------------------------------------------------------------------------------------
    // Access

    public function testEveryPageNeedsASignedInAccount(): void
    {
        $id = $this->makeRelease();
        foreach (['/portal/downloads/', '/portal/downloads/' . $id . '/', '/portal/licences/', '/portal/licences/request/', '/portal/licences/1/', '/portal/licences/1/files/1/'] as $path) {
            $r = $this->request('GET', $path);
            self::assertSame(302, $r->status, $path);
            self::assertSame('/portal/login/?next=' . rawurlencode($path), $r->getHeader('Location'), $path);
        }
        self::assertSame(0, $this->downloads($id));
        $r = $this->post('/portal/licences/request/', self::standardRequest());
        self::assertSame(302, $r->status);
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM licences'));
    }

    public function testAdminPagesAreForAdministratorsOnly(): void
    {
        $this->makeUser(self::CLIENT);
        $release = $this->makeRelease();
        $licence = $this->makeLicence($this->makeUser(self::OTHER));
        $this->signInAs(self::CLIENT);
        foreach (['/portal/admin/releases/', '/portal/admin/releases/new/', '/portal/admin/releases/' . $release . '/', '/portal/admin/licences/', '/portal/admin/licences/' . $licence . '/'] as $path) {
            self::assertSame(403, $this->request('GET', $path)->status, $path);
        }
        self::assertSame(403, $this->post('/portal/admin/releases/' . $release . '/', ['action' => 'delete', 'confirm' => '1'])->status);
        self::assertSame(403, $this->post('/portal/admin/licences/' . $licence . '/', ['action' => 'set_status', 'status' => 'revoked'])->status);
        self::assertNotNull($this->db->value('SELECT id FROM releases WHERE id = ?', [$release]));
        self::assertSame('requested', $this->db->value('SELECT status FROM licences WHERE id = ?', [$licence]));
    }

    public function testPostsWithoutTheCsrfTokenAreRefused(): void
    {
        $this->makeUser(self::ADMIN, 'correct horse battery', 'admin');
        $release = $this->makeRelease(['is_published' => 0, 'published_at' => null]);
        $this->signInAs(self::ADMIN);
        self::assertSame(419, $this->post('/portal/admin/releases/' . $release . '/', ['action' => 'publish'], false)->status);
        self::assertSame(0, (int) $this->db->value('SELECT is_published FROM releases WHERE id = ?', [$release]));
        self::assertSame(419, $this->post('/portal/licences/request/', self::standardRequest(), false)->status);
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM licences'));
    }

    // ---------------------------------------------------------------------------------------------------------
    // The downloads page

    public function testTheDownloadsPageGroupsPublishedReleasesNewestFirstAndHidesDrafts(): void
    {
        $this->makeUser(self::CLIENT);
        $old = $this->makeRelease(['version' => '2.15.0', 'title' => 'Older build', 'published_at' => time() - 86400 * 30]);
        $new = $this->makeRelease(['version' => '2.16.0', 'title' => 'Newer build', 'channel' => 'beta']);
        $draft = $this->makeRelease(['version' => '2.17.0', 'title' => 'Secret draft', 'is_published' => 0, 'published_at' => null]);
        $patterns = $this->makeRelease(['product' => 'patterns', 'version' => '1.4.0', 'title' => 'Patterns build', 'file_name' => 'Patterns-1.4.0.zip']);
        $this->signInAs(self::CLIENT);

        $r = $this->request('GET', '/portal/downloads/');
        self::assertSame(200, $r->status);
        $html = $r->body;
        self::assertStringNotContainsString('2.17.0', $html);
        self::assertStringNotContainsString('Secret draft', $html);
        self::assertStringNotContainsString('/portal/downloads/' . $draft . '/', $html);
        // Ecosystem order: Patterns before StageWatch; inside a product the newest first.
        self::assertLessThan(strpos($html, 'id="stagewatch"'), strpos($html, 'id="patterns"'));
        self::assertLessThan(strpos($html, 'StageWatch 2.15.0'), strpos($html, 'StageWatch 2.16.0'));
        self::assertSame(2, substr_count($html, '>Latest</span>'));
        self::assertStringContainsString('pt-badge--beta', $html);
        self::assertStringContainsString('<strong>Running order</strong>', $html);
        self::assertStringContainsString(hash('sha256', self::ALPHABET), $html);
        foreach ([$old, $new, $patterns] as $id) {
            self::assertStringContainsString('href="/portal/downloads/' . $id . '/"', $html);
        }
        self::assertSame(404, $this->request('GET', '/portal/downloads/' . $draft . '/')->status);
        self::assertSame(0, $this->downloads($draft));
    }

    public function testAnAdministratorCanPreviewADraftWithoutCountingIt(): void
    {
        $this->makeUser(self::ADMIN, 'correct horse battery', 'admin');
        $draft = $this->makeRelease(['is_published' => 0, 'published_at' => null]);
        $this->signInAs(self::ADMIN);
        $r = $this->request('GET', '/portal/downloads/' . $draft . '/');
        self::assertSame(200, $r->status);
        self::assertSame(self::ALPHABET, $r->captureStream());
        self::assertSame(0, $this->downloads($draft));
        self::assertSame(0, $this->events($draft));
    }

    public function testARegisteredDownloadStreamsTheFileCountsItAndRecordsAnEvent(): void
    {
        $uid = $this->makeUser(self::CLIENT);
        $id = $this->makeRelease(['file_name' => 'StageWatch Setup 2.16.0.exe']);
        $this->signInAs(self::CLIENT);

        $r = $this->request('GET', '/portal/downloads/' . $id . '/');
        self::assertSame(200, $r->status);
        self::assertTrue($r->isStream());
        self::assertSame(self::ALPHABET, $r->captureStream());
        self::assertSame('26', $r->getHeader('Content-Length'));
        self::assertSame('bytes', $r->getHeader('Accept-Ranges'));
        self::assertStringStartsWith('attachment; filename="StageWatch Setup 2.16.0.exe"', (string) $r->getHeader('Content-Disposition'));
        self::assertSame('nosniff', $r->getHeader('X-Content-Type-Options'));
        self::assertSame(1, $this->downloads($id));
        $event = $this->db->one('SELECT * FROM download_events WHERE release_id = ?', [$id]);
        self::assertNotNull($event);
        self::assertSame($uid, (int) $event['user_id']);
        self::assertSame($this->app->ipHash('203.0.113.7'), $event['ip_hash']);

        // A HEAD request answers the headers and is not a download.
        $head = $this->request('HEAD', '/portal/downloads/' . $id . '/');
        self::assertSame(200, $head->status);
        self::assertSame('', $head->captureStream());
        self::assertSame(1, $this->downloads($id));
        self::assertSame(1, $this->events($id));

        self::assertSame(404, $this->request('GET', '/portal/downloads/' . ($id + 99) . '/')->status);
    }

    public function testALicensedBuildNeedsAnIssuedLicenceForItsProduct(): void
    {
        $uid = $this->makeUser(self::CLIENT);
        $id = $this->makeRelease(['access' => 'licensed', 'title' => 'Production build']);
        $this->signInAs(self::CLIENT);

        $page = $this->request('GET', '/portal/downloads/')->body;
        self::assertStringContainsString('Production build', $page);
        self::assertStringNotContainsString('href="/portal/downloads/' . $id . '/"', $page);
        self::assertStringContainsString('href="/portal/licences/request/?product=stagewatch"', $page);
        self::assertSame(404, $this->request('GET', '/portal/downloads/' . $id . '/')->status);

        // Requested, revoked, or another product's licence: still no.
        $licence = $this->makeLicence($uid, ['status' => 'requested']);
        $this->makeLicence($uid, ['product' => 'patterns', 'status' => 'issued']);
        self::assertSame(404, $this->request('GET', '/portal/downloads/' . $id . '/')->status);
        $this->db->update('licences', ['status' => 'revoked'], ['id' => $licence]);
        self::assertSame(404, $this->request('GET', '/portal/downloads/' . $id . '/')->status);
        self::assertSame(0, $this->downloads($id));

        $this->db->update('licences', ['status' => 'issued'], ['id' => $licence]);
        $r = $this->request('GET', '/portal/downloads/' . $id . '/');
        self::assertSame(200, $r->status);
        self::assertSame(self::ALPHABET, $r->captureStream());
        self::assertSame(1, $this->downloads($id));
        self::assertStringContainsString('href="/portal/downloads/' . $id . '/"', $this->request('GET', '/portal/downloads/')->body);
    }

    public function testARangeRequestGetsPartialContentAndAResumeIsNotCountedAgain(): void
    {
        $this->makeUser(self::CLIENT);
        $id = $this->makeRelease();
        $this->signInAs(self::CLIENT);
        $url = '/portal/downloads/' . $id . '/';

        $r = $this->request('GET', $url, [], ['Range' => 'bytes=5-9']);
        self::assertSame(206, $r->status);
        self::assertSame('fghij', $r->captureStream());
        self::assertSame('bytes 5-9/26', $r->getHeader('Content-Range'));
        self::assertSame('5', $r->getHeader('Content-Length'));
        self::assertSame(0, $this->downloads($id), 'a range that does not start at 0 is a resume');

        $r = $this->request('GET', $url, [], ['Range' => 'bytes=-3']);
        self::assertSame(206, $r->status);
        self::assertSame('xyz', $r->captureStream());
        self::assertSame('bytes 23-25/26', $r->getHeader('Content-Range'));

        $r = $this->request('GET', $url, [], ['Range' => 'bytes=20-']);
        self::assertSame(206, $r->status);
        self::assertSame('uvwxyz', $r->captureStream());

        $r = $this->request('GET', $url, [], ['Range' => 'bytes=0-99']);
        self::assertSame(206, $r->status);
        self::assertSame(self::ALPHABET, $r->captureStream());
        self::assertSame('bytes 0-25/26', $r->getHeader('Content-Range'));
        self::assertSame(1, $this->downloads($id));

        $r = $this->request('GET', $url, [], ['Range' => 'bytes=100-200']);
        self::assertSame(416, $r->status);
        self::assertSame('bytes */26', $r->getHeader('Content-Range'));

        // Several ranges, or an If-Range for another version of the file: the whole file.
        $r = $this->request('GET', $url, [], ['Range' => 'bytes=0-1,5-6']);
        self::assertSame(200, $r->status);
        self::assertSame(self::ALPHABET, $r->captureStream());
        $r = $this->request('GET', $url, [], ['Range' => 'bytes=5-9', 'If-Range' => '"not-this-file"']);
        self::assertSame(200, $r->status);
        self::assertSame(self::ALPHABET, $r->captureStream());
        $etag = (string) $r->getHeader('ETag');
        $r = $this->request('GET', $url, [], ['Range' => 'bytes=5-9', 'If-Range' => $etag]);
        self::assertSame(206, $r->status);
        self::assertSame('fghij', $r->captureStream());
        self::assertSame(3, $this->downloads($id));
        self::assertSame(3, $this->events($id));
    }

    /** @return iterable<string, array{string, int, array{int, int}|false|null}> */
    public static function ranges(): iterable
    {
        yield 'first bytes' => ['bytes=0-9', 100, [0, 9]];
        yield 'open end' => ['bytes=90-', 100, [90, 99]];
        yield 'end past the file' => ['bytes=90-500', 100, [90, 99]];
        yield 'suffix' => ['bytes=-10', 100, [90, 99]];
        yield 'suffix longer than the file' => ['bytes=-500', 100, [0, 99]];
        yield 'start past the end' => ['bytes=100-', 100, false];
        yield 'empty suffix' => ['bytes=-0', 100, false];
        yield 'reversed' => ['bytes=9-0', 100, null];
        yield 'several' => ['bytes=0-1,4-5', 100, null];
        yield 'other unit' => ['items=0-1', 100, null];
        yield 'nothing' => ['bytes=-', 100, null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ranges')]
    public function testRangeParsing(string $header, int $size, array|false|null $expected): void
    {
        self::assertSame($expected, DownloadController::parseRange($header, $size));
    }

    public function testOnLiteSpeedTheServerSendsTheFileByAnInternalRedirect(): void
    {
        $this->makeUser(self::CLIENT);
        $id = $this->makeRelease(['file_name' => 'StageWatch-2.16.0.exe']);
        $path = (string) $this->db->value('SELECT file_path FROM releases WHERE id = ?', [$id]);
        $this->signInAs(self::CLIENT);

        $r = $this->request('GET', '/portal/downloads/' . $id . '/', server: ['SERVER_SOFTWARE' => 'LiteSpeed']);
        self::assertSame(200, $r->status);
        self::assertFalse($r->isStream());
        self::assertSame('', $r->body);
        self::assertSame('/downloads-vault/' . $path, $r->getHeader('X-LiteSpeed-Location'));
        self::assertNull($r->getHeader('Content-Length'));
        self::assertStringStartsWith('attachment; filename="StageWatch-2.16.0.exe"', (string) $r->getHeader('Content-Disposition'));
        self::assertSame(1, $this->downloads($id));
        self::assertSame(1, $this->events($id));

        // Another server software streams the file itself.
        $r = $this->request('GET', '/portal/downloads/' . $id . '/', server: ['SERVER_SOFTWARE' => 'Apache']);
        self::assertNull($r->getHeader('X-LiteSpeed-Location'));
        self::assertSame(self::ALPHABET, $r->captureStream());
    }

    public function testAMissingOrTamperedFileIsNotFound(): void
    {
        $this->makeUser(self::CLIENT);
        $missing = $this->makeRelease();
        $path = (string) $this->db->value('SELECT file_path FROM releases WHERE id = ?', [$missing]);
        unlink($this->vault . '/' . $path);
        $outside = $this->makeRelease(['file_path' => '../../config/defaults.php']);
        $this->signInAs(self::CLIENT);
        self::assertSame(404, $this->request('GET', '/portal/downloads/' . $missing . '/')->status);
        self::assertSame(404, $this->request('GET', '/portal/downloads/' . $outside . '/')->status);
        self::assertSame(0, $this->downloads($missing) + $this->downloads($outside));
    }

    // ---------------------------------------------------------------------------------------------------------
    // Administering releases

    private function releaseForm(array $over = []): array
    {
        return $over + [
            'product' => 'stagewatch',
            'version' => '2.16.0',
            'channel' => 'stable',
            'title' => 'Faster start-up',
            'notes' => "- **New:** running order import",
            'access' => 'registered',
            'publish' => '1',
        ];
    }

    public function testAnAdministratorUploadsARelease(): void
    {
        $adminId = $this->makeUser(self::ADMIN, 'correct horse battery', 'admin');
        $this->makeUser(self::CLIENT);
        $this->signInAs(self::ADMIN);
        $form = $this->request('GET', '/portal/admin/releases/new/');
        self::assertSame(200, $form->status);
        self::assertStringContainsString('enctype="multipart/form-data"', $form->body);
        self::assertStringContainsString('name="_csrf"', $form->body);

        $bytes = self::zipBytes();
        $r = $this->postFiles('/portal/admin/releases/', $this->releaseForm(), ['file' => [$this->fakeUpload('StageWatch Setup 2.16.0.zip', $bytes)]]);
        self::assertSame(303, $r->status);
        $row = $this->db->one('SELECT * FROM releases');
        self::assertNotNull($row);
        self::assertSame('/portal/admin/releases/' . $row['id'] . '/', $r->getHeader('Location'));
        self::assertSame('StageWatch Setup 2.16.0.zip', $row['file_name']);
        self::assertMatchesRegularExpression('#^[a-f0-9]{32}/StageWatch-Setup-2\.16\.0\.zip$#', (string) $row['file_path']);
        self::assertSame(strlen($bytes), (int) $row['file_size']);
        self::assertSame(hash('sha256', $bytes), $row['sha256']);
        self::assertSame(1, (int) $row['is_published']);
        self::assertNotNull($row['published_at']);
        self::assertSame($adminId, (int) $row['created_by']);
        self::assertSame($bytes, file_get_contents($this->vault . '/' . $row['file_path']));
        self::assertSame('release.created', $this->db->value("SELECT action FROM audit_log WHERE action = 'release.created' AND subject = ?", [(string) $row['id']]));

        $index = $this->request('GET', '/portal/admin/releases/');
        self::assertSame(200, $index->status);
        self::assertStringContainsString('StageWatch 2.16.0', $index->body);

        $this->signInAs(self::CLIENT);
        $download = $this->request('GET', '/portal/downloads/' . $row['id'] . '/');
        self::assertSame(200, $download->status);
        self::assertSame($bytes, $download->captureStream());
    }

    public function testAReleaseIsRefusedWithBadInputOrAFileThatIsNotAllowed(): void
    {
        $this->makeUser(self::ADMIN, 'correct horse battery', 'admin');
        $this->signInAs(self::ADMIN);

        $r = $this->postFiles('/portal/admin/releases/', $this->releaseForm(['channel' => 'nightly', 'access' => 'everyone', 'product' => 'photoshop', 'version' => '../1']), ['file' => [$this->fakeUpload('a.zip', self::zipBytes())]]);
        self::assertSame(303, $r->status);
        self::assertSame('/portal/admin/releases/new/#form', $r->getHeader('Location'));
        foreach (['channel', 'access', 'product', 'version'] as $key) {
            self::assertContains($key, self::errorKeys(), $key);
        }
        $_SESSION['_errors'] = [];

        $r = $this->postFiles('/portal/admin/releases/', $this->releaseForm(), ['file' => [$this->fakeUpload('shell.php', '<?php echo 1;')]]);
        self::assertSame(303, $r->status);
        self::assertSame(['file'], self::errorKeys());
        $_SESSION['_errors'] = [];

        $r = $this->post('/portal/admin/releases/', $this->releaseForm());
        self::assertSame(303, $r->status);
        self::assertSame(['file'], self::errorKeys());

        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM releases'));
        self::assertSame([], glob($this->vault . '/*/*') ?: []);

        // The form shows the errors and keeps what was typed.
        $this->postFiles('/portal/admin/releases/', $this->releaseForm(['channel' => 'nightly', 'title' => 'Kept title']), ['file' => [$this->fakeUpload('a.zip', self::zipBytes())]]);
        $form = $this->request('GET', '/portal/admin/releases/new/')->body;
        self::assertStringContainsString('aria-invalid="true"', $form);
        self::assertStringContainsString('Choose stable or beta.', $form);
        self::assertStringContainsString('value="Kept title"', $form);
    }

    public function testAFileCanBePickedFromTheIncomingFolderButNothingOutsideIt(): void
    {
        $this->makeUser(self::ADMIN, 'correct horse battery', 'admin');
        mkdir($this->vault . '/incoming', 0777, true);
        $bytes = self::zipBytes();
        file_put_contents($this->vault . '/incoming/Patterns 1.4.0.zip', $bytes);
        file_put_contents($this->vault . '/incoming/notes.php', '<?php');
        file_put_contents($this->vault . '/secret.zip', $bytes);
        $this->signInAs(self::ADMIN);

        $form = $this->request('GET', '/portal/admin/releases/new/')->body;
        self::assertStringContainsString('value="Patterns 1.4.0.zip"', $form);
        self::assertStringNotContainsString('notes.php', $form);

        $meta = $this->releaseForm(['product' => 'patterns', 'version' => '1.4.0']);
        foreach (['../../config', '../secret.zip', '../incoming/Patterns 1.4.0.zip', 'notes.php', '.', 'missing.zip', "Patterns 1.4.0.zip\0"] as $attempt) {
            $_SESSION['_errors'] = [];
            $r = $this->post('/portal/admin/releases/', $meta + ['incoming' => $attempt]);
            self::assertSame(303, $r->status, $attempt);
            self::assertSame('/portal/admin/releases/new/#form', $r->getHeader('Location'), $attempt);
            self::assertSame(['incoming'], self::errorKeys(), $attempt);
        }
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM releases'));
        self::assertFileExists($this->vault . '/secret.zip');

        // An upload and a pick together are refused: one file per release.
        $_SESSION['_errors'] = [];
        $this->postFiles('/portal/admin/releases/', $meta + ['incoming' => 'Patterns 1.4.0.zip'], ['file' => [$this->fakeUpload('b.zip', $bytes)]]);
        self::assertSame(['file'], self::errorKeys());

        $r = $this->post('/portal/admin/releases/', array_replace($meta, ['incoming' => 'Patterns 1.4.0.zip', 'publish' => '']));
        self::assertSame(303, $r->status);
        $row = $this->db->one('SELECT * FROM releases');
        self::assertNotNull($row);
        self::assertSame('Patterns 1.4.0.zip', $row['file_name']);
        self::assertMatchesRegularExpression('#^[a-f0-9]{32}/Patterns-1\.4\.0\.zip$#', (string) $row['file_path']);
        self::assertSame(hash('sha256', $bytes), $row['sha256']);
        self::assertSame(0, (int) $row['is_published']);
        self::assertNull($row['published_at']);
        self::assertFileDoesNotExist($this->vault . '/incoming/Patterns 1.4.0.zip');
        self::assertFileExists($this->vault . '/' . $row['file_path']);

        // The same product, version and channel cannot be added twice.
        file_put_contents($this->vault . '/incoming/again.zip', $bytes);
        $_SESSION['_errors'] = [];
        $this->post('/portal/admin/releases/', $meta + ['incoming' => 'again.zip']);
        self::assertSame(['version'], self::errorKeys());
        self::assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM releases'));
    }

    public function testAnAdministratorPublishesUnpublishesEditsAndDeletesARelease(): void
    {
        $adminId = $this->makeUser(self::ADMIN, 'correct horse battery', 'admin');
        $id = $this->makeRelease(['is_published' => 0, 'published_at' => null]);
        $this->db->insert('download_events', ['release_id' => $id, 'user_id' => null, 'ip_hash' => null, 'created_at' => time()]);
        $file = $this->vault . '/' . $this->db->value('SELECT file_path FROM releases WHERE id = ?', [$id]);
        $this->signInAs(self::ADMIN);
        $url = '/portal/admin/releases/' . $id . '/';
        self::assertSame(200, $this->request('GET', $url)->status);

        $this->post($url, ['action' => 'publish']);
        $row = $this->db->one('SELECT * FROM releases WHERE id = ?', [$id]);
        self::assertSame(1, (int) $row['is_published']);
        $firstPublished = (int) $row['published_at'];
        self::assertGreaterThan(0, $firstPublished);

        $this->post($url, ['action' => 'unpublish']);
        $row = $this->db->one('SELECT * FROM releases WHERE id = ?', [$id]);
        self::assertSame(0, (int) $row['is_published']);
        self::assertSame($firstPublished, (int) $row['published_at']);
        $this->db->update('releases', ['published_at' => $firstPublished - 100], ['id' => $id]);
        $this->post($url, ['action' => 'publish']);
        self::assertSame($firstPublished - 100, (int) $this->db->value('SELECT published_at FROM releases WHERE id = ?', [$id]), 'published_at is set the first time only');

        $r = $this->post($url, ['action' => 'save', 'product' => 'stagewatch', 'version' => '2.16.1', 'channel' => 'beta', 'title' => 'Renamed', 'notes' => 'Better', 'access' => 'licensed']);
        self::assertSame(303, $r->status);
        $row = $this->db->one('SELECT * FROM releases WHERE id = ?', [$id]);
        self::assertSame(['2.16.1', 'beta', 'Renamed', 'Better', 'licensed'], [$row['version'], $row['channel'], $row['title'], $row['notes'], $row['access']]);

        $this->post($url, ['action' => 'save', 'product' => 'stagewatch', 'version' => '2.16.1', 'channel' => 'stable', 'title' => 'x', 'access' => 'admin']);
        self::assertSame(['access'], self::errorKeys());
        self::assertSame('licensed', $this->db->value('SELECT access FROM releases WHERE id = ?', [$id]));

        $before = $this->db->one('SELECT * FROM releases WHERE id = ?', [$id]);
        $this->post($url, ['action' => 'destroy']);
        self::assertSame($before, $this->db->one('SELECT * FROM releases WHERE id = ?', [$id]));

        $this->post($url, ['action' => 'delete']);
        self::assertSame(['confirm'], self::errorKeys());
        self::assertFileExists($file);

        $r = $this->post($url, ['action' => 'delete', 'confirm' => '1']);
        self::assertSame(303, $r->status);
        self::assertSame('/portal/admin/releases/', $r->getHeader('Location'));
        self::assertNull($this->db->value('SELECT id FROM releases WHERE id = ?', [$id]));
        self::assertSame(0, $this->events($id));
        self::assertFileDoesNotExist($file);
        self::assertDirectoryDoesNotExist(dirname($file));
        $actions = array_column($this->db->all("SELECT action FROM audit_log WHERE user_id = ? AND action LIKE 'release.%' ORDER BY id", [$adminId]), 'action');
        self::assertSame(['release.published', 'release.unpublished', 'release.published', 'release.updated', 'release.deleted'], $actions);
        self::assertSame(404, $this->request('GET', $url)->status);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Requesting a licence

    public function testTheRequestPageOffersOnlyLiveProductsAndRequestableLicences(): void
    {
        $this->makeUser(self::CLIENT);
        $this->signInAs(self::CLIENT);

        $r = $this->request('GET', '/portal/licences/request/');
        self::assertSame(200, $r->status);
        foreach (['standard', 'pro', 'production', 'event-pass'] as $tier) {
            self::assertStringContainsString('product=stagewatch&amp;tier=' . $tier . '#form', $r->body, $tier);
        }
        foreach (['trial', 'free', 'nonprofit', 'rental'] as $tier) {
            self::assertStringNotContainsString('tier=' . $tier . '#form', $r->body, $tier);
        }
        self::assertStringNotContainsString('product=patterns&amp;tier=', $r->body);
        self::assertStringContainsString('href="/contact/?topic=sales&amp;product=patterns"', $r->body);

        $form = $this->request('GET', '/portal/licences/request/?product=stagewatch&tier=production');
        self::assertSame(200, $form->status);
        self::assertStringContainsString('name="hardware_id_1"', $form->body);
        self::assertStringContainsString('name="hardware_id_2"', $form->body);
        self::assertStringNotContainsString('name="hardware_id_3"', $form->body);
        self::assertStringContainsString('Main PC hardware ID', $form->body);
        self::assertStringContainsString('Backup PC hardware ID', $form->body);
        self::assertStringContainsString('In StageWatch: Help → License → Plans → Copy', $form->body);
        self::assertStringContainsString('name="nonprofit"', $form->body);
        self::assertStringContainsString('name="pack"', $form->body);
        self::assertStringContainsString('name="_csrf"', $form->body);

        $pass = $this->request('GET', '/portal/licences/request/?product=stagewatch&tier=event-pass')->body;
        self::assertStringContainsString('name="hardware_id_1"', $pass);
        self::assertStringNotContainsString('name="hardware_id_2"', $pass);
        self::assertStringNotContainsString('name="pack"', $pass);

        // A tier that cannot be requested, or a product that is not on sale, gets the choice instead of a form.
        foreach (['/portal/licences/request/?product=stagewatch&tier=trial', '/portal/licences/request/?product=patterns&tier=pro'] as $path) {
            $body = $this->request('GET', $path)->body;
            self::assertStringNotContainsString('name="hardware_id_1"', $body, $path);
            self::assertStringContainsString('tier=pro#form', $body, $path);
        }
    }

    /**
     * A launch price reads as the licensing page shows it: the launch price first, then the list price struck through
     * with a hidden "was" for screen readers. Other prices keep their own label.
     */
    public function testALaunchPriceLeadsWithTheListPriceStruckThrough(): void
    {
        $this->makeUser(self::CLIENT);
        $this->signInAs(self::CLIENT);
        $launch = '<strong>£59</strong> <s class="pt-muted"><span class="u-visually-hidden">was </span>£79</s> launch price';

        $form = $this->request('GET', '/portal/licences/request/?product=stagewatch&tier=production')->body;
        self::assertStringContainsString('<dd>' . $launch . '</dd>', $form);
        self::assertStringNotContainsString('Launch price £59', $form, 'the content\'s note is not printed beside the list price');

        $choose = $this->request('GET', '/portal/licences/request/?product=stagewatch')->body;
        self::assertStringContainsString('<span class="pt-card-meta">' . $launch . '</span>', $choose);
        self::assertStringContainsString('<span class="pt-card-meta">£15 for 30 days</span>', $choose, 'a price without a launch offer keeps its label');
        self::assertStringContainsString('<span class="pt-card-meta">£29</span>', $choose);

        $pass = $this->request('GET', '/portal/licences/request/?product=stagewatch&tier=event-pass')->body;
        self::assertStringContainsString('<dd>£15 for 30 days</dd>', $pass);
    }

    public function testALicenceRequestIsValidated(): void
    {
        $this->makeUser(self::CLIENT);
        $this->signInAs(self::CLIENT);
        $cases = [
            'unknown tier' => [['tier' => 'platinum'], 'tier'],
            'tier that is not requestable' => [['tier' => 'trial'], 'tier'],
            'extra that is not requestable' => [['tier' => 'nonprofit'], 'tier'],
            'product not on sale' => [['product' => 'patterns', 'tier' => 'pro'], 'product'],
            'unknown product' => [['product' => 'photoshop'], 'product'],
            'missing hardware ID' => [['hardware_id_1' => ''], 'hardware_id_1'],
            'short hardware ID' => [['hardware_id_1' => 'abc'], 'hardware_id_1'],
            'hardware ID with spaces' => [['hardware_id_1' => 'AB CD EF GH'], 'hardware_id_1'],
            'hardware ID with markup' => [['hardware_id_1' => '<script>alert(1)</script>'], 'hardware_id_1'],
            'hardware ID too long' => [['hardware_id_1' => str_repeat('A', 129)], 'hardware_id_1'],
            'nonprofit without an organisation' => [['nonprofit' => '1'], 'organisation'],
            'nonprofit not a checkbox value' => [['nonprofit' => 'yes'], 'nonprofit'],
            '5-pack on an event pass' => [['tier' => 'event-pass', 'pack' => '1'], 'pack'],
            'note too long' => [['note' => str_repeat('n', 2001)], 'note'],
        ];
        foreach ($cases as $label => [$over, $key]) {
            $_SESSION['_errors'] = [];
            $r = $this->post('/portal/licences/request/', self::standardRequest($over));
            self::assertSame(303, $r->status, $label);
            self::assertContains($key, self::errorKeys(), $label);
        }

        // Production covers a main and a backup PC: two IDs, and two different ones.
        $_SESSION['_errors'] = [];
        $this->post('/portal/licences/request/', self::standardRequest(['tier' => 'production']));
        self::assertSame(['hardware_id_2'], self::errorKeys());
        $_SESSION['_errors'] = [];
        $r = $this->post('/portal/licences/request/', self::standardRequest(['tier' => 'production', 'hardware_id_2' => strtolower('SW-HW-9F2A-77C1')]));
        self::assertSame('/portal/licences/request/?product=stagewatch&tier=production#form', $r->getHeader('Location'));
        self::assertSame(['hardware_id_2'], self::errorKeys());

        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM licences'));
        self::assertSame([], $this->mails());

        // The form shows the error beside the field.
        $form = $this->request('GET', '/portal/licences/request/?product=stagewatch&tier=production')->body;
        self::assertStringContainsString('aria-invalid="true"', $form);
        self::assertStringContainsString('id="f-hardware-id-2-error"', $form);
    }

    public function testAValidRequestIsStoredEmailedAndShownToItsOwnerOnly(): void
    {
        $uid = $this->makeUser(self::CLIENT);
        $this->makeUser(self::OTHER);
        $this->signInAs(self::CLIENT);

        $r = $this->post('/portal/licences/request/', self::standardRequest([
            'tier' => 'production',
            'hardware_id_1' => 'MAIN-PC-0001',
            'hardware_id_2' => 'BACKUP-PC-0002',
            'nonprofit' => '1',
            'organisation' => 'St Mary’s Church',
            'pack' => '1',
            'note' => 'Invoice to **the office**, please.',
        ]));
        self::assertSame(303, $r->status);
        $l = $this->db->one('SELECT * FROM licences');
        self::assertNotNull($l);
        self::assertSame('/portal/licences/' . $l['id'] . '/', $r->getHeader('Location'));
        self::assertSame($uid, (int) $l['user_id']);
        self::assertSame(['stagewatch', 'production', 'requested'], [$l['product'], $l['tier'], $l['status']]);
        self::assertMatchesRegularExpression('/^SW-[A-Z2-7]{6}$/', (string) $l['reference']);
        self::assertSame(['MAIN-PC-0001', 'BACKUP-PC-0002'], json_decode((string) $l['hardware_ids'], true));
        self::assertSame(1, (int) $l['nonprofit']);
        self::assertSame(5, (int) $l['quantity']);
        self::assertStringContainsString('St Mary’s Church', (string) $l['customer_note']);
        self::assertNull($l['issued_at']);
        self::assertSame('licence.requested', $this->db->value("SELECT action FROM audit_log WHERE action = 'licence.requested' AND user_id = ?", [$uid]));

        $admin = $this->lastMailTo('owner@example.test');
        self::assertNotNull($admin);
        self::assertSame('licence-request-admin', $admin['template']);
        self::assertStringContainsString((string) $l['reference'], $admin['subject']);
        self::assertStringContainsString('BACKUP-PC-0002', $admin['html']);
        self::assertStringContainsString('/portal/admin/licences/' . $l['id'] . '/', $admin['html']);
        $receipt = $this->lastMailTo(self::CLIENT);
        self::assertNotNull($receipt);
        self::assertSame('licence-request-receipt', $receipt['template']);
        self::assertStringContainsString((string) $l['reference'], $receipt['html']);
        self::assertStringContainsString('We will email you the payment details', $receipt['html']);
        self::assertStringContainsString('/portal/licences/' . $l['id'] . '/', $receipt['html']);

        $show = $this->request('GET', '/portal/licences/' . $l['id'] . '/');
        self::assertSame(200, $show->status);
        self::assertStringContainsString((string) $l['reference'], $show->body);
        self::assertStringContainsString('MAIN-PC-0001', $show->body);
        self::assertStringContainsString('<strong>the office</strong>', $show->body);
        $index = $this->request('GET', '/portal/licences/');
        self::assertStringContainsString((string) $l['reference'], $index->body);
        self::assertStringContainsString('pt-badge--requested', $index->body);

        $this->signInAs(self::OTHER);
        self::assertSame(404, $this->request('GET', '/portal/licences/' . $l['id'] . '/')->status);
        self::assertStringNotContainsString((string) $l['reference'], $this->request('GET', '/portal/licences/')->body);
    }

    public function testPaymentLinksAreSentWhenConfiguredAndOnlyAtTheStandardPrice(): void
    {
        $this->boot(['licensing' => ['payment_mode' => 'links', 'payment_links' => ['stagewatch.pro' => 'https://pay.example.test/stagewatch-pro', 'stagewatch.standard' => 'javascript:alert(1)']]]);
        $this->makeUser(self::CLIENT);
        $this->signInAs(self::CLIENT);

        $this->post('/portal/licences/request/', self::standardRequest(['tier' => 'pro']));
        $receipt = $this->lastMailTo(self::CLIENT);
        self::assertStringContainsString('https://pay.example.test/stagewatch-pro', (string) $receipt['html']);
        $id = (int) $this->db->value("SELECT id FROM licences WHERE tier = 'pro'");
        self::assertStringContainsString('href="https://pay.example.test/stagewatch-pro"', $this->request('GET', '/portal/licences/' . $id . '/')->body);

        // Nonprofit pricing differs from the link's price, and a link that is not http(s) is never used.
        $this->post('/portal/licences/request/', self::standardRequest(['tier' => 'pro', 'hardware_id_1' => 'OTHER-PC-77', 'nonprofit' => '1', 'organisation' => 'A School']));
        $receipt = $this->lastMailTo(self::CLIENT);
        self::assertStringNotContainsString('pay.example.test', (string) $receipt['html']);
        self::assertStringContainsString('We will email you the payment details', (string) $receipt['html']);
        $this->post('/portal/licences/request/', self::standardRequest());
        self::assertStringNotContainsString('javascript:', (string) $this->lastMailTo(self::CLIENT)['html']);
    }

    public function testLicenceRequestsAreLimitedPerDay(): void
    {
        $this->makeUser(self::CLIENT);
        $this->signInAs(self::CLIENT);
        for ($i = 1; $i <= 10; $i++) {
            $r = $this->post('/portal/licences/request/', self::standardRequest(['hardware_id_1' => 'PC-NUMBER-' . $i]));
            self::assertSame('/portal/licences/', substr((string) $r->getHeader('Location'), 0, 17), 'request ' . $i);
        }
        $r = $this->post('/portal/licences/request/', self::standardRequest(['hardware_id_1' => 'PC-NUMBER-11']));
        self::assertSame('/portal/licences/request/?product=stagewatch&tier=standard#form', $r->getHeader('Location'));
        self::assertSame(10, (int) $this->db->value('SELECT COUNT(*) FROM licences'));
        self::assertSame(10, count(array_unique(array_column($this->db->all('SELECT reference FROM licences'), 'reference'))));
    }

    // ---------------------------------------------------------------------------------------------------------
    // Administering licences

    public function testAnAdministratorIssuesALicenceWithADatFileAndOnlyItsOwnerCanDownloadIt(): void
    {
        $owner = $this->makeUser(self::CLIENT);
        $this->makeUser(self::OTHER);
        $adminId = $this->makeUser(self::ADMIN, 'correct horse battery', 'admin');
        $id = $this->makeLicence($owner, ['tier' => 'production', 'hardware_ids' => json_encode(['MAIN-PC-0001', 'BACKUP-PC-0002'])]);
        $url = '/portal/admin/licences/' . $id . '/';
        $this->signInAs(self::ADMIN);

        $page = $this->request('GET', $url);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('href="/portal/admin/users/' . $owner . '/"', $page->body);
        self::assertStringContainsString('BACKUP-PC-0002', $page->body);

        // Issuing needs a file.
        $this->post($url, ['action' => 'set_status', 'status' => 'issued']);
        self::assertSame(['files'], self::errorKeys());
        self::assertSame('requested', $this->db->value('SELECT status FROM licences WHERE id = ?', [$id]));

        // A file is stored while the licence waits for payment; the client cannot download it yet.
        $_SESSION['_errors'] = [];
        $this->postFiles($url, ['action' => 'set_status', 'status' => 'awaiting_payment', 'message' => 'Bank details: see invoice 42.'], ['files' => [$this->fakeUpload('StageWatch Production.dat', 'LICENCE-DATA-1234')]]);
        self::assertSame([], self::errorKeys());
        $l = $this->db->one('SELECT * FROM licences WHERE id = ?', [$id]);
        self::assertSame('awaiting_payment', $l['status']);
        self::assertNull($l['issued_at']);
        $file = $this->db->one('SELECT * FROM licence_files WHERE licence_id = ?', [$id]);
        self::assertNotNull($file);
        self::assertSame('StageWatch Production.dat', $file['original_name']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.dat$/', (string) $file['stored_name']);
        self::assertFileExists($this->storage . '/licences/' . $file['stored_name']);
        $status = $this->lastMailTo(self::CLIENT);
        self::assertSame('licence-status', $status['template']);
        self::assertStringContainsString('Bank details: see invoice 42.', $status['html']);
        $fileUrl = '/portal/licences/' . $id . '/files/' . $file['id'] . '/';

        $this->signInAs(self::CLIENT);
        self::assertSame(404, $this->request('GET', $fileUrl)->status);
        self::assertStringNotContainsString($fileUrl, $this->request('GET', '/portal/licences/' . $id . '/')->body);

        $this->signInAs(self::ADMIN);
        $r = $this->post($url, ['action' => 'set_status', 'status' => 'issued']);
        self::assertSame(303, $r->status);
        $l = $this->db->one('SELECT * FROM licences WHERE id = ?', [$id]);
        self::assertSame('issued', $l['status']);
        self::assertGreaterThan(0, (int) $l['issued_at']);
        $issued = $this->lastMailTo(self::CLIENT);
        self::assertSame('licence-issued', $issued['template']);
        self::assertStringContainsString('https://patternsshowcontrol.test/portal/licences/' . $id . '/', $issued['html']);
        self::assertSame(['from' => 'awaiting_payment', 'to' => 'issued', 'message' => false], json_decode((string) $this->db->value("SELECT meta FROM audit_log WHERE action = 'licence.status' AND user_id = ? ORDER BY id DESC LIMIT 1", [$adminId]), true));

        $this->signInAs(self::CLIENT);
        self::assertStringContainsString('href="' . $fileUrl . '"', $this->request('GET', '/portal/licences/')->body);
        self::assertStringContainsString('href="' . $fileUrl . '"', $this->request('GET', '/portal/licences/' . $id . '/')->body);
        $download = $this->request('GET', $fileUrl);
        self::assertSame(200, $download->status);
        self::assertSame('LICENCE-DATA-1234', $download->captureStream());
        self::assertStringStartsWith('attachment; filename="StageWatch Production.dat"', (string) $download->getHeader('Content-Disposition'));
        self::assertSame(404, $this->request('GET', '/portal/licences/' . $id . '/files/' . ($file['id'] + 1) . '/')->status);

        $this->signInAs(self::OTHER);
        self::assertSame(404, $this->request('GET', $fileUrl)->status);
        self::assertSame(404, $this->request('GET', '/portal/licences/' . $id . '/')->status);

        // Revoked: the owner can no longer download the file.
        $this->signInAs(self::ADMIN);
        $this->post($url, ['action' => 'set_status', 'status' => 'revoked', 'message' => 'Refunded at your request.']);
        $this->signInAs(self::CLIENT);
        self::assertSame(404, $this->request('GET', $fileUrl)->status);
    }

    public function testStatusChangesAreValidatedAndAnUnchangedStatusIsRefused(): void
    {
        $owner = $this->makeUser(self::CLIENT);
        $this->makeUser(self::ADMIN, 'correct horse battery', 'admin');
        $id = $this->makeLicence($owner);
        $url = '/portal/admin/licences/' . $id . '/';
        $this->signInAs(self::ADMIN);

        foreach (['paid', 'requested', ''] as $bad) {
            $_SESSION['_errors'] = [];
            $this->post($url, ['action' => 'set_status', 'status' => $bad]);
            self::assertSame(['status'], self::errorKeys(), $bad);
        }
        $before = $this->db->one('SELECT * FROM licences WHERE id = ?', [$id]);
        $this->post($url, ['action' => 'rename', 'status' => 'revoked']);
        self::assertSame($before, $this->db->one('SELECT * FROM licences WHERE id = ?', [$id]));
        self::assertSame([], $this->mails());
        self::assertSame(404, $this->post('/portal/admin/licences/' . ($id + 50) . '/', ['action' => 'set_status', 'status' => 'revoked'])->status);
    }

    public function testLicenceFilesAndDetails(): void
    {
        $owner = $this->makeUser(self::CLIENT);
        $adminId = $this->makeUser(self::ADMIN, 'correct horse battery', 'admin');
        $id = $this->makeLicence($owner, ['status' => 'issued', 'issued_at' => time()]);
        $other = $this->makeLicence($owner);
        $url = '/portal/admin/licences/' . $id . '/';
        $this->signInAs(self::ADMIN);

        $this->postFiles($url, ['action' => 'upload'], ['files' => [$this->fakeUpload('setup.exe', 'MZ' . str_repeat("\0", 100))]]);
        self::assertSame(['files'], self::errorKeys());
        $this->postFiles($url, ['action' => 'upload'], ['files' => [$this->fakeUpload('big.lic', str_repeat('L', 1_048_577))]]);
        self::assertSame(['files'], self::errorKeys());
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM licence_files'));

        $_SESSION['_errors'] = [];
        $this->postFiles($url, ['action' => 'upload', 'notify' => '1'], ['files' => [$this->fakeUpload('main.lic', 'MAIN'), $this->fakeUpload('backup.txt', 'BACKUP')]]);
        self::assertSame([], self::errorKeys());
        $files = $this->db->all('SELECT * FROM licence_files WHERE licence_id = ? ORDER BY id', [$id]);
        self::assertSame(['main.lic', 'backup.txt'], array_column($files, 'original_name'));
        self::assertSame('licence-issued', $this->lastMailTo(self::CLIENT)['template']);

        // Another licence's file cannot be deleted from this one.
        $foreign = $this->db->insert('licence_files', ['licence_id' => $other, 'original_name' => 'x.dat', 'stored_name' => str_repeat('a', 32) . '.dat', 'size' => 1, 'created_at' => time()]);
        $this->post($url, ['action' => 'delete_file', 'file_id' => (string) $foreign]);
        self::assertNotNull($this->db->value('SELECT id FROM licence_files WHERE id = ?', [$foreign]));

        $stored = $this->storage . '/licences/' . $files[0]['stored_name'];
        self::assertFileExists($stored);
        $this->post($url, ['action' => 'delete_file', 'file_id' => (string) $files[0]['id']]);
        self::assertNull($this->db->value('SELECT id FROM licence_files WHERE id = ?', [(int) $files[0]['id']]));
        self::assertFileDoesNotExist($stored);

        $_SESSION['_errors'] = [];
        $this->post($url, ['action' => 'details', 'updates_until' => '2027-02-30', 'expires_at' => 'next week', 'admin_note' => '']);
        self::assertSame(['updates_until', 'expires_at'], self::errorKeys());
        $_SESSION['_errors'] = [];
        $this->post($url, ['action' => 'details', 'updates_until' => '2027-10-20', 'expires_at' => '', 'admin_note' => 'Paid by bank transfer.']);
        self::assertSame([], self::errorKeys());
        $l = $this->db->one('SELECT * FROM licences WHERE id = ?', [$id]);
        self::assertSame('2027-10-20', date('Y-m-d', (int) $l['updates_until']));
        self::assertNull($l['expires_at']);
        self::assertSame('Paid by bank transfer.', $l['admin_note']);

        // The client sees the dates, never the administrators' note.
        $this->signInAs(self::CLIENT);
        $page = $this->request('GET', '/portal/licences/' . $id . '/')->body;
        self::assertStringContainsString('datetime="2027-10-20', $page);
        self::assertStringNotContainsString('Paid by bank transfer.', $page);

        $actions = array_column($this->db->all("SELECT action FROM audit_log WHERE user_id = ? AND action LIKE 'licence.%' ORDER BY id", [$adminId]), 'action');
        self::assertSame(['licence.file_added', 'licence.file_deleted', 'licence.details'], $actions);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Every page and email renders

    private static function assertCleanPage(Response $r, string $label): void
    {
        self::assertSame(200, $r->status, $label);
        self::assertDoesNotMatchRegularExpression('/\b(Warning|Notice|Deprecated|Fatal error)\b:/', $r->body, $label);
        self::assertSame(1, substr_count($r->body, '<h1'), $label . ': one h1');
        self::assertStringNotContainsString(' style="', $r->body, $label . ': no inline styles');
        self::assertDoesNotMatchRegularExpression('#<script(?![^>]*\\ssrc=)(?![^>]*application/ld\\+json)#', $r->body, $label . ': no inline script');
    }

    public function testEveryPageRendersCleanlyInEveryState(): void
    {
        $owner = $this->makeUser(self::CLIENT);
        $this->makeUser(self::ADMIN, 'correct horse battery', 'admin');

        // Empty states first.
        $this->signInAs(self::CLIENT);
        self::assertCleanPage($this->request('GET', '/portal/downloads/'), 'downloads, empty');
        self::assertCleanPage($this->request('GET', '/portal/licences/'), 'licences, empty');
        $this->signInAs(self::ADMIN);
        self::assertCleanPage($this->request('GET', '/portal/admin/releases/'), 'admin releases, empty');
        self::assertCleanPage($this->request('GET', '/portal/admin/licences/'), 'admin licences, empty');
        self::assertCleanPage($this->request('GET', '/portal/admin/licences/?status=issued&q=nobody'), 'admin licences, filtered to nothing');

        $release = $this->makeRelease();
        $this->makeRelease(['version' => '2.15.0', 'access' => 'licensed', 'notes' => null, 'published_at' => time() - 600]);
        $draft = $this->makeRelease(['version' => '2.17.0', 'is_published' => 0, 'published_at' => null]);
        $missing = $this->makeRelease(['version' => '2.14.0', 'file_path' => str_repeat('b', 32) . '/gone.exe']);
        mkdir($this->vault . '/incoming', 0777, true);
        file_put_contents($this->vault . '/incoming/next.zip', self::zipBytes());
        $requested = $this->makeLicence($owner, ['customer_note' => "Organisation: A School\n\nThanks"]);
        $issued = $this->makeLicence($owner, ['status' => 'issued', 'issued_at' => time(), 'tier' => 'production', 'hardware_ids' => json_encode(['MAIN-PC-0001', 'BACKUP-PC-0002']), 'quantity' => 5, 'nonprofit' => 1, 'updates_until' => time() + 86400 * 365, 'expires_at' => time() + 86400 * 30]);
        $this->db->insert('licence_files', ['licence_id' => $issued, 'original_name' => 'licence.dat', 'stored_name' => str_repeat('c', 32) . '.dat', 'size' => 12, 'created_at' => time()]);
        $this->makeLicence($owner, ['status' => 'revoked', 'tier' => 'retired-tier']);

        foreach ([
            '/portal/admin/releases/' => 'admin releases',
            '/portal/admin/releases/new/' => 'add a release',
            '/portal/admin/releases/' . $release . '/' => 'edit a published release',
            '/portal/admin/releases/' . $draft . '/' => 'edit a draft',
            '/portal/admin/releases/' . $missing . '/' => 'edit a release whose file is missing',
            '/portal/admin/licences/' => 'admin licences',
            '/portal/admin/licences/' . $requested . '/' => 'admin licence, requested',
            '/portal/admin/licences/' . $issued . '/' => 'admin licence, issued',
            '/portal/licences/' . $requested . '/' => 'client view as admin',
            '/portal/downloads/' => 'downloads as admin',
        ] as $path => $label) {
            self::assertCleanPage($this->request('GET', $path), $label);
        }
        self::assertStringContainsString('missing from the downloads folder', $this->request('GET', '/portal/admin/releases/' . $missing . '/')->body);

        // A failed form shows its errors on the next view.
        $this->post('/portal/admin/licences/' . $requested . '/', ['action' => 'details', 'updates_until' => 'soon']);
        $page = $this->request('GET', '/portal/admin/licences/' . $requested . '/');
        self::assertCleanPage($page, 'admin licence with an error');
        self::assertStringContainsString('aria-invalid="true"', $page->body);

        $this->signInAs(self::CLIENT);
        foreach ([
            '/portal/downloads/' => 'downloads',
            '/portal/licences/' => 'licences',
            '/portal/licences/request/' => 'choose a licence',
            '/portal/licences/request/?product=stagewatch' => 'choose a StageWatch licence',
            '/portal/licences/request/?product=stagewatch&tier=bogus' => 'choose, after an unknown tier',
            '/portal/licences/request/?product=stagewatch&tier=standard' => 'request Standard',
            '/portal/licences/request/?product=stagewatch&tier=production' => 'request Production',
            '/portal/licences/request/?product=stagewatch&tier=event-pass' => 'request an event pass',
            '/portal/licences/' . $requested . '/' => 'a requested licence',
            '/portal/licences/' . $issued . '/' => 'an issued licence',
        ] as $path => $label) {
            self::assertCleanPage($this->request('GET', $path), $label);
        }

        // Every email this unit sends renders without errors.
        $this->post('/portal/licences/request/', self::standardRequest());
        $this->signInAs(self::ADMIN);
        $new = (int) $this->db->value('SELECT MAX(id) FROM licences');
        $this->post('/portal/admin/licences/' . $new . '/', ['action' => 'set_status', 'status' => 'awaiting_payment', 'message' => 'Details follow.']);
        $this->postFiles('/portal/admin/licences/' . $new . '/', ['action' => 'set_status', 'status' => 'issued'], ['files' => [$this->fakeUpload('l.dat', 'DATA')]]);
        $templates = array_column($this->mails(), 'template');
        foreach (['licence-request-admin', 'licence-request-receipt', 'licence-status', 'licence-issued'] as $template) {
            self::assertContains($template, $templates);
        }
        foreach ($this->mails() as $m) {
            self::assertDoesNotMatchRegularExpression('/\b(Warning|Notice|Deprecated|Fatal error)\b:/', $m['html'], $m['template']);
        }
    }

    public function testTheAdminListFiltersBySearchesAndIgnoresUnknownFilters(): void
    {
        $a = $this->makeUser('alice@example.test');
        $b = $this->makeUser('bob@example.test');
        $this->makeUser(self::ADMIN, 'correct horse battery', 'admin');
        $this->makeLicence($a, ['reference' => 'SW-AAAAAA', 'status' => 'issued']);
        $this->makeLicence($b, ['reference' => 'SW-BBBBBB', 'status' => 'requested']);
        $this->makeLicence($b, ['reference' => 'PT-CCCCCC', 'product' => 'patterns', 'status' => 'requested']);
        $this->signInAs(self::ADMIN);

        $all = $this->request('GET', '/portal/admin/licences/')->body;
        foreach (['SW-AAAAAA', 'SW-BBBBBB', 'PT-CCCCCC'] as $ref) {
            self::assertStringContainsString($ref, $all);
        }
        self::assertStringContainsString('href="/portal/admin/users/' . $a . '/"', $all);

        $issued = $this->request('GET', '/portal/admin/licences/?status=issued')->body;
        self::assertStringContainsString('SW-AAAAAA', $issued);
        self::assertStringNotContainsString('SW-BBBBBB', $issued);

        $bob = $this->request('GET', '/portal/admin/licences/?q=bob%40example')->body;
        self::assertStringContainsString('SW-BBBBBB', $bob);
        self::assertStringContainsString('PT-CCCCCC', $bob);
        self::assertStringNotContainsString('SW-AAAAAA', $bob);

        $ref = $this->request('GET', '/portal/admin/licences/?q=SW-AAAA&product=stagewatch')->body;
        self::assertStringContainsString('SW-AAAAAA', $ref);
        self::assertStringNotContainsString('SW-BBBBBB', $ref);

        $bogus = $this->request('GET', '/portal/admin/licences/?status=paid&product=photoshop');
        self::assertSame(200, $bogus->status);
        self::assertStringContainsString('PT-CCCCCC', $bogus->body);
        self::assertStringContainsString('SW-AAAAAA', $bogus->body);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Regressions found in review

    /** Regression: a name starting with "+" or holding ".." was stored under a vault path the server refused to serve. */
    public function testEveryUploadedFileNameGetsAVaultPathThatCanBeServed(): void
    {
        $this->makeUser(self::ADMIN, 'correct horse battery', 'admin');
        $this->makeUser(self::CLIENT);
        $names = ['+Setup.zip', 'a..b.zip', 'Patterns v2 .. 16.zip', str_repeat('a', 119) . '..x.zip', 'ü.zip', '-_-.zip', 'Setup (2.16.0) [final].ZIP'];
        $bytes = self::zipBytes();
        $ids = [];
        $this->signInAs(self::ADMIN);
        foreach ($names as $i => $name) {
            $_SESSION['_errors'] = [];
            $r = $this->postFiles('/portal/admin/releases/', $this->releaseForm(['version' => '3.0.' . $i]), ['file' => [$this->fakeUpload($name, $bytes)]]);
            self::assertSame(303, $r->status, $name);
            self::assertSame([], self::errorKeys(), $name);
            $row = $this->db->one('SELECT * FROM releases WHERE version = ?', ['3.0.' . $i]);
            self::assertNotNull($row, $name);
            self::assertMatchesRegularExpression(DownloadController::PATH_PATTERN, (string) $row['file_path'], $name);
            self::assertStringNotContainsString('..', (string) $row['file_path'], $name);
            self::assertNotNull(DownloadController::resolveVaultFile($this->app, (string) $row['file_path']), $name);
            $ids[$name] = (int) $row['id'];
        }
        $this->signInAs(self::CLIENT);
        foreach ($ids as $name => $id) {
            $r = $this->request('GET', '/portal/downloads/' . $id . '/');
            self::assertSame(200, $r->status, $name);
            self::assertSame($bytes, $r->captureStream(), $name);
        }
        foreach (['' => 'release', '...' => 'release', 'a.b.c' => 'a.b.c', 'x..' => 'x', '+x+.zip' => 'x.zip', 'A--B__C.msi' => 'A-B__C.msi', "tab\tname.exe" => 'tab-name.exe'] as $in => $out) {
            self::assertSame($out, AdminReleaseController::vaultName($in), var_export($in, true));
        }
    }

    /** Regression: only the button was disabled, so a POST could publish a release whose file is not in the vault. */
    public function testAReleaseWhoseFileIsMissingCannotBePublished(): void
    {
        $this->makeUser(self::ADMIN, 'correct horse battery', 'admin');
        $id = $this->makeRelease(['is_published' => 0, 'published_at' => null]);
        $file = $this->vault . '/' . $this->db->value('SELECT file_path FROM releases WHERE id = ?', [$id]);
        rename($file, $file . '.moved');
        $this->signInAs(self::ADMIN);
        $url = '/portal/admin/releases/' . $id . '/';

        $r = $this->post($url, ['action' => 'publish']);
        self::assertSame(303, $r->status);
        self::assertSame($url, $r->getHeader('Location'));
        self::assertSame(0, (int) $this->db->value('SELECT is_published FROM releases WHERE id = ?', [$id]));
        self::assertNull($this->db->value('SELECT published_at FROM releases WHERE id = ?', [$id]));
        self::assertNull($this->db->value("SELECT id FROM audit_log WHERE action = 'release.published'"));
        $flashes = array_column($_SESSION['_flash'] ?? [], 'type');
        self::assertContains('danger', $flashes);

        rename($file . '.moved', $file);
        $this->post($url, ['action' => 'publish']);
        self::assertSame(1, (int) $this->db->value('SELECT is_published FROM releases WHERE id = ?', [$id]));
    }

    /** Regression: a HEAD request for a licence file was written to the audit trail as a download. */
    public function testAHeadRequestForALicenceFileIsNotAuditedAsADownload(): void
    {
        $owner = $this->makeUser(self::CLIENT);
        $id = $this->makeLicence($owner, ['status' => 'issued', 'issued_at' => time()]);
        $stored = str_repeat('e', 32) . '.dat';
        file_put_contents($this->app->storage->dir('licences') . '/' . $stored, 'LICENCE');
        $fileId = $this->db->insert('licence_files', ['licence_id' => $id, 'original_name' => 'licence.dat', 'stored_name' => $stored, 'size' => 7, 'created_at' => time()]);
        $url = '/portal/licences/' . $id . '/files/' . $fileId . '/';
        $this->signInAs(self::CLIENT);
        $head = $this->request('HEAD', $url);
        self::assertSame(200, $head->status);
        self::assertSame('7', $head->getHeader('Content-Length'));
        self::assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'licence.file_downloaded'"));

        $get = $this->request('GET', $url);
        self::assertSame('LICENCE', $get->captureStream());
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'licence.file_downloaded'"));
    }

    /** Regression: any client could inflate a count and grow download_events without bound with 1-byte ranges. */
    public function testOneAccountsCountedDownloadsAreCappedButTheFileIsStillServed(): void
    {
        $this->makeUser(self::CLIENT);
        $this->makeUser(self::OTHER);
        $id = $this->makeRelease();
        $url = '/portal/downloads/' . $id . '/';
        $this->signInAs(self::CLIENT);
        for ($i = 1; $i <= 32; $i++) {
            $r = $this->request('GET', $url, [], ['Range' => 'bytes=0-0']);
            self::assertSame(206, $r->status, 'request ' . $i);
            self::assertSame('a', $r->captureStream(), 'request ' . $i);
        }
        self::assertSame(30, $this->downloads($id));
        self::assertSame(30, $this->events($id));

        // The cap is per account: another client's download still counts.
        $this->signInAs(self::OTHER);
        self::assertSame(self::ALPHABET, $this->request('GET', $url)->captureStream());
        self::assertSame(31, $this->downloads($id));
    }

    /** Regression: an incoming/ folder that was a link let a pick list, and move, files from outside the vault. */
    public function testTheIncomingFolderMayNotBeALinkOutOfTheVault(): void
    {
        $this->makeUser(self::ADMIN, 'correct horse battery', 'admin');
        $outside = sys_get_temp_dir() . '/psc-outside-' . bin2hex(random_bytes(6));
        mkdir($outside, 0777, true);
        file_put_contents($outside . '/elsewhere.zip', self::zipBytes());
        try {
            symlink($outside, $this->vault . '/incoming');
            $this->signInAs(self::ADMIN);
            $form = $this->request('GET', '/portal/admin/releases/new/');
            self::assertSame(200, $form->status);
            self::assertStringNotContainsString('elsewhere.zip', $form->body);

            $r = $this->post('/portal/admin/releases/', $this->releaseForm(['incoming' => 'elsewhere.zip']));
            self::assertSame(303, $r->status);
            self::assertSame(['incoming'], self::errorKeys());
            self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM releases'));
            self::assertFileExists($outside . '/elsewhere.zip');
        } finally {
            @unlink($this->vault . '/incoming');
            self::rmrf($outside);
        }
    }

    /** Regression: the release's events were deleted outside a transaction, so a failed delete lost its history. */
    public function testAFailedReleaseDeleteKeepsTheReleaseItsHistoryAndItsFile(): void
    {
        $this->makeUser(self::ADMIN, 'correct horse battery', 'admin');
        $id = $this->makeRelease();
        $this->db->insert('download_events', ['release_id' => $id, 'user_id' => null, 'ip_hash' => null, 'created_at' => time()]);
        $file = $this->vault . '/' . $this->db->value('SELECT file_path FROM releases WHERE id = ?', [$id]);
        $this->refuseWrites('keep_releases', 'DELETE', 'releases');
        $this->signInAs(self::ADMIN);

        $r = $this->post('/portal/admin/releases/' . $id . '/', ['action' => 'delete', 'confirm' => '1']);
        self::assertSame(500, $r->status);
        self::assertNotNull($this->db->value('SELECT id FROM releases WHERE id = ?', [$id]));
        self::assertSame(1, $this->events($id));
        self::assertFileExists($file);
    }

    /** Regression: licence files stored before a failed database write stayed on disk with no row pointing to them. */
    public function testLicenceFilesAreRemovedFromDiskWhenTheirRowsCannotBeWritten(): void
    {
        $owner = $this->makeUser(self::CLIENT);
        $this->makeUser(self::ADMIN, 'correct horse battery', 'admin');
        $id = $this->makeLicence($owner, ['status' => 'issued', 'issued_at' => time()]);
        $this->refuseWrites('refuse_files', 'INSERT', 'licence_files');
        $this->signInAs(self::ADMIN);
        $url = '/portal/admin/licences/' . $id . '/';

        $r = $this->postFiles($url, ['action' => 'upload'], ['files' => [$this->fakeUpload('a.dat', 'A'), $this->fakeUpload('b.lic', 'B')]]);
        self::assertSame(500, $r->status);
        $r = $this->postFiles($url, ['action' => 'set_status', 'status' => 'revoked'], ['files' => [$this->fakeUpload('c.dat', 'C')]]);
        self::assertSame(500, $r->status);
        self::assertSame('issued', $this->db->value('SELECT status FROM licences WHERE id = ?', [$id]));
        self::assertSame([], glob($this->storage . '/licences/*') ?: []);
    }

    /** Regression: the upload control and the access radios were not tied to their error messages. */
    public function testReleaseFormErrorsAreTiedToTheirControls(): void
    {
        $this->makeUser(self::ADMIN, 'correct horse battery', 'admin');
        $this->signInAs(self::ADMIN);
        $this->post('/portal/admin/releases/', $this->releaseForm(['access' => 'everyone']));
        $form = $this->request('GET', '/portal/admin/releases/new/')->body;
        self::assertMatchesRegularExpression('/<input type="file" id="f-file" name="file"[^>]* aria-invalid="true" aria-describedby="f-file-help f-file-error">/', $form);
        self::assertStringContainsString('id="f-file-error"', $form);
        self::assertMatchesRegularExpression('/<input type="radio" id="f-access-registered"[^>]* aria-describedby="f-access-help f-access-error" aria-invalid="true">/', $form);
        self::assertStringContainsString('id="f-access-error"', $form);
        self::assertStringContainsString('id="f-access-help"', $form);
    }
}
