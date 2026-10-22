<?php

declare(strict_types=1);

namespace Psc\Tests\Feature\News;

use Psc\Controllers\NewsMediaController;
use Psc\News\ArticleStore;
use Psc\News\Images;
use Psc\Tests\TestCase;

/**
 * Article images: what an upload must be before GD decodes it, what is stored (WebP at each width up to the
 * original's, never upscaled, and a 1200 × 630 share card, with no byte of the upload kept), and the two media
 * routes: the public one serves live articles' images only, the same to everyone and cacheable; the administrators'
 * one serves any, never cached. Files, rows and headers first, words second.
 */
final class NewsMediaTest extends TestCase
{
    private function images(): Images
    {
        return new Images($this->app);
    }

    /** A real picture drawn with GD: the left half red, the right half blue (or see-through with $alpha). */
    private static function picture(int $w, int $h, string $ext = 'png', bool $alpha = false): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        $left = $alpha ? (int) imagecolorallocatealpha($im, 255, 0, 0, 127) : (int) imagecolorallocate($im, 255, 0, 0);
        imagefilledrectangle($im, 0, 0, intdiv($w, 2) - 1, $h - 1, $left);
        imagefilledrectangle($im, intdiv($w, 2), 0, $w - 1, $h - 1, (int) imagecolorallocate($im, 0, 0, 255));
        ob_start();
        match ($ext) {
            'jpg' => imagejpeg($im, null, 92),
            'webp' => imagewebp($im, null, 90),
            'gif' => imagegif($im),
            default => imagepng($im),
        };
        imagedestroy($im);
        return (string) ob_get_clean();
    }

    /** A small real PNG whose header claims $w × $h pixels: getimagesize believes it, GD would try to decode it. */
    private static function pngClaiming(int $w, int $h): string
    {
        $png = self::picture(8, 8);
        $chunk = 'IHDR' . pack('NN', $w, $h) . substr($png, 24, 5);
        return substr($png, 0, 12) . $chunk . pack('N', crc32($chunk)) . substr($png, 33);
    }

    /** A JPEG carrying an EXIF orientation tag, in an APP1 segment after its JFIF one, as cameras write them. */
    private static function jpegTurned(int $w, int $h, int $orientation): string
    {
        $jpeg = self::picture($w, $h, 'jpg');
        $tiff = 'II' . pack('v', 42) . pack('V', 8) . pack('v', 1) . pack('vvVvv', 0x0112, 3, 1, $orientation, 0) . pack('V', 0);
        $app1 = "Exif\0\0" . $tiff;
        $after = 2;
        if (substr($jpeg, 2, 2) === "\xFF\xE0") {
            $after += 2 + (int) unpack('n', substr($jpeg, 4, 2))[1];
        }
        return substr($jpeg, 0, $after) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, $after);
    }

    /** @return array<string, mixed> the stored row */
    private function store(string $bytes, string $name, ?int $articleId, array $meta = []): array
    {
        $r = $this->images()->store($this->fakeUpload($name, $bytes), $articleId, $meta + ['alt' => 'A test picture'], null);
        self::assertTrue($r['ok'], $name . ': ' . ($r['error'] ?? ''));
        return $r['image'];
    }

    /** @return array{ok: false, error: string, errors: array<string, string>} */
    private function refuse(string $bytes, string $name): array
    {
        $r = $this->images()->store($this->fakeUpload($name, $bytes), null, ['alt' => 'A test picture'], null);
        self::assertFalse($r['ok'], $name . ' is refused');
        self::assertSame(['file'], array_keys($r['errors']), $name);
        return $r;
    }

    private function dir(): string
    {
        return $this->storage . '/' . ArticleStore::IMAGE_DIR;
    }

    /** @return list<string> the stored image files (the incoming folder aside) */
    private function storedFiles(): array
    {
        return array_values(array_filter(scandir($this->dir()) ?: [], fn (string $n): bool => is_file($this->dir() . '/' . $n)));
    }

    private function assertNothingKept(): void
    {
        self::assertSame([], is_dir($this->dir()) ? $this->storedFiles() : [], 'no image file is kept');
        $incoming = $this->dir() . '/incoming';
        self::assertSame([], is_dir($incoming) ? array_values(array_diff(scandir($incoming) ?: [], ['.', '..'])) : [], 'the upload is gone');
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM news_images'));
    }

    /** @return array{0: int, 1: int, 2: int} width, height and type of a stored file */
    private function sizeOf(string $name): array
    {
        $info = getimagesize($this->dir() . '/' . $name);
        self::assertIsArray($info, $name);
        return [(int) $info[0], (int) $info[1], (int) $info[2]];
    }

    // ------------------------------------------------------------------------------------------------- storing

    public function testAnImageIsStoredAsWebpAtEveryWidthUpToItsOwnAndAShareCard(): void
    {
        $article = $this->makeArticle();
        $big = $this->store(self::picture(2400, 1200), 'wall.png', $article, ['caption' => 'The wall', 'credit' => 'Maker Ltd', 'credit_url' => 'https://maker.example/press']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $big['base']);
        self::assertSame(['480,960,1600', 1600, 800, 0, 'wall.png'], [$big['widths'], $big['width'], $big['height'], $big['position'], $big['original_name']]);
        $row = $this->db->one('SELECT * FROM news_images WHERE id = ?', [$big['id']]);
        self::assertSame($article, (int) $row['article_id']);
        self::assertSame(['A test picture', 'The wall', 'Maker Ltd', 'https://maker.example/press'], [$row['alt'], $row['caption'], $row['credit'], $row['credit_url']]);
        self::assertSame([480, 240, IMAGETYPE_WEBP], $this->sizeOf($big['base'] . '-480.webp'));
        self::assertSame([960, 480, IMAGETYPE_WEBP], $this->sizeOf($big['base'] . '-960.webp'));
        self::assertSame([1600, 800, IMAGETYPE_WEBP], $this->sizeOf($big['base'] . '-1600.webp'));
        self::assertSame([1200, 630, IMAGETYPE_JPEG], $this->sizeOf($big['base'] . '-og.jpg'));
        $mine = array_values(array_filter($this->storedFiles(), static fn (string $n): bool => str_starts_with($n, $big['base'])));
        $named = ArticleStore::imageFiles($big);
        sort($mine);
        sort($named);
        self::assertSame($named, $mine, 'exactly the files the naming scheme names');

        // Never upscaled: a smaller original keeps its own width under the next name, and no larger copy exists.
        $mid = $this->store(self::picture(1200, 800, 'jpg'), 'rack.jpg', $article);
        self::assertSame(['480,960,1600', 1200, 800, 1], [$mid['widths'], $mid['width'], $mid['height'], $mid['position']]);
        self::assertSame([1200, 800, IMAGETYPE_WEBP], $this->sizeOf($mid['base'] . '-1600.webp'));
        $small = $this->store(self::picture(800, 600, 'webp'), 'desk.webp', $article);
        self::assertSame(['480,960', 800, 600], [$small['widths'], $small['width'], $small['height']]);
        self::assertSame([800, 600, IMAGETYPE_WEBP], $this->sizeOf($small['base'] . '-960.webp'));
        self::assertFileDoesNotExist($this->dir() . '/' . $small['base'] . '-1600.webp');
        $tiny = $this->store(self::picture(400, 300, 'gif'), 'logo.gif', $article);
        self::assertSame(['480', 400, 300], [$tiny['widths'], $tiny['width'], $tiny['height']]);
        self::assertSame([400, 300, IMAGETYPE_WEBP], $this->sizeOf($tiny['base'] . '-480.webp'));
        self::assertSame([1200, 630, IMAGETYPE_JPEG], $this->sizeOf($tiny['base'] . '-og.jpg'), 'the share card is always its full size');

        self::assertSame([0, 1, 2, 3], array_map('intval', array_column($this->db->all('SELECT position FROM news_images ORDER BY id'), 'position')), 'each new image goes last; the first is the lead');
        self::assertSame([], array_values(array_diff(scandir($this->dir() . '/incoming') ?: [], ['.', '..'])), 'no upload is kept');
    }

    public function testThePictureIsRedrawnSoNothingTheFileCarriedSurvives(): void
    {
        // A PNG with PHP in a text chunk and after its end, and a JPEG with PHP in a comment: images to every check.
        $png = self::picture(640, 360);
        $text = 'tEXt' . "Comment\0" . '<?php system($_GET["c"]); ?>';
        $png = substr($png, 0, -12) . pack('N', strlen($text) - 4) . $text . pack('N', crc32($text)) . substr($png, -12) . '<?php echo "tail"; ?>';
        $jpeg = self::picture(640, 360, 'jpg');
        $comment = '<?php passthru("id"); ?>';
        $jpeg = substr($jpeg, 0, 2) . "\xFF\xFE" . pack('n', strlen($comment) + 2) . $comment . substr($jpeg, 2);
        $stored = [$this->store($png, 'poly.png', null), $this->store($jpeg, 'poly.jpg', null)];
        foreach ($stored as $image) {
            foreach (ArticleStore::imageFiles($image) as $name) {
                $path = $this->dir() . '/' . $name;
                if (!is_file($path)) {
                    continue;
                }
                $bytes = (string) file_get_contents($path);
                self::assertStringNotContainsString('<?php', $bytes, $name);
                self::assertStringNotContainsString('system(', $bytes, $name);
                self::assertStringNotContainsString('passthru', $bytes, $name);
                self::assertStringNotContainsString('Comment', $bytes, $name);
            }
        }
    }

    public function testAPhotoStoredOnItsSideIsTurnedTheWayItIsSeen(): void
    {
        $red = static fn (int $rgb): bool => (($rgb >> 16) & 0xFF) > 180 && ($rgb & 0xFF) < 90;
        $blue = static fn (int $rgb): bool => ($rgb & 0xFF) > 180 && (($rgb >> 16) & 0xFF) < 90;
        // 6: a quarter turn clockwise (the left half goes to the top); 8: anticlockwise; 3: a half turn.
        foreach ([6 => [400, 800, 'top'], 8 => [400, 800, 'bottom'], 3 => [800, 400, 'right'], 1 => [800, 400, 'left']] as $orientation => [$w, $h, $where]) {
            $bytes = self::jpegTurned(800, 400, $orientation);
            $path = tempnam(sys_get_temp_dir(), 'psc-jpg');
            file_put_contents($path, $bytes);
            self::assertSame($orientation, Images::orientation($path));
            @unlink($path);
            $image = $this->store($bytes, 'side.jpg', null);
            $name = $image['base'] . '-' . ($w > 480 ? 960 : 480) . '.webp';
            self::assertSame([$w, $h], array_slice($this->sizeOf($name), 0, 2), 'orientation ' . $orientation);
            $im = imagecreatefromwebp($this->dir() . '/' . $name);
            self::assertInstanceOf(\GdImage::class, $im);
            [$first, $second] = $where === 'top' || $where === 'bottom'
                ? [imagecolorat($im, intdiv($w, 2), intdiv($h, 4)), imagecolorat($im, intdiv($w, 2), intdiv(3 * $h, 4))]
                : [imagecolorat($im, intdiv($w, 4), intdiv($h, 2)), imagecolorat($im, intdiv(3 * $w, 4), intdiv($h, 2))];
            $redFirst = $where === 'top' || $where === 'left';
            self::assertTrue($redFirst ? $red($first) && $blue($second) : $blue($first) && $red($second), 'orientation ' . $orientation . ': red is at the ' . $where);
        }
        $png = tempnam(sys_get_temp_dir(), 'psc-png');
        file_put_contents($png, self::picture(10, 10));
        self::assertSame(1, Images::orientation($png), 'a file that is not a JPEG has no orientation');
        @unlink($png);
    }

    public function testTransparencyIsKeptInTheCopiesAndFilledOnTheShareCard(): void
    {
        $image = $this->store(self::picture(1000, 500, 'png', true), 'cutout.png', null);
        $copy = imagecreatefromwebp($this->dir() . '/' . $image['base'] . '-480.webp');
        self::assertInstanceOf(\GdImage::class, $copy);
        self::assertGreaterThan(100, (imagecolorat($copy, 60, 120) >> 24) & 0x7F, 'the see-through half stays see-through');
        $card = imagecreatefromjpeg($this->dir() . '/' . $image['base'] . '-og.jpg');
        self::assertInstanceOf(\GdImage::class, $card);
        $rgb = imagecolorat($card, 100, 300);
        self::assertLessThan(40, ($rgb >> 16) & 0xFF, 'the share card shows the dark room behind it, not black or white noise');
        self::assertLessThan(40, $rgb & 0xFF);
    }

    // ------------------------------------------------------------------------------------------------ refusals

    public function testAnImageOverTwentyFiveMegapixelsIsRefusedBeforeItIsDecoded(): void
    {
        $r = $this->refuse(self::pngClaiming(10000, 10000), 'bomb.png');
        self::assertSame("The image is 10,000 \u{00D7} 10,000 pixels, more than the 25 megapixels allowed. Resize it and upload it again.", $r['errors']['file']);
        $this->assertNothingKept();
        // Just under the limit gets past this check (and is then refused for what it really holds).
        self::assertStringNotContainsString('25 megapixels', $this->refuse(self::pngClaiming(5000, 5000), 'big.png')['error']);
        $this->assertNothingKept();
    }

    public function testAnImageThatWouldNotFitInMemoryIsRefusedBeforeItIsDecoded(): void
    {
        $limit = (string) ini_get('memory_limit');
        try {
            ini_set('memory_limit', (string) (memory_get_usage(true) + 48 * 1024 * 1024));
            $r = $this->refuse(self::pngClaiming(5000, 4000), 'large.png');
            self::assertMatchesRegularExpression("/^The image is 5,000 \u{00D7} 4,000 pixels, too large for the server to process\\. Resize it to about \\d+ megapixels or fewer and upload it again\\.$/u", $r['error']);
        } finally {
            ini_set('memory_limit', $limit);
        }
        $this->assertNothingKept();
    }

    public function testFilesThatAreNotImagesAreRefused(): void
    {
        $wrong = 'The file is not the kind of image its name says. Upload a PNG, JPEG, WebP or GIF image.';
        $cases = [
            'notes.png' => ['Show notes, not a picture.', $wrong],
            'shell.png' => ["<?php system(\$_GET['c']); ?>", $wrong],
            'shell.php' => ["<?php echo 'x'; ?>", 'Upload a PNG, JPEG, WebP or GIF image.'],
            'drawing.svg' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'Upload a PNG, JPEG, WebP or GIF image.'],
            'renamed.jpg' => [self::picture(640, 360, 'png'), $wrong],
            // A GIF header in front of a script: the sniffer calls it a GIF, but there is no picture in it.
            'poly.gif' => ["GIF89a/*\x01\x00\x01\x00*/=1;alert(document.domain);//" . str_repeat("\x00", 32), null],
        ];
        foreach ($cases as $name => [$bytes, $message]) {
            $r = $this->refuse($bytes, $name);
            if ($message !== null) {
                self::assertSame($message, $r['error'], $name);
            }
        }
        $this->assertNothingKept();
        $empty = $this->images()->store($this->fakeUpload('empty.png', ''), null, ['alt' => 'Nothing'], null);
        self::assertFalse($empty['ok']);
        self::assertStringStartsWith('Choose an image of up to ', $empty['errors']['file']);
    }

    public function testPicturesTooSmallOrTooLongForAnArticleAreRefused(): void
    {
        self::assertSame("The image is 300 \u{00D7} 200 pixels. Use one at least 320 pixels wide and 160 pixels tall.", $this->refuse(self::picture(300, 200), 'small.png')['error']);
        self::assertStringContainsString('too narrow or too wide for an article', $this->refuse(self::picture(2100, 300), 'strip.png')['error']);
        self::assertStringContainsString('too narrow or too wide for an article', $this->refuse(self::picture(320, 2000), 'tower.png')['error']);
        $this->assertNothingKept();
    }

    public function testTheWordsAreCheckedAndCleaned(): void
    {
        [$values, $errors] = Images::meta(['alt' => "  The wall \u{2014} at load-in  ", 'caption' => '', 'credit' => 'Maker Ltd', 'credit_url' => 'https://maker.example/press']);
        self::assertSame([], $errors);
        self::assertSame(['alt' => 'The wall, at load-in', 'caption' => null, 'credit' => 'Maker Ltd', 'credit_url' => 'https://maker.example/press'], $values);

        $cases = [
            [['alt' => ''], 'alt', Images::ALT_REQUIRED],
            [['alt' => str_repeat('a', 301)], 'alt', Images::ALT_REQUIRED],
            [['alt' => 'ok', 'caption' => str_repeat('c', 301)], 'caption', 'Keep the caption to 300 characters.'],
            [['alt' => 'ok', 'credit' => str_repeat('c', 161)], 'credit', 'Keep the credit to 160 characters.'],
            [['alt' => 'ok', 'credit_url' => 'https://maker.example/'], 'credit', 'Add the name to credit, or clear the web address.'],
            [['alt' => 'ok', 'credit' => 'Maker', 'credit_url' => 'javascript:alert(1)'], 'credit_url', "Enter the credit's web address, starting https:// or http://, in up to 500 characters."],
            [['alt' => 'ok', 'credit' => 'Maker', 'credit_url' => 'https://banned.example/x'], 'credit_url', 'That site is on the banned list.'],
            [['alt' => 'Mail press@maker.example for the file'], 'alt', 'Remove the email address; link to a page instead.'],
        ];
        foreach ($cases as [$input, $field, $message]) {
            self::assertSame($message, Images::meta($input, ['banned.example'])[1][$field] ?? null, $field . ': ' . json_encode($input));
        }
        $r = $this->images()->store($this->fakeUpload('wall.png', self::picture(640, 360)), null, ['alt' => ''], null);
        self::assertSame(['alt' => Images::ALT_REQUIRED], $r['errors']);
        $this->assertNothingKept();
    }

    // ------------------------------------------------------------------------------------------ the routes

    public function testALiveArticlesImageIsServedTheSameToEveryoneAndCachedForAnHour(): void
    {
        $image = $this->store(self::picture(2000, 1000), 'wall.png', $this->makeArticle());
        $this->signInAdmin();
        foreach (['480.webp' => 'image/webp', '960.webp' => 'image/webp', '1600.webp' => 'image/webp', 'og.jpg' => 'image/jpeg'] as $suffix => $type) {
            $r = $this->request('GET', '/news/media/' . $image['base'] . '-' . $suffix);
            self::assertSame(200, $r->status, $suffix);
            self::assertSame($type, $r->getHeader('Content-Type'));
            self::assertSame(NewsMediaController::PUBLIC_CACHE, $r->getHeader('Cache-Control'));
            self::assertStringNotContainsString('immutable', (string) $r->getHeader('Cache-Control'));
            self::assertSame('nosniff', $r->getHeader('X-Content-Type-Options'));
            self::assertSame("default-src 'none'", $r->getHeader('Content-Security-Policy'));
            self::assertSame([], $r->cookies(), 'no session, even with an administrator signed in');
            $bytes = (string) file_get_contents($this->dir() . '/' . $image['base'] . '-' . $suffix);
            self::assertSame((string) strlen($bytes), $r->getHeader('Content-Length'));
            self::assertSame($bytes, $r->captureStream());
        }
    }

    public function testImagesOfArticlesThatAreNotLiveAndNamesThatAreNotStoredAreNotFound(): void
    {
        $draft = $this->store(self::picture(800, 400), 'a.png', $this->makeArticle(['status' => 'draft']));
        $scheduled = $this->store(self::picture(800, 400), 'b.png', $this->makeArticle(['published_at' => time() + 3600]));
        $loose = $this->store(self::picture(800, 400), 'c.png', null);
        $taken = $this->makeArticle();
        $down = $this->store(self::picture(800, 400), 'd.png', $taken);
        self::assertSame(200, $this->request('GET', '/news/media/' . $down['base'] . '-480.webp')->status);
        (new ArticleStore($this->app))->unpublish($taken);
        $live = $this->store(self::picture(800, 400), 'e.png', $this->makeArticle());
        foreach ([
            $draft['base'] . '-480.webp',
            $scheduled['base'] . '-480.webp',
            $loose['base'] . '-480.webp',
            $down['base'] . '-480.webp',
            $live['base'] . '-1600.webp',
            $live['base'] . '-og.webp',
            $live['base'] . '-123.webp',
            $live['base'] . '-480.jpg',
            str_repeat('a', 32) . '-480.webp',
        ] as $file) {
            $r = $this->request('GET', '/news/media/' . $file);
            self::assertSame(404, $r->status, $file);
            self::assertSame('private, no-store', $r->getHeader('Cache-Control'), $file);
            self::assertSame([], $r->cookies(), $file);
            self::assertStringContainsString('noindex', (string) $r->getHeader('X-Robots-Tag'));
        }
        self::assertNotSame(200, $this->request('GET', '/news/media/' . strtoupper($live['base']) . '-480.webp')->status);
        self::assertSame(404, $this->request('GET', '/news/media/../' . $live['base'] . '-480.webp')->status);
    }

    public function testAdministratorsSeeAnyStoredImageAndNothingIsCached(): void
    {
        $draft = $this->store(self::picture(800, 400), 'a.png', $this->makeArticle(['status' => 'draft']));
        $path = '/portal/admin/news/media/' . $draft['base'] . '-960.webp';
        $r = $this->request('GET', $path);
        self::assertSame(302, $r->status);
        self::assertSame('/portal/login/?next=' . rawurlencode($path), $r->getHeader('Location'));
        $this->signInClient();
        self::assertSame(403, $this->request('GET', $path)->status);

        $_SESSION = [];
        $this->jar = [];
        $this->signInAdmin();
        $r = $this->request('GET', $path);
        self::assertSame(200, $r->status);
        self::assertSame('image/webp', $r->getHeader('Content-Type'));
        self::assertSame('private, no-store', $r->getHeader('Cache-Control'));
        self::assertSame('nosniff', $r->getHeader('X-Content-Type-Options'));
        self::assertSame("default-src 'none'", $r->getHeader('Content-Security-Policy'));
        self::assertSame((string) file_get_contents($this->dir() . '/' . $draft['base'] . '-960.webp'), $r->captureStream());
        self::assertSame(404, $this->request('GET', '/portal/admin/news/media/' . $draft['base'] . '-1600.webp')->status, 'a copy that was never made');
        self::assertSame(404, $this->request('GET', '/portal/admin/news/media/' . str_repeat('b', 32) . '-480.webp')->status);
        // A file under the naming scheme that no image row names (a stopped upload) is not served either.
        $stray = str_repeat('c', 32);
        copy($this->dir() . '/' . $draft['base'] . '-480.webp', $this->dir() . '/' . $stray . '-480.webp');
        self::assertSame(404, $this->request('GET', '/portal/admin/news/media/' . $stray . '-480.webp')->status);
    }

    // ----------------------------------------------------------------------------------- the rest of the API

    public function testTheSrcsetDescribesEachCopyByItsOwnWidth(): void
    {
        $base = str_repeat('d', 32);
        $images = $this->images();
        self::assertSame("/news/media/{$base}-480.webp 480w, /news/media/{$base}-960.webp 960w, /news/media/{$base}-1600.webp 1600w", $images->srcset(['base' => $base, 'widths' => '480,960,1600', 'width' => 1600]));
        self::assertSame("/portal/admin/news/media/{$base}-480.webp 480w, /portal/admin/news/media/{$base}-960.webp 800w", $images->srcset(['base' => $base, 'widths' => '480,960', 'width' => 800], true));
        self::assertSame("/news/media/{$base}-480.webp 400w", $images->srcset(['base' => $base, 'widths' => '480', 'width' => 400]));
        self::assertSame('', $images->srcset(['base' => 'not-a-base', 'widths' => '480', 'width' => 400]));
        self::assertTrue(Images::supported());
    }

    public function testFileNamesOnlyTheStoredCopies(): void
    {
        $image = $this->store(self::picture(800, 400), 'a.png', null);
        $images = $this->images();
        self::assertSame($this->dir() . '/' . $image['base'] . '-480.webp', $images->file($image['base'] . '-480.webp'));
        self::assertSame($this->dir() . '/' . $image['base'] . '-og.jpg', $images->file($image['base'] . '-og.jpg'));
        foreach ([$image['base'] . '-1600.webp', $image['base'] . '-480.webp/', '../' . $image['base'] . '-480.webp', $image['base'] . '.png', $image['base'] . '-480.webp.php', 'incoming', ''] as $name) {
            self::assertNull($images->file($name), $name);
        }
    }

    public function testDeletingAnImageTakesItsRowAndEveryFile(): void
    {
        $article = $this->makeArticle();
        $one = $this->store(self::picture(2000, 1000), 'a.png', $article);
        $two = $this->store(self::picture(2000, 1000), 'b.png', $article);
        $images = $this->images();
        $images->delete($one);
        self::assertNull($images->find((int) $one['id']));
        self::assertSame([], array_values(array_filter($this->storedFiles(), static fn (string $n): bool => str_starts_with($n, $one['base']))));
        $images->delete($one);
        self::assertCount(4, $this->storedFiles(), 'a second delete of the same image is harmless');
        // Deleting the article takes its images' files with it.
        (new ArticleStore($this->app))->delete($article);
        self::assertSame([], $this->storedFiles());
        self::assertNull($images->find((int) $two['id']));
    }

    public function testTheLeadIsTheImageAtThePositionZero(): void
    {
        $article = $this->makeArticle();
        $ids = [];
        foreach (['a', 'b', 'c'] as $n) {
            $ids[] = (int) $this->store(self::picture(800, 400), $n . '.png', $article)['id'];
        }
        $images = $this->images();
        self::assertFalse($images->makeLead($article, $ids[0]), 'it already is');
        self::assertFalse($images->makeLead($this->makeArticle(), $ids[1]), 'another article\'s image');
        self::assertTrue($images->makeLead($article, $ids[2]));
        self::assertSame([$ids[2], $ids[0], $ids[1]], array_map(static fn (array $i): int => (int) $i['id'], (new ArticleStore($this->app))->images($article)));
        self::assertSame([0, 1, 2], array_map('intval', array_column((new ArticleStore($this->app))->images($article), 'position')));

        $changed = $images->update($ids[1], ['alt' => 'A new description', 'caption' => 'Now captioned']);
        self::assertSame(['changed' => ['alt', 'caption'], 'errors' => []], $changed);
        self::assertSame(['changed' => [], 'errors' => []], $images->update($ids[1], ['alt' => 'A new description', 'caption' => 'Now captioned']));
        self::assertSame(['alt' => Images::ALT_REQUIRED], $images->update($ids[1], ['alt' => ''])['errors']);
        self::assertSame('A new description', $images->find($ids[1])['alt']);
    }
}
