<?php

declare(strict_types=1);

namespace Psc\Controllers\Admin;

use Psc\Controllers\Controller;
use Psc\Controllers\Portal\DownloadController;
use Psc\Db\Database;
use Psc\Http\Request;
use Psc\Http\Response;
use Psc\Support\Audit;
use Psc\Support\Uploads;

/**
 * Releases, for administrators (the routes carry the admin middleware): the list with drafts and counts, adding a
 * release, and editing, publishing, unpublishing or deleting one.
 *
 * A release's file either arrives with the form or is picked from the vault's incoming/ folder, where an
 * administrator put it by FTP or the hosting File Manager (the way to add files larger than an upload allows).
 * Either way it is moved into "<vault>/<32 random hex>/<file name>", and its size and SHA-256 are recorded. A
 * picked name must be a plain file listed directly in incoming/: no path, no link, no climbing out.
 */
final class AdminReleaseController extends Controller
{
    public const EXTENSIONS = ['exe', 'msi', 'zip', 'tgz', 'gz', 'pdf'];

    private const ACTIONS = ['save', 'publish', 'unpublish', 'delete'];

    private const VERSION_PATTERN = '/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,39}$/';

    public function index(Request $request): Response
    {
        $db = $this->app->db();
        $releases = $db->all('SELECT id, product, version, channel, title, file_name, file_size, access, is_published, download_count, published_at, created_at, updated_at'
            . ' FROM releases ORDER BY COALESCE(published_at, created_at) DESC, id DESC');
        $stats = $db->one('SELECT COUNT(*) AS total, COALESCE(SUM(is_published), 0) AS published, COALESCE(SUM(download_count), 0) AS downloads FROM releases') ?? [];
        return $this->portal('admin/releases/index', [
            'releases' => $releases,
            'counts' => [
                'total' => (int) ($stats['total'] ?? 0),
                'published' => (int) ($stats['published'] ?? 0),
                'drafts' => (int) ($stats['total'] ?? 0) - (int) ($stats['published'] ?? 0),
                'downloads' => (int) ($stats['downloads'] ?? 0),
            ],
            'products' => DownloadController::productNames(),
            'channels' => DownloadController::CHANNELS,
            'access' => DownloadController::ACCESS,
        ], 'Releases');
    }

    public function create(Request $request): Response
    {
        return $this->portal('admin/releases/create', [
            'products' => DownloadController::productNames(),
            'channels' => DownloadController::CHANNELS,
            'access' => DownloadController::ACCESS,
            'incoming' => $this->incomingFiles(),
            'extensions' => self::EXTENSIONS,
            'maxBytes' => $this->maxUploadBytes(),
            'errors' => $this->takeErrors(),
            'old' => $this->app->session()->takeOld(),
        ], 'Add a release');
    }

    public function store(Request $request): Response
    {
        $admin = $this->currentUser();
        $form = '/portal/admin/releases/new/#form';
        [$data, $errors] = $this->validateMeta($request, null);
        $publish = $request->input('publish');
        if (!in_array($publish, ['', '1'], true)) {
            $errors['publish'] = 'Tick the box to publish now, or leave it clear to save a draft.';
        }

        $uploads = Uploads::files(self::fileEntry($request, 'file'));
        $picked = $request->input('incoming');
        $source = null;
        if (count($uploads) > 1) {
            $errors['file'] = 'Add one file per release.';
        } elseif ($uploads !== [] && $picked !== '') {
            $errors['file'] = 'Choose one: upload a file, or pick one from the incoming folder.';
        } elseif ($uploads === [] && $picked === '') {
            $errors['file'] = 'Upload the release file, or pick one from the incoming folder.';
        } elseif ($picked !== '') {
            $source = $this->incomingPath($picked);
            if ($source === null) {
                $errors['incoming'] = 'Pick a file from the list. Only files placed directly in the incoming folder can be used.';
            }
        }
        if ($errors !== []) {
            return $this->backWithErrors($form, $request, $errors);
        }

        $folder = bin2hex(random_bytes(16));
        $dir = DownloadController::vaultRoot($this->app) . '/' . $folder;
        $moved = $uploads !== [] ? $this->moveUpload($uploads[0], $dir) : $this->moveIncoming((string) $source, $dir);
        if (!$moved['ok']) {
            return $this->backWithErrors($form, $request, [$uploads !== [] ? 'file' : 'incoming' => $moved['error']]);
        }
        $path = $moved['path'];
        $relative = $folder . '/' . basename($path);
        // A release is only ever saved with a file the downloads page can serve.
        if (DownloadController::resolveVaultFile($this->app, $relative) === null) {
            @unlink($path);
            @rmdir($dir);
            return $this->backWithErrors($form, $request, [$uploads !== [] ? 'file' : 'incoming' => 'The file could not be stored. Please try again.']);
        }
        $sha = (string) hash_file('sha256', $path);
        $now = time();
        try {
            $id = $this->app->db()->insert('releases', $data + [
                'file_name' => $moved['name'],
                'file_path' => $relative,
                'file_size' => (int) filesize($path),
                'sha256' => $sha,
                'is_published' => $publish === '1' ? 1 : 0,
                'download_count' => 0,
                'published_at' => $publish === '1' ? $now : null,
                'created_by' => (int) $admin['id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            @unlink($path);
            @rmdir($dir);
            throw $e;
        }
        Audit::log($this->app, (int) $admin['id'], 'release.created', (string) $id, [
            'product' => $data['product'],
            'version' => $data['version'],
            'channel' => $data['channel'],
            'file' => $moved['name'],
            'source' => $uploads !== [] ? 'upload' : 'incoming',
            'published' => $publish === '1',
        ]);
        $name = DownloadController::productName($data['product']) . ' ' . $data['version'];
        return $this->redirect('/portal/admin/releases/' . $id . '/', 'success', $publish === '1' ? $name . ' is published. Clients see it in Downloads now.' : $name . ' is saved as a draft. Clients will see it once you publish it.');
    }

    public function edit(Request $request): Response
    {
        $release = $this->find($request->attrInt('id'));
        if ($release === null) {
            return $this->notFound();
        }
        $creator = $release['created_by'] !== null ? $this->app->db()->one('SELECT id, name, email FROM users WHERE id = ?', [(int) $release['created_by']]) : null;
        $recent = (int) $this->app->db()->value('SELECT COUNT(*) FROM download_events WHERE release_id = ? AND created_at >= ?', [(int) $release['id'], time() - 30 * 86400]);
        return $this->portal('admin/releases/edit', [
            'release' => $release,
            'creator' => $creator,
            'recentDownloads' => $recent,
            'fileMissing' => DownloadController::resolveVaultFile($this->app, (string) $release['file_path']) === null,
            'products' => DownloadController::productNames(),
            'channels' => DownloadController::CHANNELS,
            'access' => DownloadController::ACCESS,
            'errors' => $this->takeErrors(),
            'old' => $this->app->session()->takeOld(),
        ], 'Edit ' . DownloadController::productName((string) $release['product']) . ' ' . (string) $release['version']);
    }

    public function update(Request $request): Response
    {
        $admin = $this->currentUser();
        $release = $this->find($request->attrInt('id'));
        if ($release === null) {
            return $this->notFound();
        }
        $id = (int) $release['id'];
        $path = '/portal/admin/releases/' . $id . '/';
        $action = $request->input('action');
        if (!in_array($action, self::ACTIONS, true)) {
            return $this->backWithErrors($path, $request, [], 'That action is not available. Use one of the buttons on this page.');
        }
        $name = DownloadController::productName((string) $release['product']) . ' ' . (string) $release['version'];
        $db = $this->app->db();
        $now = time();

        if ($action === 'save') {
            [$data, $errors] = $this->validateMeta($request, $id);
            if ($errors !== []) {
                return $this->backWithErrors($path . '#details', $request, $errors);
            }
            $changed = [];
            foreach ($data as $k => $v) {
                if ((string) ($release[$k] ?? '') !== (string) ($v ?? '')) {
                    $changed[] = $k;
                }
            }
            if ($changed === []) {
                return $this->redirect($path, 'info', 'Nothing changed, so nothing was saved.');
            }
            $db->update('releases', $data + ['updated_at' => $now], ['id' => $id]);
            Audit::log($this->app, (int) $admin['id'], 'release.updated', (string) $id, ['fields' => $changed]);
            return $this->redirect($path, 'success', 'Changes saved.');
        }

        if ($action === 'publish') {
            if ((int) $release['is_published'] === 1) {
                return $this->redirect($path, 'info', $name . ' is already published.');
            }
            // Clients are never shown a download button for a file that is not there.
            if (DownloadController::resolveVaultFile($this->app, (string) $release['file_path']) === null) {
                return $this->backWithErrors($path, $request, [], $name . ' was not published: its file is missing from the downloads folder. Delete this release and add it again with the file.');
            }
            $db->update('releases', ['is_published' => 1, 'published_at' => $release['published_at'] ?? $now, 'updated_at' => $now], ['id' => $id]);
            Audit::log($this->app, (int) $admin['id'], 'release.published', (string) $id);
            return $this->redirect($path, 'success', $name . ' is published. Clients see it in Downloads now.');
        }

        if ($action === 'unpublish') {
            if ((int) $release['is_published'] !== 1) {
                return $this->redirect($path, 'info', $name . ' is already a draft.');
            }
            $db->update('releases', ['is_published' => 0, 'updated_at' => $now], ['id' => $id]);
            Audit::log($this->app, (int) $admin['id'], 'release.unpublished', (string) $id);
            return $this->redirect($path, 'success', $name . ' is unpublished. Clients no longer see it or download it.');
        }

        if ($request->input('confirm') !== '1') {
            return $this->backWithErrors($path . '#delete', $request, ['confirm' => 'Tick the box to confirm you want to delete this release and its file.'], 'The release was not deleted.');
        }
        $file = DownloadController::resolveVaultFile($this->app, (string) $release['file_path']);
        // The row and its history go together; the file is removed only once they are gone.
        $db->transaction(static function (Database $db) use ($id): void {
            $db->exec('DELETE FROM download_events WHERE release_id = ?', [$id]);
            $db->exec('DELETE FROM releases WHERE id = ?', [$id]);
        });
        if ($file !== null) {
            @unlink($file);
            @rmdir(dirname($file));
        }
        Audit::log($this->app, (int) $admin['id'], 'release.deleted', (string) $id, [
            'product' => (string) $release['product'],
            'version' => (string) $release['version'],
            'file' => (string) $release['file_name'],
            'downloads' => (int) $release['download_count'],
        ]);
        return $this->redirect('/portal/admin/releases/', 'success', $name . ' and its file are deleted.');
    }

    // ---------------------------------------------------------------------------------------------------------
    // Internals

    /**
     * The release's details from the form (everything but the file), checked against the allow-lists.
     *
     * @return array{array{product: string, version: string, channel: string, title: string, notes: string|null, access: string}, array<string, string>}
     */
    private function validateMeta(Request $request, ?int $exceptId): array
    {
        $products = DownloadController::productNames();
        $errors = [];
        $product = $request->input('product');
        if (!isset($products[$product])) {
            $errors['product'] = 'Choose a product from the list.';
        }
        $version = trim($request->input('version'));
        if (!preg_match(self::VERSION_PATTERN, $version)) {
            $errors['version'] = 'Enter a version such as 2.16.0: up to 40 letters, numbers, dots, dashes or plus signs.';
        }
        $channel = $request->input('channel');
        if (!isset(DownloadController::CHANNELS[$channel])) {
            $errors['channel'] = 'Choose stable or beta.';
        }
        $title = trim((string) preg_replace('/\s+/u', ' ', self::utf8($request->input('title'))));
        if ($title === '' || mb_strlen($title) > 160) {
            $errors['title'] = 'Enter a title of up to 160 characters.';
        }
        $notes = trim(str_replace(["\r\n", "\r"], "\n", self::utf8($request->input('notes'))));
        $notes = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $notes);
        if (mb_strlen($notes) > 20000) {
            $errors['notes'] = 'Keep the release notes under 20,000 characters.';
        }
        $access = $request->input('access');
        if (!isset(DownloadController::ACCESS[$access])) {
            $errors['access'] = 'Choose who can download this release.';
        }
        if (!isset($errors['product']) && !isset($errors['version']) && !isset($errors['channel'])) {
            $sql = 'SELECT id FROM releases WHERE product = ? AND version = ? AND channel = ?' . ($exceptId !== null ? ' AND id <> ?' : '');
            $params = [$product, $version, $channel];
            if ($exceptId !== null) {
                $params[] = $exceptId;
            }
            if ($this->app->db()->value($sql, $params) !== null) {
                $errors['version'] = 'There is already a ' . $products[$product] . ' ' . $version . ' ' . mb_strtolower(DownloadController::CHANNELS[$channel]) . ' release. Edit that one, or use a new version number.';
            }
        }
        return [[
            'product' => $product,
            'version' => $version,
            'channel' => $channel,
            'title' => $title,
            'notes' => $notes !== '' ? $notes : null,
            'access' => $access,
        ], $errors];
    }

    /**
     * Plain files directly inside the vault's incoming/ folder, newest first.
     *
     * @return list<array{name: string, size: int, modified: int, allowed: bool}>
     */
    private function incomingFiles(): array
    {
        $dir = $this->incomingDir();
        if ($dir === null) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $name) {
            $full = $dir . '/' . $name;
            if ($name === '.' || $name === '..' || str_starts_with($name, '.') || is_link($full) || !is_file($full)) {
                continue;
            }
            $out[] = [
                'name' => $name,
                'size' => (int) filesize($full),
                'modified' => (int) filemtime($full),
                'allowed' => in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EXTENSIONS, true),
            ];
        }
        usort($out, static fn (array $a, array $b): int => $b['modified'] <=> $a['modified'] ?: strcmp($a['name'], $b['name']));
        return $out;
    }

    private function incomingDir(): ?string
    {
        $vault = DownloadController::vaultRoot($this->app);
        $dir = $vault . '/incoming';
        if (!is_dir($dir) && is_dir($vault) && is_writable($vault)) {
            @mkdir($dir, 0770);
        }
        // incoming/ must be a real folder directly inside the vault: a link there would let a pick reach, and move,
        // files from anywhere the link points.
        $real = realpath($dir);
        $vaultReal = realpath($vault);
        if ($real === false || $vaultReal === false || is_link($dir) || !is_dir($real) || dirname($real) !== $vaultReal) {
            return null;
        }
        return $real;
    }

    /** The absolute path of a picked incoming file, or null unless it is a listed, plain file of an allowed type. */
    private function incomingPath(string $name): ?string
    {
        if ($name === '' || $name !== basename($name) || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0") || str_starts_with($name, '.')) {
            return null;
        }
        $listed = false;
        foreach ($this->incomingFiles() as $f) {
            if ($f['name'] === $name && $f['allowed']) {
                $listed = true;
                break;
            }
        }
        $dir = $this->incomingDir();
        if (!$listed || $dir === null) {
            return null;
        }
        $real = realpath($dir . '/' . $name);
        if ($real === false || dirname($real) !== $dir || is_link($dir . '/' . $name) || !is_file($real)) {
            return null;
        }
        return $real;
    }

    /**
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
     * @return array{ok: true, path: string, name: string}|array{ok: false, error: string}
     */
    private function moveUpload(array $file, string $dir): array
    {
        $stored = Uploads::store($file, $dir, self::EXTENSIONS, $this->maxUploadBytes(), $this->app->config->string('app.env') === 'testing');
        if (!$stored['ok']) {
            @rmdir($dir);
            return ['ok' => false, 'error' => $stored['error']];
        }
        $target = $dir . '/' . self::vaultName($stored['original_name']);
        if (!@rename($stored['path'], $target)) {
            @unlink($stored['path']);
            @rmdir($dir);
            return ['ok' => false, 'error' => 'The file could not be stored. Please try again.'];
        }
        return ['ok' => true, 'path' => $target, 'name' => $stored['original_name']];
    }

    /** @return array{ok: true, path: string, name: string}|array{ok: false, error: string} */
    private function moveIncoming(string $source, string $dir): array
    {
        $name = Uploads::cleanName(basename($source));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $size = (int) @filesize($source);
        if ($size <= 0) {
            return ['ok' => false, 'error' => 'That file is empty. Upload it to the incoming folder again.'];
        }
        if (!Uploads::mimeMatches($ext, Uploads::sniff($source))) {
            return ['ok' => false, 'error' => 'The file’s content does not match its extension.'];
        }
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
            return ['ok' => false, 'error' => 'The file could not be stored. Check that the downloads folder is writable.'];
        }
        $target = $dir . '/' . self::vaultName($name);
        if (!@rename($source, $target)) {
            if (!@copy($source, $target)) {
                @rmdir($dir);
                return ['ok' => false, 'error' => 'The file could not be moved out of the incoming folder. Check its permissions.'];
            }
            @unlink($source);
        }
        @chmod($target, 0640);
        return ['ok' => true, 'path' => $target, 'name' => $name];
    }

    /**
     * A file name for the vault and the LiteSpeed redirect, always one DownloadController::PATH_PATTERN accepts:
     * letters, digits, dot, dash, underscore and plus only, starting and ending with a letter or digit, and never
     * two dots in a row (a served path may not contain "..").
     */
    public static function vaultName(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $ext = preg_match('/^[a-z0-9]{1,10}$/', $ext) ? $ext : '';
        $base = (string) preg_replace('/[^A-Za-z0-9._+-]+/', '-', pathinfo($name, PATHINFO_FILENAME));
        $base = (string) preg_replace(['/\.{2,}/', '/-{2,}/'], ['.', '-'], $base);
        $base = substr($base, 0, 120);
        $base = (string) preg_replace('/^[^A-Za-z0-9]+|[^A-Za-z0-9]+$/', '', $base);
        return ($base !== '' ? $base : 'release') . ($ext !== '' ? '.' . $ext : '');
    }

    /** The upload limit: downloads.max_upload_mb, capped by what PHP itself accepts. */
    private function maxUploadBytes(): int
    {
        $limit = max(1, $this->app->config->int('downloads.max_upload_mb', 512)) * 1024 * 1024;
        foreach (['upload_max_filesize', 'post_max_size'] as $ini) {
            $bytes = Uploads::iniBytes((string) ini_get($ini));
            if ($bytes > 0) {
                $limit = min($limit, $bytes);
            }
        }
        return $limit;
    }

    /** @return array<string, mixed>|null */
    private static function fileEntry(Request $request, string $field): ?array
    {
        $entry = $request->files[$field] ?? null;
        return is_array($entry) ? $entry : null;
    }

    /** @return array<string, mixed>|null */
    private function find(int $id): ?array
    {
        return $id > 0 ? $this->app->db()->one('SELECT * FROM releases WHERE id = ?', [$id]) : null;
    }

    /** @return array<string, mixed> */
    private function currentUser(): array
    {
        $user = $this->app->auth()->user();
        if ($user === null) {
            throw new \LogicException('Release administration needs the auth middleware');
        }
        return $user;
    }

    private static function utf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : '';
    }
}
