<?php

declare(strict_types=1);

namespace Psc\Tests\Unit\News;

use Psc\News\Categories;
use Psc\News\Paths;
use Psc\Tests\TestCase;

/**
 * The one place Industry Updates addresses are made (Psc\News\Paths): the hub and its pages, the category hubs and
 * theirs, the articles under their primary category, the comment pages, the feeds, the sitemaps, and the slug rules
 * (readable, short, unique, never a word the routes use).
 */
final class PathsTest extends TestCase
{
    public function testEveryPageOfTheSiloHasOneAddress(): void
    {
        self::assertSame('/news/', Paths::index());
        self::assertSame('/news/', Paths::index(1));
        self::assertSame('/news/', Paths::index(0));
        self::assertSame('/news/page/2/', Paths::index(2));
        self::assertSame('/news/video/', Paths::category('video'));
        self::assertSame('/news/video/', Paths::category('video', 1));
        self::assertSame('/news/ai/page/12/', Paths::category('ai', 12));

        $row = ['slug' => 'genlock-firmware', 'category' => 'video', 'categories' => ['video', 'technology']];
        self::assertSame('/news/video/genlock-firmware/', Paths::article($row), 'under its primary category');
        self::assertSame('/news/video/genlock-firmware/comment/', Paths::comment($row));
        self::assertSame('/news/genlock-firmware/', Paths::permalink($row), 'the address it keeps whatever its category');
        self::assertSame('/news/genlock-firmware/', Paths::article(['slug' => 'genlock-firmware', 'category' => '']), 'a draft with no category yet');
        self::assertSame('/news/genlock-firmware/', Paths::article(['slug' => 'genlock-firmware', 'category' => 'rumours']));
        self::assertSame('/news/genlock-firmware/comment/', Paths::comment(['slug' => 'genlock-firmware']));

        self::assertSame('/news/feed.xml', Paths::feed());
        self::assertSame('/news/feed.xml', Paths::feed(''));
        self::assertSame('/news/legal/feed.xml', Paths::feed('legal'));
        self::assertSame('/news/go/12/', Paths::go(12));
        self::assertSame('/news/media/' . str_repeat('a', 32) . '-960.webp', Paths::media(str_repeat('a', 32) . '-960.webp'));
        self::assertSame('/portal/admin/news/media/' . str_repeat('a', 32) . '-og.jpg', Paths::media(str_repeat('a', 32) . '-og.jpg', true));
        self::assertSame('/news/sitemap.xml', Paths::sitemap());
        self::assertSame('/news/sitemap-2026-10.xml', Paths::sitemapMonth('2026-10'));
        self::assertSame('/news/sitemap-news.xml', Paths::sitemapNews());
        self::assertSame(implode('|', Categories::KEYS), Paths::categoryPattern());
    }

    public function testAnAddressOnTheSiteNamesItsArticleOrNothing(): void
    {
        self::assertSame(['category' => 'video', 'slug' => 'my-story', 'comment' => false], Paths::parse('/news/video/my-story/'));
        self::assertSame(['category' => 'legal', 'slug' => 'my-story', 'comment' => true], Paths::parse('/news/legal/my-story/comment/'));
        self::assertSame(['category' => null, 'slug' => 'my-story', 'comment' => false], Paths::parse('/news/my-story/'));
        self::assertSame(['category' => null, 'slug' => 'my-story', 'comment' => true], Paths::parse('/news/my-story/comment/'));
        foreach (['/news/', '/news/video/', '/news/page/2/', '/news/video/page/2/', '/news/video/page/', '/news/video/comment/', '/news/category/video/',
            '/news/go/3/', '/news/feed.xml', '/news/video/feed.xml', '/news/sitemap.xml', '/news/rumours/my-story/', '/news/my-story', '/news/My-Story/', '/portal/news/x/'] as $path) {
            self::assertNull(Paths::parse($path), $path);
        }
    }

    public function testASlugIsShortReadableAsciiWithoutStopWordsAtItsEnds(): void
    {
        self::assertSame('right-to-work-checks-now-cover-freelancers', Paths::slug('The right to work checks now cover freelancers'));
        self::assertSame('tale-of-two-cities', Paths::slug('A Tale of Two Cities'), 'stop words inside stay');
        self::assertSame('companion-5-1-prototype-rest-api-tokens-and-metrics', Paths::slug('Companion 5.1: prototype REST API, tokens and metrics'));
        self::assertSame('cafe-and-bar-for-the-win', Paths::slug('Café & bar, for the win'));
        self::assertSame('fixtures-are-cheaper', Paths::slug('Fixtures are cheaper: for'), 'none at the end either');
        foreach (Paths::STOP_WORDS as $word) {
            self::assertSame('lighting-desks', Paths::slug(ucfirst($word) . ' lighting desks ' . $word), $word);
        }
        self::assertSame('', Paths::slug('The and of'));
        self::assertSame('', Paths::slug('!!!'));

        // At most 60 characters, cut at a word, then trimmed of a stop word the cut left at its end.
        $long = Paths::slug('Wireless microphone licences change for the spring season and the booking deadline');
        self::assertSame('wireless-microphone-licences-change-for-the-spring-season', $long);
        self::assertLessThanOrEqual(Paths::SLUG_MAX, strlen(Paths::slug(str_repeat('Lighting consoles ', 20))));
        self::assertSame(60, strlen(Paths::slug(str_repeat('x', 90))), 'one long word is cut');
        self::assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Paths::slug('Zürich: 4K über alles, Ñandú'));
    }

    public function testNumberedSlugsStayWithinTheLimitAndReservedWordsAreNeverSlugs(): void
    {
        self::assertSame('genlock', Paths::numbered('genlock', 1));
        self::assertSame('genlock-2', Paths::numbered('genlock', 2));
        $base = 'wireless-microphone-licences-change-for-the-spring-season';
        self::assertSame('wireless-microphone-licences-change-for-the-spring-season-2', Paths::numbered($base, 2));
        self::assertSame('wireless-microphone-licences-change-for-the-spring-season-12', Paths::numbered($base . '-and-more', 12), 'words go so the number fits');
        self::assertSame('wireless-microphone-licences-change-for-the-spring-12', Paths::numbered('wireless-microphone-licences-change-for-the-spring-in-seasonal', 12), 'and a stop word the cut leaves');
        self::assertTrue(Paths::isNumberedFrom('genlock', 'genlock'));
        self::assertTrue(Paths::isNumberedFrom('genlock-3', 'genlock'));
        self::assertFalse(Paths::isNumberedFrom('genlock-firmware', 'genlock'));
        self::assertFalse(Paths::isNumberedFrom('genlock-0', 'genlock'));

        foreach ([...Paths::RESERVED, ...Categories::KEYS] as $word) {
            self::assertTrue(Paths::isReserved($word), $word);
        }
        self::assertFalse(Paths::isReserved('pages'));
        self::assertFalse(Paths::isReserved('video-2'));
        self::assertSame([], array_intersect(Paths::RESERVED, Categories::KEYS), 'no category key is a word the routes use');
    }
}
