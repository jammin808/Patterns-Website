<?php

declare(strict_types=1);

namespace Psc\Controllers\Portal;

use Psc\App;
use Psc\Content\Content;
use Psc\Controllers\Controller;
use Psc\Db\Database;
use Psc\Http\Request;
use Psc\Http\Response;
use Psc\Support\Uploads;

/**
 * The downloads page and the release files behind it.
 *
 * Who may download a release is decided here from the stored row and the signed-in account:
 *   published, access 'registered'  every signed-in, active account
 *   published, access 'licensed'    accounts holding an issued licence for the release's product, and administrators
 *   draft (not published)           administrators only (a preview from the release's admin page)
 * Anyone else gets a 404 for the file. A draft is never listed or revealed to a client; a licensed build is listed
 * with the way to request a licence, but its file is served only to licence holders.
 *
 * Files live in the vault (downloads.vault_path, by default "<app root>/downloads-vault") as
 * "<32 random hex>/<file name>", a path no visitor can guess. On LiteSpeed the response is an internal redirect
 * (X-LiteSpeed-Location under downloads.vault_url) so the web server sends the bytes; elsewhere the file is
 * streamed here in 1 MiB chunks with single-range support, so an interrupted download can resume.
 * A served download counts once: HEAD requests, ranges that do not start at byte 0 (a resume) and an
 * administrator's draft preview are not counted, and one account's counted downloads are capped per hour
 * (COUNT_LIMIT), so a script cannot inflate a release's count or grow download_events without bound. Past the cap
 * the file is still served.
 */
final class DownloadController extends Controller
{
    public const CHANNELS = ['stable' => 'Stable', 'beta' => 'Beta'];

    public const ACCESS = ['registered' => 'Every signed-in client', 'licensed' => 'Licence holders only'];

    /** Product names when a product has no content file yet. */
    private const PRODUCT_NAMES = [
        'patterns' => 'Patterns',
        'stagewatch' => 'StageWatch',
        'agmessage' => 'AGMessage',
        'playmap' => 'PlayMap',
    ];

    private const CHUNK = 1_048_576;

    /** Counted downloads per account per hour: [limit, window seconds]. */
    private const COUNT_LIMIT = [30, 3600];

    /** A release's vault path: a random folder, then a file name of safe characters only. */
    public const PATH_PATTERN = '#^[a-f0-9]{32}/[A-Za-z0-9][A-Za-z0-9._+-]{0,149}$#D';

    // ---------------------------------------------------------------------------------------------------------
    // Pages

    /** Published releases grouped by product (ecosystem order), newest first, with the state this account sees. */
    public function index(Request $request): Response
    {
        $user = $this->currentUser();
        $db = $this->app->db();
        $isAdmin = ($user['role'] ?? '') === 'admin';
        $licensed = $isAdmin ? self::productKeys() : self::licensedProducts($db, (int) $user['id']);

        $rows = $db->all('SELECT id, product, version, channel, title, notes, file_name, file_size, sha256, access, published_at'
            . ' FROM releases WHERE is_published = 1 ORDER BY published_at DESC, id DESC');
        $groups = [];
        foreach (self::productKeys() as $key) {
            $groups[$key] = [];
        }
        foreach ($rows as $row) {
            $key = (string) $row['product'];
            if (!array_key_exists($key, $groups)) {
                continue;
            }
            $row['may_download'] = $row['access'] !== 'licensed' || in_array($key, $licensed, true);
            $groups[$key][] = $row;
        }
        $groups = array_filter($groups, static fn (array $list): bool => $list !== []);

        $requestable = LicenceController::liveProducts();
        $products = [];
        foreach (array_keys($groups) as $key) {
            $products[$key] = [
                'name' => self::productName($key),
                'licence_url' => isset($requestable[$key])
                    ? '/portal/licences/request/?product=' . rawurlencode($key)
                    : '/contact/?topic=sales&product=' . rawurlencode($key),
                'licence_label' => isset($requestable[$key]) ? 'Request a licence' : 'Ask about a licence',
            ];
        }

        return $this->portal('portal/downloads/index', [
            'groups' => $groups,
            'products' => $products,
            'channels' => self::CHANNELS,
        ], 'Downloads');
    }

    /** One release file, after the access check; counted, then handed to LiteSpeed or streamed. */
    public function file(Request $request): Response
    {
        $user = $this->currentUser();
        $db = $this->app->db();
        $release = $db->one('SELECT * FROM releases WHERE id = ?', [$request->attrInt('id')]);
        if ($release === null || !self::mayDownload($db, $release, $user)) {
            return $this->notFound();
        }
        $relative = (string) $release['file_path'];
        $path = $this->vaultFile($relative);
        if ($path === null) {
            $this->app->log->warning('release file missing', ['release' => (int) $release['id']]);
            return $this->notFound();
        }
        $size = (int) filesize($path);
        $name = (string) $release['file_name'];
        $isHead = $request->method === 'HEAD';
        $etag = self::etag($release, $size);
        $lastModified = gmdate('D, d M Y H:i:s', (int) filemtime($path)) . ' GMT';

        $range = null;
        if ($request->header('range') !== '' && self::ifRangeMatches($request->header('if-range'), $etag, $lastModified)) {
            $range = self::parseRange($request->header('range'), $size);
            if ($range === false) {
                $response = new Response('', 416);
                $response->header('Content-Range', 'bytes */' . $size);
                $response->header('Accept-Ranges', 'bytes');
                $response->header('Cache-Control', 'private, no-store');
                return $response;
            }
        }
        $start = is_array($range) ? $range[0] : 0;

        if (!$isHead && $start === 0 && (int) $release['is_published'] === 1) {
            $this->count((int) $release['id'], (int) $user['id']);
        }

        $litespeed = $this->litespeedLocation($request, $relative);
        if ($litespeed !== null) {
            $response = new Response('', 200);
            foreach (Uploads::downloadHeaders($name, $size) as $header => $value) {
                if ($header !== 'Content-Length') {
                    $response->header($header, $value);
                }
            }
            return $response->header('X-LiteSpeed-Location', $litespeed);
        }

        [$start, $end] = is_array($range) ? $range : [0, max(0, $size - 1)];
        $length = $size === 0 ? 0 : $end - $start + 1;
        $status = is_array($range) ? 206 : 200;
        $response = $isHead ? new Response('', $status) : Response::stream(self::writer($this->app, $path, $start, $length), 'application/octet-stream', $status);
        foreach (Uploads::downloadHeaders($name, $length) as $header => $value) {
            $response->header($header, $value);
        }
        $response->header('Accept-Ranges', 'bytes');
        $response->header('ETag', $etag);
        $response->header('Last-Modified', $lastModified);
        if ($status === 206) {
            $response->header('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $size);
        }
        return $response;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Shared with the licence and admin pages

    /** @return list<string> product keys in ecosystem order */
    public static function productKeys(): array
    {
        return Content::PRODUCTS;
    }

    public static function productName(string $key): string
    {
        $name = Content::product($key)['name'] ?? null;
        return is_string($name) && $name !== '' ? $name : (self::PRODUCT_NAMES[$key] ?? ucfirst($key));
    }

    /** @return array<string, string> key => name, in ecosystem order */
    public static function productNames(): array
    {
        $out = [];
        foreach (self::productKeys() as $key) {
            $out[$key] = self::productName($key);
        }
        return $out;
    }

    /** @return list<string> the products this account holds an issued licence for */
    public static function licensedProducts(Database $db, int $userId): array
    {
        $rows = $db->all("SELECT DISTINCT product FROM licences WHERE user_id = ? AND status = 'issued'", [$userId]);
        return array_values(array_map(static fn (array $r): string => (string) $r['product'], $rows));
    }

    /**
     * @param array<string, mixed> $release
     * @param array<string, mixed> $user
     */
    public static function mayDownload(Database $db, array $release, array $user): bool
    {
        if (($user['role'] ?? '') === 'admin') {
            return true;
        }
        if ((int) $release['is_published'] !== 1) {
            return false;
        }
        return match ((string) $release['access']) {
            'registered' => true,
            'licensed' => in_array((string) $release['product'], self::licensedProducts($db, (int) $user['id']), true),
            default => false,
        };
    }

    /** The vault's absolute path (no trailing slash). */
    public static function vaultRoot(App $app): string
    {
        $configured = $app->config->string('downloads.vault_path');
        return rtrim($configured !== '' ? $configured : $app->root . '/downloads-vault', '/');
    }

    /** The absolute path of a release's file when the stored path is well formed and the file is inside the vault. */
    public function vaultFile(string $relative): ?string
    {
        return self::resolveVaultFile($this->app, $relative);
    }

    public static function resolveVaultFile(App $app, string $relative): ?string
    {
        if (!preg_match(self::PATH_PATTERN, $relative) || str_contains($relative, '..')) {
            return null;
        }
        $root = realpath(self::vaultRoot($app));
        $full = realpath(self::vaultRoot($app) . '/' . $relative);
        if ($root === false || $full === false || !str_starts_with($full, $root . DIRECTORY_SEPARATOR) || !is_file($full) || is_link(self::vaultRoot($app) . '/' . $relative)) {
            return null;
        }
        return $full;
    }

    /**
     * Parses a Range header against a file's size: [start, end] for one satisfiable range, false when it cannot
     * be satisfied (answer 416), null when the header is malformed or asks for several ranges (send the whole file).
     *
     * @return array{int, int}|false|null
     */
    public static function parseRange(string $header, int $size): array|false|null
    {
        if (!preg_match('/^\s*bytes\s*=\s*(\d*)\s*-\s*(\d*)\s*$/i', $header, $m)) {
            return null;
        }
        [$first, $last] = [$m[1], $m[2]];
        if ($first === '' && $last === '') {
            return null;
        }
        if ($first === '') {
            $suffix = (int) $last;
            if ($suffix === 0 || $size === 0) {
                return false;
            }
            return [max(0, $size - $suffix), $size - 1];
        }
        if (strlen($first) > 18 || strlen($last) > 18) {
            return null;
        }
        $start = (int) $first;
        $end = $last === '' ? $size - 1 : (int) $last;
        if ($last !== '' && $end < $start) {
            return null;
        }
        if ($start >= $size) {
            return false;
        }
        return [$start, min($end, $size - 1)];
    }

    // ---------------------------------------------------------------------------------------------------------
    // Internals

    /** @return array<string, mixed> */
    private function currentUser(): array
    {
        $user = $this->app->auth()->user();
        if ($user === null) {
            throw new \LogicException('Download pages need the auth middleware');
        }
        return $user;
    }

    private function count(int $releaseId, int $userId): void
    {
        [$limit, $window] = self::COUNT_LIMIT;
        if (!$this->app->limiter()->hit('download:' . $userId, $limit, $window)) {
            return;
        }
        $ipHash = $this->app->ipHash($this->app->clientIp());
        $this->app->db()->transaction(static function (Database $db) use ($releaseId, $userId, $ipHash): void {
            $db->exec('UPDATE releases SET download_count = download_count + 1 WHERE id = ?', [$releaseId]);
            $db->insert('download_events', [
                'release_id' => $releaseId,
                'user_id' => $userId,
                'ip_hash' => $ipHash,
                'created_at' => time(),
            ]);
        });
    }

    /** The internal-redirect URL on LiteSpeed, or null to stream the file here. */
    private function litespeedLocation(Request $request, string $relative): ?string
    {
        $software = $request->server['SERVER_SOFTWARE'] ?? '';
        if (!is_string($software) || !str_contains($software, 'LiteSpeed')) {
            return null;
        }
        $base = $this->app->config->string('downloads.vault_url', '/downloads-vault/');
        if ($base === '' || !str_starts_with($base, '/')) {
            return null;
        }
        return rtrim($base, '/') . '/' . implode('/', array_map('rawurlencode', explode('/', $relative)));
    }

    /** @param array<string, mixed> $release */
    private static function etag(array $release, int $size): string
    {
        $sha = (string) ($release['sha256'] ?? '');
        $tag = preg_match('/^[a-f0-9]{64}$/', $sha) ? substr($sha, 0, 32) : hash('sha256', $release['id'] . ':' . $release['file_path'] . ':' . $size);
        return '"' . substr($tag, 0, 32) . '"';
    }

    /** No If-Range, or one naming this exact file: a Range may be honoured. Otherwise the whole file is sent. */
    private static function ifRangeMatches(string $ifRange, string $etag, string $lastModified): bool
    {
        $ifRange = trim($ifRange);
        return $ifRange === '' || $ifRange === $etag || $ifRange === $lastModified;
    }

    /** @return \Closure(): void writes $length bytes from $start, 1 MiB at a time, stopping if the client goes */
    private static function writer(App $app, string $path, int $start, int $length): \Closure
    {
        return static function () use ($app, $path, $start, $length): void {
            // A long download must not hold the visitor's session lock or hit the script time limit.
            $app->session()->close();
            if (PHP_SAPI !== 'cli') {
                @set_time_limit(0);
                // Each chunk goes straight to the web server: an output buffer (output_buffering = On has no size
                // limit) or zlib.output_compression would otherwise hold or recompress a whole installer in memory.
                while (ob_get_level() > 0 && @ob_end_flush()) {
                    continue;
                }
            }
            $fh = @fopen($path, 'rb');
            if ($fh === false) {
                return;
            }
            try {
                if ($start > 0 && fseek($fh, $start) !== 0) {
                    return;
                }
                $left = $length;
                while ($left > 0 && !feof($fh)) {
                    $chunk = fread($fh, min(self::CHUNK, $left));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    echo $chunk;
                    $left -= strlen($chunk);
                    if (PHP_SAPI !== 'cli') {
                        flush();
                        if (connection_aborted() === 1) {
                            break;
                        }
                    }
                }
            } finally {
                fclose($fh);
            }
        };
    }
}
