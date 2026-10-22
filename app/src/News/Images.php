<?php

declare(strict_types=1);

namespace Psc\News;

use Psc\App;
use Psc\Db\Database;
use Psc\Support\Names;
use Psc\Support\Uploads;

/**
 * Article images (docs/NEWS.md §2.8), and the only writer of news_images. An upload is kept only when it is a PNG,
 * JPEG, WebP or GIF whose sniffed content agrees with its name (Uploads::store, into a folder of its own), and its
 * header (getimagesize) shows at most MAX_PIXELS, a shape an article can show, and a decoded size that fits the
 * memory PHP has left: all of that before GD decodes a single pixel. The picture is then drawn again from its pixels,
 * which leaves its metadata and anything else the file carried behind: WebP at each of ArticleStore::IMAGE_WIDTHS
 * narrower than the original, then one at the original's own width under the next name (never upscaled), and a
 * 1200 × 630 JPEG share card cropped from the centre. The uploaded file is deleted whatever happens.
 *
 * The files are <base>-480.webp, <base>-960.webp, <base>-1600.webp and <base>-og.jpg under storage/uploads/news/
 * (ArticleStore::imageFiles()); the row records which widths exist and the size of the largest. Position 0 is the
 * article's lead image.
 */
final class Images
{
    public const EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    /** The most pixels an upload may have (25 megapixels). */
    public const MAX_PIXELS = 25_000_000;

    /** The smallest picture an article shows, in pixels, as it is displayed. */
    public const MIN_WIDTH = 320;
    public const MIN_HEIGHT = 160;

    /** Neither side may be more than this many times the other. */
    public const MAX_RATIO = 6;

    /** The share card, the size Seo announces for og:image. */
    public const OG_WIDTH = 1200;
    public const OG_HEIGHT = 630;

    public const ALT_MAX = 300;
    public const CAPTION_MAX = 300;
    public const CREDIT_MAX = 160;
    public const CREDIT_URL_MAX = 500;

    public const ALT_REQUIRED = 'Describe the image for someone who cannot see it, in up to 300 characters.';

    private const WEBP_QUALITY = 82;
    private const JPEG_QUALITY = 84;

    /** GD holds four bytes a pixel; this much more is kept free for the decoder and PHP itself. */
    private const MEMORY_MARGIN = 8 * 1024 * 1024;

    /** Where an upload waits while it is checked; anything left there longer than this is swept away. */
    private const INCOMING = ArticleStore::IMAGE_DIR . '/incoming';
    private const INCOMING_SECONDS = 3600;

    /** A stored file's name, as the media routes accept it. */
    private const FILE = '/^[a-f0-9]{32}-(?:480|960|1600)\.webp$|^[a-f0-9]{32}-og\.jpg$/';

    /** What the share card shows behind transparent pixels: the site's dark room. */
    private const MATTE = [9, 12, 16];

    public function __construct(private readonly App $app)
    {
    }

    /** Whether this PHP can store article images: GD's WebP and JPEG writers and getimagesize (/health reports it). */
    public static function supported(): bool
    {
        return function_exists('imagewebp') && function_exists('imagejpeg') && function_exists('getimagesize')
            && function_exists('imagecreatetruecolor') && function_exists('imagecopyresampled');
    }

    /** The largest upload accepted: uploads.max_mb, capped by what PHP itself accepts in one request. */
    public function maxBytes(): int
    {
        $limit = max(1, $this->app->config->int('uploads.max_mb', 10)) * 1024 * 1024;
        foreach (['upload_max_filesize', 'post_max_size'] as $ini) {
            $bytes = Uploads::iniBytes((string) ini_get($ini));
            if ($bytes > 0) {
                $limit = min($limit, $bytes);
            }
        }
        return $limit;
    }

    /**
     * An image's words from a form: the alt text (required), caption, credit and the credit's web address, cleaned
     * as article text is (one line, no invisible characters, no em dash) and checked. A credit address needs a
     * credit to link from, and may not be on a banned site.
     *
     * @param array<string, mixed> $input alt, caption, credit, credit_url
     * @param list<string> $bannedDomains
     * @return array{0: array{alt: string, caption: ?string, credit: ?string, credit_url: ?string}, 1: array<string, string>}
     */
    public static function meta(array $input, array $bannedDomains = []): array
    {
        $line = static function (mixed $v): string {
            if (!is_string($v) || !mb_check_encoding($v, 'UTF-8')) {
                return '';
            }
            return trim(Prose::undash(Names::visible(trim((string) preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $v)))));
        };
        $values = [
            'alt' => $line($input['alt'] ?? ''),
            'caption' => $line($input['caption'] ?? ''),
            'credit' => $line($input['credit'] ?? ''),
            'credit_url' => $line($input['credit_url'] ?? ''),
        ];
        $errors = [];
        if ($values['alt'] === '' || mb_strlen($values['alt']) > self::ALT_MAX) {
            $errors['alt'] = self::ALT_REQUIRED;
        }
        if (mb_strlen($values['caption']) > self::CAPTION_MAX) {
            $errors['caption'] = 'Keep the caption to 300 characters.';
        }
        if (mb_strlen($values['credit']) > self::CREDIT_MAX) {
            $errors['credit'] = 'Keep the credit to 160 characters.';
        }
        foreach (['alt', 'caption', 'credit'] as $field) {
            if (!isset($errors[$field]) && Prose::emails($values[$field])) {
                $errors[$field] = Validator::CONTACT;
            }
        }
        $url = $values['credit_url'];
        if ($url !== '') {
            if (!preg_match('#^https?://#i', $url) || Urls::canonical($url) === null || mb_strlen($url) > self::CREDIT_URL_MAX) {
                $errors['credit_url'] = "Enter the credit's web address, starting https:// or http://, in up to 500 characters.";
            } elseif (Urls::onDomain($url, $bannedDomains)) {
                $errors['credit_url'] = Validator::BANNED;
            } elseif ($values['credit'] === '') {
                $errors['credit'] = 'Add the name to credit, or clear the web address.';
            }
        }
        return [[
            'alt' => $values['alt'],
            'caption' => $values['caption'] !== '' ? $values['caption'] : null,
            'credit' => $values['credit'] !== '' ? $values['credit'] : null,
            'credit_url' => $url !== '' ? $url : null,
        ], $errors];
    }

    /**
     * Checks, re-encodes and stores one upload for an article (null: not attached yet) with its words, and returns
     * the news_images row. On any refusal nothing is kept and errors says why, by field (file, alt, caption, credit,
     * credit_url); error is the first of them.
     *
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $upload one Uploads::files() entry
     * @param array<string, mixed> $meta alt, caption, credit, credit_url (as meta() reads them)
     * @param list<string> $bannedDomains
     * @return array{ok: true, image: array<string, mixed>}|array{ok: false, error: string, errors: array<string, string>}
     */
    public function store(array $upload, ?int $articleId, array $meta, ?int $userId, array $bannedDomains = []): array
    {
        [$words, $errors] = self::meta($meta, $bannedDomains);
        if ($errors !== []) {
            return self::refused($errors);
        }
        if (!self::supported()) {
            return self::refused(['file' => 'Images cannot be processed on this server: its PHP has no WebP or JPEG support. Ask the host to turn on GD with WebP.']);
        }
        $this->sweepIncoming();
        $stored = Uploads::store($upload, $this->app->storage->dir(self::INCOMING), self::EXTENSIONS, $this->maxBytes(), $this->app->config->string('app.env') === 'testing');
        if (!$stored['ok']) {
            return self::refused(['file' => $this->uploadError($upload, $stored['error'])]);
        }
        try {
            $result = $this->encode($stored['path'], strtolower(pathinfo($stored['stored_name'], PATHINFO_EXTENSION)));
        } finally {
            @unlink($stored['path']);
        }
        if (isset($result['error'])) {
            return self::refused(['file' => $result['error']]);
        }
        $db = $this->app->db();
        $position = $articleId !== null ? (int) $db->value('SELECT COALESCE(MAX(position) + 1, 0) FROM news_images WHERE article_id = ?', [$articleId]) : 0;
        $row = [
            'article_id' => $articleId,
            'position' => $position,
            'base' => $result['base'],
            'widths' => implode(',', $result['widths']),
            'width' => $result['width'],
            'height' => $result['height'],
            'alt' => $words['alt'],
            'caption' => $words['caption'],
            'credit' => $words['credit'],
            'credit_url' => $words['credit_url'],
            'original_name' => mb_substr($stored['original_name'], 0, 190),
            'bytes' => $stored['size'],
            'created_by' => $userId,
            'created_at' => time(),
        ];
        try {
            $row['id'] = $db->insert('news_images', $row);
        } catch (\Throwable $e) {
            $this->removeFiles($result['base']);
            throw $e;
        }
        return ['ok' => true, 'image' => $row];
    }

    /**
     * Changes an image's words; returns the fields that changed ([] for none), or the errors.
     *
     * @param array<string, mixed> $meta
     * @param list<string> $bannedDomains
     * @return array{changed: list<string>, errors: array<string, string>}
     */
    public function update(int $imageId, array $meta, array $bannedDomains = []): array
    {
        [$words, $errors] = self::meta($meta, $bannedDomains);
        $row = $this->find($imageId);
        if ($errors !== [] || $row === null) {
            return ['changed' => [], 'errors' => $row === null ? ['alt' => 'That image is no longer there.'] : $errors];
        }
        $changed = [];
        foreach ($words as $k => $v) {
            if ((string) ($row[$k] ?? '') !== (string) ($v ?? '')) {
                $changed[] = $k;
            }
        }
        if ($changed !== []) {
            $this->app->db()->update('news_images', $words, ['id' => $imageId]);
        }
        return ['changed' => $changed, 'errors' => []];
    }

    /**
     * Makes the image its article's lead (position 0), the others following in their order. False when it already
     * was, or is not one of the article's.
     */
    public function makeLead(int $articleId, int $imageId): bool
    {
        return (bool) $this->app->db()->transaction(static function (Database $db) use ($articleId, $imageId): bool {
            $ids = array_map('intval', array_column($db->all('SELECT id FROM news_images WHERE article_id = ? ORDER BY position, id', [$articleId]), 'id'));
            if (!in_array($imageId, $ids, true) || $ids[0] === $imageId) {
                return false;
            }
            $order = array_merge([$imageId], array_values(array_diff($ids, [$imageId])));
            foreach ($order as $position => $id) {
                $db->update('news_images', ['position' => $position], ['id' => $id]);
            }
            return true;
        });
    }

    /** @return array<string, mixed>|null */
    public function find(int $imageId): ?array
    {
        return $imageId > 0 ? $this->app->db()->one('SELECT * FROM news_images WHERE id = ?', [$imageId]) : null;
    }

    /**
     * Removes an image: its row, if it is still there, then its files. ArticleStore and the housekeeping call it
     * after removing the row themselves.
     *
     * @param array<string, mixed> $image a news_images row
     */
    public function delete(array $image): void
    {
        if (isset($image['id']) && (int) $image['id'] > 0) {
            $this->app->db()->exec('DELETE FROM news_images WHERE id = ?', [(int) $image['id']]);
        }
        $this->removeFiles((string) ($image['base'] ?? ''));
    }

    /**
     * The srcset of an image's WebP copies from the public media route, or with $admin the administrators' one:
     * each stored width, the widest described by its own size, since copies are never upscaled.
     *
     * @param array<string, mixed> $image a news_images row
     */
    public function srcset(array $image, bool $admin = false): string
    {
        $base = (string) ($image['base'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $base)) {
            return '';
        }
        $listed = array_map('intval', explode(',', (string) ($image['widths'] ?? '')));
        $largest = (int) ($image['width'] ?? 0);
        $file = Paths::media($base, $admin);
        $out = [];
        foreach (ArticleStore::IMAGE_WIDTHS as $w) {
            if (in_array($w, $listed, true)) {
                $out[] = $file . '-' . $w . '.webp ' . ($largest > 0 ? min($w, $largest) : $w) . 'w';
            }
        }
        return implode(', ', $out);
    }

    /** The stored file's path for a name the media routes serve (<base>-<width>.webp, <base>-og.jpg), when it exists. */
    public function file(string $name): ?string
    {
        if (!preg_match(self::FILE, $name)) {
            return null;
        }
        $path = $this->app->storage->path(ArticleStore::IMAGE_DIR . '/' . $name);
        return is_file($path) ? $path : null;
    }

    // ------------------------------------------------------------------------------------------------- internals

    /**
     * Reads an image's header, checks it, and writes its copies. Returns the base, the widths written and the size
     * of the largest, or the reason it was refused.
     *
     * @return array{base: string, widths: list<int>, width: int, height: int}|array{error: string}
     */
    private function encode(string $path, string $ext): array
    {
        $unreadable = 'The image could not be read. Save it again as a PNG or JPEG and upload it again.';
        $info = @getimagesize($path);
        if (!is_array($info) || (int) $info[0] < 1 || (int) $info[1] < 1) {
            return ['error' => $unreadable];
        }
        $w = (int) $info[0];
        $h = (int) $info[1];
        $type = (int) $info[2];
        $types = ['png' => IMAGETYPE_PNG, 'jpg' => IMAGETYPE_JPEG, 'jpeg' => IMAGETYPE_JPEG, 'gif' => IMAGETYPE_GIF, 'webp' => IMAGETYPE_WEBP];
        if (($types[$ext] ?? null) !== $type) {
            return ['error' => 'The file is not the kind of image its name says. Upload a PNG, JPEG, WebP or GIF image.'];
        }
        if ($w * $h > self::MAX_PIXELS) {
            return ['error' => 'The image is ' . self::size($w, $h) . ' pixels, more than the 25 megapixels allowed. Resize it and upload it again.'];
        }
        $orientation = $type === IMAGETYPE_JPEG ? self::orientation($path) : 1;
        [$dw, $dh] = $orientation >= 5 ? [$h, $w] : [$w, $h];
        if ($dw < self::MIN_WIDTH || $dh < self::MIN_HEIGHT) {
            return ['error' => 'The image is ' . self::size($dw, $dh) . ' pixels. Use one at least ' . self::MIN_WIDTH . ' pixels wide and ' . self::MIN_HEIGHT . ' pixels tall.'];
        }
        if ($dw > $dh * self::MAX_RATIO || $dh > $dw * self::MAX_RATIO) {
            return ['error' => 'The image is ' . self::size($dw, $dh) . ' pixels, too narrow or too wide for an article. Crop it so neither side is more than six times the other.'];
        }
        $largest = min($dw, max(ArticleStore::IMAGE_WIDTHS));
        $largestHeight = max(1, (int) round($dh * $largest / $dw));
        // The original, the largest copy and its turned twin, and the share card, all at once at the worst.
        $needed = 4 * ($w * $h + 2 * $largest * $largestHeight + self::OG_WIDTH * self::OG_HEIGHT) + self::MEMORY_MARGIN;
        $free = $this->memoryFree();
        if ($needed > $free) {
            // What would fit, judged as if the copies were as large as the original (never less than they are).
            $fits = intdiv(max(0, $free - self::MEMORY_MARGIN - 4 * self::OG_WIDTH * self::OG_HEIGHT), 12);
            return ['error' => 'The image is ' . self::size($w, $h) . ' pixels, too large for the server to process. Resize it to about '
                . max(1, intdiv($fits, 1_000_000)) . ' megapixels or fewer and upload it again.'];
        }

        $src = match ($type) {
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
        if (!$src instanceof \GdImage || imagesx($src) !== $w || imagesy($src) !== $h) {
            return ['error' => $unreadable];
        }
        if (!imageistruecolor($src)) {
            imagepalettetotruecolor($src);
        }
        // The largest copy is drawn from the original; the smaller ones and the share card from it.
        [$rw, $rh] = $orientation >= 5 ? [$largestHeight, $largest] : [$largest, $largestHeight];
        $big = self::resized($src, $rw, $rh);
        imagedestroy($src);
        $big = self::oriented($big, $orientation);

        $base = bin2hex(random_bytes(16));
        $dir = $this->app->storage->dir(ArticleStore::IMAGE_DIR);
        $widths = [];
        $ok = true;
        foreach (ArticleStore::IMAGE_WIDTHS as $target) {
            $file = $dir . '/' . $base . '-' . $target . '.webp';
            if ($target < $largest) {
                $copy = self::resized($big, $target, max(1, (int) round($largestHeight * $target / $largest)));
                $ok = $ok && @imagewebp($copy, $file, self::WEBP_QUALITY);
                imagedestroy($copy);
                $widths[] = $target;
                continue;
            }
            $ok = $ok && @imagewebp($big, $file, self::WEBP_QUALITY);
            $widths[] = $target;
            break;
        }
        $card = self::card($big);
        imagedestroy($big);
        imageinterlace($card, true);
        $ok = $ok && @imagejpeg($card, $dir . '/' . $base . '-og.jpg', self::JPEG_QUALITY);
        imagedestroy($card);
        if (!$ok) {
            $this->removeFiles($base);
            return ['error' => 'The image could not be stored. Please try again.'];
        }
        return ['base' => $base, 'widths' => $widths, 'width' => $largest, 'height' => $largestHeight];
    }

    /** A truecolour copy at $w × $h, transparency kept. */
    private static function resized(\GdImage $src, int $w, int $h): \GdImage
    {
        $dst = imagecreatetruecolor($w, $h);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, (int) imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));
        return $dst;
    }

    /** The share card: the picture cropped from its centre to OG_WIDTH × OG_HEIGHT, over the matte. */
    private static function card(\GdImage $src): \GdImage
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        $ratio = self::OG_WIDTH / self::OG_HEIGHT;
        if ($sw / $sh > $ratio) {
            $cw = max(1, (int) round($sh * $ratio));
            $ch = $sh;
        } else {
            $cw = $sw;
            $ch = max(1, (int) round($sw / $ratio));
        }
        $card = imagecreatetruecolor(self::OG_WIDTH, self::OG_HEIGHT);
        imagefill($card, 0, 0, (int) imagecolorallocate($card, ...self::MATTE));
        imagealphablending($card, true);
        imagecopyresampled($card, $src, 0, 0, intdiv($sw - $cw, 2), intdiv($sh - $ch, 2), self::OG_WIDTH, self::OG_HEIGHT, $cw, $ch);
        return $card;
    }

    /** The image turned and flipped as its EXIF orientation (1 to 8) says it should be seen. */
    private static function oriented(\GdImage $img, int $orientation): \GdImage
    {
        if ($orientation <= 1 || $orientation > 8) {
            return $img;
        }
        // imagerotate turns anticlockwise: 270 is a quarter turn clockwise.
        $turn = [3 => 180, 5 => 270, 6 => 270, 7 => 270, 8 => 90][$orientation] ?? 0;
        if ($turn !== 0) {
            $turned = imagerotate($img, $turn, 0);
            if ($turned instanceof \GdImage) {
                imagedestroy($img);
                $img = $turned;
                imagealphablending($img, false);
                imagesavealpha($img, true);
            }
        }
        $flip = [2 => IMG_FLIP_HORIZONTAL, 4 => IMG_FLIP_VERTICAL, 5 => IMG_FLIP_HORIZONTAL, 7 => IMG_FLIP_VERTICAL][$orientation] ?? null;
        if ($flip !== null) {
            imageflip($img, $flip);
        }
        return $img;
    }

    /**
     * A JPEG's EXIF orientation (1 to 8; 1 when it has none), read from the first APP1 segment's first IFD without
     * the exif extension: a camera's portrait photo is stored on its side with this tag saying so, and the copies
     * keep no metadata to say it for them.
     */
    public static function orientation(string $path): int
    {
        $data = (string) @file_get_contents($path, false, null, 0, 131072);
        if (strlen($data) < 4 || substr($data, 0, 2) !== "\xFF\xD8") {
            return 1;
        }
        $at = 2;
        while ($at + 4 <= strlen($data) && $data[$at] === "\xFF") {
            $marker = ord($data[$at + 1]);
            $length = (int) unpack('n', substr($data, $at + 2, 2))[1];
            if ($marker === 0xDA || $length < 2) {
                break;
            }
            if ($marker === 0xE1 && substr($data, $at + 4, 6) === "Exif\0\0") {
                return self::tiffOrientation(substr($data, $at + 10, $length - 8));
            }
            $at += 2 + $length;
        }
        return 1;
    }

    private static function tiffOrientation(string $tiff): int
    {
        if (strlen($tiff) < 8) {
            return 1;
        }
        $order = substr($tiff, 0, 2);
        if ($order !== 'II' && $order !== 'MM') {
            return 1;
        }
        $short = $order === 'II' ? 'v' : 'n';
        $long = $order === 'II' ? 'V' : 'N';
        $ifd = (int) unpack($long, substr($tiff, 4, 4))[1];
        if ($ifd + 2 > strlen($tiff)) {
            return 1;
        }
        $count = (int) unpack($short, substr($tiff, $ifd, 2))[1];
        for ($i = 0; $i < $count; $i++) {
            $entry = $ifd + 2 + $i * 12;
            if ($entry + 12 > strlen($tiff)) {
                break;
            }
            if ((int) unpack($short, substr($tiff, $entry, 2))[1] === 0x0112) {
                $value = (int) unpack($short, substr($tiff, $entry + 8, 2))[1];
                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }
        return 1;
    }

    /** Bytes PHP may still allocate under memory_limit (no limit: as many as an integer holds). */
    private function memoryFree(): int
    {
        $limit = (string) ini_get('memory_limit');
        $bytes = Uploads::iniBytes($limit);
        if ($bytes <= 0 || str_starts_with(trim($limit), '-')) {
            return PHP_INT_MAX;
        }
        return max(0, $bytes - memory_get_usage(true));
    }

    /** Uploads::store's refusal in an image form's words. */
    private function uploadError(array $upload, string $error): string
    {
        $ext = strtolower(pathinfo(Uploads::cleanName((string) ($upload['name'] ?? '')), PATHINFO_EXTENSION));
        if ((int) ($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return $error . ' Choose an image of up to ' . filesize_human($this->maxBytes()) . '.';
        }
        if (!in_array($ext, self::EXTENSIONS, true)) {
            return 'Upload a PNG, JPEG, WebP or GIF image.';
        }
        if (str_contains($error, 'does not match')) {
            return 'The file is not the kind of image its name says. Upload a PNG, JPEG, WebP or GIF image.';
        }
        if (str_starts_with($error, 'Each file must be')) {
            return 'Choose an image of up to ' . filesize_human($this->maxBytes()) . '.';
        }
        return $error;
    }

    private function removeFiles(string $base): void
    {
        foreach (ArticleStore::imageFiles(['base' => $base]) as $name) {
            $path = $this->app->storage->path(ArticleStore::IMAGE_DIR . '/' . $name);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** Uploads a stopped request left in the incoming folder (a process killed mid-check). */
    private function sweepIncoming(): void
    {
        $dir = $this->app->storage->path(self::INCOMING);
        if (!is_dir($dir)) {
            return;
        }
        $cutoff = time() - self::INCOMING_SECONDS;
        foreach (scandir($dir) ?: [] as $name) {
            $path = $dir . '/' . $name;
            if (preg_match('/^[a-f0-9]{32}\.[a-z]{3,4}$/', $name) && is_file($path) && (int) @filemtime($path) < $cutoff) {
                @unlink($path);
            }
        }
    }

    private static function size(int $w, int $h): string
    {
        return number_format($w) . " \u{00D7} " . number_format($h);
    }

    /**
     * @param array<string, string> $errors
     * @return array{ok: false, error: string, errors: array<string, string>}
     */
    private static function refused(array $errors): array
    {
        return ['ok' => false, 'error' => (string) reset($errors), 'errors' => $errors];
    }
}
