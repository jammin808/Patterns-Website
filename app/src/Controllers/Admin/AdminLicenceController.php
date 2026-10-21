<?php

declare(strict_types=1);

namespace Psc\Controllers\Admin;

use Psc\Controllers\Controller;
use Psc\Controllers\Portal\DownloadController;
use Psc\Controllers\Portal\LicenceController;
use Psc\Db\Database;
use Psc\Http\Request;
use Psc\Http\Response;
use Psc\Support\Audit;
use Psc\Support\Paginator;
use Psc\Support\Uploads;

/**
 * Licence requests, for administrators (the routes carry the admin middleware): a filtered list, one request's
 * page, and the update its forms post. Every action is on an allow-list and audited.
 *
 *   set_status   requested | awaiting_payment | issued | revoked, with an optional message to the client, who is
 *                emailed (licence-issued when it becomes issued, licence-status otherwise). A licence is issued
 *                only once it has a file, so a client is never told to download something that is not there;
 *                files can be added in the same form. The first issue sets issued_at.
 *   upload       one or more licence files (dat, lic, txt; 1 MB each), optionally emailing the client
 *   delete_file  removes one of this licence's files
 *   details      the admin note and the updates-until and expiry dates (YYYY-MM-DD, or empty)
 */
final class AdminLicenceController extends Controller
{
    public const FILE_EXTENSIONS = ['dat', 'lic', 'txt'];

    public const MAX_FILE_BYTES = 1_048_576;

    public const MAX_FILES = 10;

    private const ACTIONS = ['set_status', 'upload', 'delete_file', 'details'];

    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $products = DownloadController::productNames();
        $statuses = LicenceController::STATUSES;
        $f = [
            'status' => isset($statuses[$request->queryString('status')]) ? $request->queryString('status') : '',
            'product' => isset($products[$request->queryString('product')]) ? $request->queryString('product') : '',
            'q' => mb_substr(trim(mb_check_encoding($request->queryString('q'), 'UTF-8') ? $request->queryString('q') : ''), 0, 190),
        ];
        $where = ['1 = 1'];
        $params = [];
        if ($f['status'] !== '') {
            $where[] = 'l.status = ?';
            $params[] = $f['status'];
        }
        if ($f['product'] !== '') {
            $where[] = 'l.product = ?';
            $params[] = $f['product'];
        }
        if ($f['q'] !== '') {
            $where[] = "(l.reference LIKE ? ESCAPE '!' OR u.email LIKE ? ESCAPE '!')";
            $like = Database::like($f['q']);
            array_push($params, $like, $like);
        }
        $sql = 'SELECT l.id, l.user_id, l.product, l.tier, l.status, l.reference, l.nonprofit, l.quantity, l.created_at, l.issued_at, l.updated_at,'
            . ' u.name AS user_name, u.email AS user_email'
            . ' FROM licences l LEFT JOIN users u ON u.id = l.user_id'
            . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY l.created_at DESC, l.id DESC';
        $query = array_filter($f + ['page' => $request->queryString('page')], static fn (string $v): bool => $v !== '');
        $pager = Paginator::query($this->app->db(), $sql, $params, $query, '/portal/admin/licences/', self::PER_PAGE);

        $counts = ['' => 0] + array_fill_keys(array_keys($statuses), 0);
        foreach ($this->app->db()->all('SELECT status, COUNT(*) AS n FROM licences GROUP BY status') as $row) {
            if (isset($counts[(string) $row['status']])) {
                $counts[(string) $row['status']] = (int) $row['n'];
            }
            $counts[''] += (int) $row['n'];
        }

        return $this->portal('admin/licences/index', [
            'pager' => $pager,
            'filters' => $f,
            'filtered' => $f['status'] !== '' || $f['product'] !== '' || $f['q'] !== '',
            'counts' => $counts,
            'statuses' => $statuses,
            'products' => $products,
            'options' => LicenceController::optionNames(),
        ], 'Licence requests');
    }

    public function show(Request $request): Response
    {
        $licence = $this->find($request->attrInt('id'));
        if ($licence === null) {
            return $this->notFound();
        }
        $db = $this->app->db();
        $owner = $db->one('SELECT id, name, email, company, status FROM users WHERE id = ?', [(int) $licence['user_id']]);
        $files = $db->all('SELECT id, original_name, size, created_at FROM licence_files WHERE licence_id = ? ORDER BY id', [(int) $licence['id']]);
        $product = (string) $licence['product'];
        return $this->portal('admin/licences/show', [
            'licence' => $licence,
            'owner' => $owner,
            'files' => $files,
            'productName' => DownloadController::productName($product),
            'optionName' => LicenceController::optionName($product, (string) $licence['tier']),
            'hardwareIds' => LicenceController::decodeIds($licence['hardware_ids'] ?? null),
            'statuses' => LicenceController::STATUSES,
            'paymentLink' => LicenceController::paymentLink($this->app, $licence),
            'extensions' => self::FILE_EXTENSIONS,
            'maxFiles' => self::MAX_FILES,
            'errors' => $this->takeErrors(),
            'old' => $this->app->session()->takeOld(),
        ], 'Licence ' . (string) $licence['reference']);
    }

    public function update(Request $request): Response
    {
        $admin = $this->currentUser();
        $licence = $this->find($request->attrInt('id'));
        if ($licence === null) {
            return $this->notFound();
        }
        $action = $request->input('action');
        if (!in_array($action, self::ACTIONS, true)) {
            return $this->backWithErrors(self::path((int) $licence['id']), $request, [], 'That action is not available. Use one of the forms on this page.');
        }
        return match ($action) {
            'set_status' => $this->setStatus($request, $licence, $admin),
            'upload' => $this->upload($request, $licence, $admin),
            'delete_file' => $this->deleteFile($request, $licence, $admin),
            default => $this->saveDetails($request, $licence, $admin),
        };
    }

    public static function path(int $id): string
    {
        return '/portal/admin/licences/' . $id . '/';
    }

    // ---------------------------------------------------------------------------------------------------------
    // Actions

    /**
     * @param array<string, mixed> $licence
     * @param array<string, mixed> $admin
     */
    private function setStatus(Request $request, array $licence, array $admin): Response
    {
        $id = (int) $licence['id'];
        $back = self::path($id) . '#status';
        $status = $request->input('status');
        $old = (string) $licence['status'];
        $message = self::text($request->input('message'));
        $errors = [];
        if (!isset(LicenceController::STATUSES[$status])) {
            $errors['status'] = 'Choose a status from the list.';
        } elseif ($status === $old) {
            $errors['status'] = 'This licence is already ' . mb_strtolower(LicenceController::STATUSES[$status]) . '. Choose a different status.';
        }
        if (mb_strlen($message) > 5000) {
            $errors['message'] = 'Keep the message under 5,000 characters.';
        }
        $uploads = Uploads::files(self::fileEntry($request, 'files'));
        if (count($uploads) > self::MAX_FILES) {
            $errors['files'] = 'Add up to ' . self::MAX_FILES . ' files at a time.';
        }
        if ($errors === [] && $status === 'issued' && $uploads === [] && $this->fileCount($id) === 0) {
            $errors['files'] = 'Add the licence file before issuing, so the client has something to download.';
        }
        if ($errors !== []) {
            return $this->backWithErrors($back, $request, $errors);
        }
        $stored = $this->storeFiles($uploads);
        if ($stored['error'] !== null) {
            return $this->backWithErrors($back, $request, ['files' => $stored['error']]);
        }

        $now = time();
        $set = ['status' => $status, 'updated_at' => $now];
        if ($status === 'issued' && $licence['issued_at'] === null) {
            $set['issued_at'] = $now;
        }
        $this->commit($stored['files'], function (Database $db) use ($id, $set, $stored, $now): void {
            $this->insertFiles($db, $id, $stored['files'], $now);
            $db->update('licences', $set, ['id' => $id]);
        });
        if ($stored['files'] !== []) {
            Audit::log($this->app, (int) $admin['id'], 'licence.file_added', (string) $id, ['files' => array_column($stored['files'], 'original_name')]);
        }
        Audit::log($this->app, (int) $admin['id'], 'licence.status', (string) $id, ['from' => $old, 'to' => $status, 'message' => $message !== '']);

        $fresh = $this->find($id) ?? $licence;
        $sent = $this->emailClient($fresh, $status === 'issued' ? 'issued' : 'status', $message);
        $label = LicenceController::STATUSES[$status];
        return $this->redirect(self::path($id), 'success', 'Status changed to ' . mb_strtolower($label) . '.' . ($sent ? ' The client has been emailed.' : ''));
    }

    /**
     * @param array<string, mixed> $licence
     * @param array<string, mixed> $admin
     */
    private function upload(Request $request, array $licence, array $admin): Response
    {
        $id = (int) $licence['id'];
        $back = self::path($id) . '#files';
        $uploads = Uploads::files(self::fileEntry($request, 'files'));
        $notify = $request->input('notify');
        $errors = [];
        if ($uploads === []) {
            $errors['files'] = 'Choose at least one licence file to upload.';
        } elseif (count($uploads) > self::MAX_FILES) {
            $errors['files'] = 'Add up to ' . self::MAX_FILES . ' files at a time.';
        }
        if (!in_array($notify, ['', '1'], true) || ($notify === '1' && $licence['status'] !== 'issued')) {
            $errors['notify'] = 'The client can only be told about a new file once the licence is issued.';
        }
        if ($errors !== []) {
            return $this->backWithErrors($back, $request, $errors);
        }
        $stored = $this->storeFiles($uploads);
        if ($stored['error'] !== null) {
            return $this->backWithErrors($back, $request, ['files' => $stored['error']]);
        }
        $now = time();
        $this->commit($stored['files'], function (Database $db) use ($id, $stored, $now): void {
            $this->insertFiles($db, $id, $stored['files'], $now);
            $db->update('licences', ['updated_at' => $now], ['id' => $id]);
        });
        Audit::log($this->app, (int) $admin['id'], 'licence.file_added', (string) $id, ['files' => array_column($stored['files'], 'original_name'), 'notified' => $notify === '1']);
        $sent = $notify === '1' && $this->emailClient($this->find($id) ?? $licence, 'issued', '');
        $n = count($stored['files']);
        return $this->redirect(self::path($id) . '#files', 'success', ($n === 1 ? 'File added.' : $n . ' files added.') . ($sent ? ' The client has been emailed.' : ''));
    }

    /**
     * @param array<string, mixed> $licence
     * @param array<string, mixed> $admin
     */
    private function deleteFile(Request $request, array $licence, array $admin): Response
    {
        $id = (int) $licence['id'];
        $fileId = (int) $request->input('file_id');
        $file = $fileId > 0 ? $this->app->db()->one('SELECT * FROM licence_files WHERE id = ? AND licence_id = ?', [$fileId, $id]) : null;
        if ($file === null) {
            return $this->backWithErrors(self::path($id) . '#files', $request, [], 'That file is not part of this licence. It may already be deleted.');
        }
        $path = LicenceController::storedPath($this->app, (string) $file['stored_name']);
        $this->app->db()->transaction(static function (Database $db) use ($fileId, $id): void {
            $db->exec('DELETE FROM licence_files WHERE id = ?', [$fileId]);
            $db->update('licences', ['updated_at' => time()], ['id' => $id]);
        });
        if ($path !== null) {
            @unlink($path);
        }
        Audit::log($this->app, (int) $admin['id'], 'licence.file_deleted', (string) $id, ['file' => (string) $file['original_name']]);
        $left = $this->fileCount($id);
        $warning = $licence['status'] === 'issued' && $left === 0 ? ' This licence is issued but has no file now: add one, or change its status.' : '';
        return $this->redirect(self::path($id) . '#files', $warning !== '' ? 'warning' : 'success', (string) $file['original_name'] . ' is deleted.' . $warning);
    }

    /**
     * @param array<string, mixed> $licence
     * @param array<string, mixed> $admin
     */
    private function saveDetails(Request $request, array $licence, array $admin): Response
    {
        $id = (int) $licence['id'];
        $back = self::path($id) . '#details';
        $errors = [];
        $note = self::text($request->input('admin_note'));
        if (mb_strlen($note) > 5000) {
            $errors['admin_note'] = 'Keep the note under 5,000 characters.';
        }
        $dates = [];
        foreach (['updates_until' => 'updates-until date', 'expires_at' => 'expiry date'] as $field => $label) {
            $parsed = self::parseDate($request->input($field));
            if ($parsed === false) {
                $errors[$field] = 'Enter the ' . $label . ' as a date (YYYY-MM-DD), or leave it empty.';
            } else {
                $dates[$field] = $parsed;
            }
        }
        if ($errors !== []) {
            return $this->backWithErrors($back, $request, $errors);
        }
        $set = ['admin_note' => $note !== '' ? $note : null] + $dates;
        $changed = [];
        foreach ($set as $k => $v) {
            if (($licence[$k] === null ? null : (string) $licence[$k]) !== ($v === null ? null : (string) $v)) {
                $changed[] = $k;
            }
        }
        if ($changed === []) {
            return $this->redirect(self::path($id) . '#details', 'info', 'Nothing changed, so nothing was saved.');
        }
        $this->app->db()->update('licences', $set + ['updated_at' => time()], ['id' => $id]);
        Audit::log($this->app, (int) $admin['id'], 'licence.details', (string) $id, ['fields' => $changed]);
        return $this->redirect(self::path($id) . '#details', 'success', 'Details saved.');
    }

    // ---------------------------------------------------------------------------------------------------------
    // Internals

    /**
     * Emails the licence's owner: 'issued' (the licence is ready) or 'status' (it moved on). Never for a disabled
     * or deleted account.
     *
     * @param array<string, mixed> $licence
     */
    private function emailClient(array $licence, string $kind, string $message): bool
    {
        $owner = $this->app->db()->one('SELECT name, email, status FROM users WHERE id = ?', [(int) $licence['user_id']]);
        if ($owner === null || !in_array($owner['status'], ['active', 'approval', 'pending'], true) || !filter_var((string) $owner['email'], FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $product = (string) $licence['product'];
        $status = (string) $licence['status'];
        $data = [
            'name' => (string) $owner['name'],
            'reference' => (string) $licence['reference'],
            'productName' => DownloadController::productName($product),
            'optionName' => LicenceController::optionName($product, (string) $licence['tier']),
            'status' => $status,
            'statusLabel' => LicenceController::STATUSES[$status] ?? $status,
            'statusHelp' => LicenceController::STATUS_HELP[$status] ?? '',
            'message' => $message,
            'installHelp' => LicenceController::INSTALL_HELP[$product] ?? '',
            'paymentLink' => $status === 'awaiting_payment' ? LicenceController::paymentLink($this->app, $licence) : null,
            'url' => $this->app->baseUrl() . '/portal/licences/' . (int) $licence['id'] . '/',
        ];
        if ($kind === 'issued') {
            return $this->app->mailer()->send((string) $owner['email'], 'Your ' . $data['productName'] . ' licence is ready', 'licence-issued', $data);
        }
        return $this->app->mailer()->send((string) $owner['email'], 'Your licence ' . $data['reference'] . ': ' . mb_strtolower($data['statusLabel']), 'licence-status', $data);
    }

    /**
     * Stores licence files under storage/licences with random names; all or none.
     *
     * @param list<array{name: string, type: string, tmp_name: string, error: int, size: int}> $uploads
     * @return array{files: list<array{original_name: string, stored_name: string, size: int, path: string}>, error: string|null}
     */
    private function storeFiles(array $uploads): array
    {
        $dir = $this->app->storage->dir('licences');
        $trust = $this->app->config->string('app.env') === 'testing';
        $files = [];
        foreach ($uploads as $u) {
            $result = Uploads::store($u, $dir, self::FILE_EXTENSIONS, self::MAX_FILE_BYTES, $trust);
            if (!$result['ok']) {
                foreach ($files as $done) {
                    @unlink($done['path']);
                }
                return ['files' => [], 'error' => Uploads::cleanName($u['name']) . ': ' . $result['error']];
            }
            $files[] = ['original_name' => $result['original_name'], 'stored_name' => $result['stored_name'], 'size' => $result['size'], 'path' => $result['path']];
        }
        return ['files' => $files, 'error' => null];
    }

    /**
     * Runs the database writes for newly stored files; when they fail, the files are removed from disk again, so
     * storage never holds a licence file that no row points to.
     *
     * @param list<array{original_name: string, stored_name: string, size: int, path: string}> $files
     * @param callable(Database): void $writes
     */
    private function commit(array $files, callable $writes): void
    {
        try {
            $this->app->db()->transaction($writes);
        } catch (\Throwable $e) {
            foreach ($files as $f) {
                @unlink($f['path']);
            }
            throw $e;
        }
    }

    /** @param list<array{original_name: string, stored_name: string, size: int, path: string}> $files */
    private function insertFiles(Database $db, int $licenceId, array $files, int $now): void
    {
        foreach ($files as $f) {
            $db->insert('licence_files', [
                'licence_id' => $licenceId,
                'original_name' => $f['original_name'],
                'stored_name' => $f['stored_name'],
                'size' => $f['size'],
                'created_at' => $now,
            ]);
        }
    }

    private function fileCount(int $licenceId): int
    {
        return (int) $this->app->db()->value('SELECT COUNT(*) FROM licence_files WHERE licence_id = ?', [$licenceId]);
    }

    /** A YYYY-MM-DD date as midnight in the site's time zone, null for empty, false when it is not a real date. */
    public static function parseDate(string $value): int|false|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[1] < 2000 || (int) $m[1] > 2100) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date === false ? false : $date->getTimestamp();
    }

    private static function text(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            return '';
        }
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value));
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
        return $id > 0 ? $this->app->db()->one('SELECT * FROM licences WHERE id = ?', [$id]) : null;
    }

    /** @return array<string, mixed> */
    private function currentUser(): array
    {
        $user = $this->app->auth()->user();
        if ($user === null) {
            throw new \LogicException('Licence administration needs the auth middleware');
        }
        return $user;
    }
}
