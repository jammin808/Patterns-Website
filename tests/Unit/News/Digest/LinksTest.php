<?php

declare(strict_types=1);

namespace Psc\Tests\Unit\News\Digest;

use PHPUnit\Framework\TestCase;
use Psc\News\Digest\Links;

/**
 * The digest's addresses (docs/NEWS.md §14.3, Links): lower-case paths with their trailing slash, the token in the
 * query under "token", the static campaign tags exactly, and the mailto whose subject carries the token.
 */
final class LinksTest extends TestCase
{
    private const TOKEN = '0123456789abcdef01234567.Yp3kV0s9qJx_7mN2aL-cR8tWf4hZ1uE6oB5iD0gTnQs';

    public function testThePaths(): void
    {
        self::assertSame('/digest/', Links::SIGNUP);
        self::assertSame('/digest/confirm/', Links::CONFIRM);
        self::assertSame('/digest/unsubscribe/', Links::UNSUBSCRIBE);
        foreach ([Links::SIGNUP, Links::CONFIRM, Links::UNSUBSCRIBE] as $path) {
            self::assertSame(strtolower($path), $path, 'the router lower-cases paths, so they are lower case already');
            self::assertStringEndsWith('/', $path, 'the routes\' own slash, so no 301 answers them');
        }
    }

    public function testTheTokenRidesInTheQuery(): void
    {
        self::assertSame('/digest/confirm/?token=' . self::TOKEN, Links::confirm(self::TOKEN));
        self::assertSame('/digest/unsubscribe/?token=' . self::TOKEN, Links::unsubscribe(self::TOKEN));
        self::assertSame('/digest/unsubscribe/?token=a%2Bb%2Fc%3D%26x%3D1%20y', Links::unsubscribe('a+b/c=&x=1 y'), 'anything else in a token is encoded');
        parse_str((string) parse_url(Links::unsubscribe(self::TOKEN), PHP_URL_QUERY), $query);
        self::assertSame(['token' => self::TOKEN], $query);
    }

    public function testTheCampaignTags(): void
    {
        self::assertSame('/news/?utm_source=digest&utm_medium=email&utm_campaign=2026-w43', Links::campaign('/news/', '2026-w43'));
        self::assertSame('/news/video/a-story/?utm_source=digest&utm_medium=email&utm_campaign=2026-w43', Links::campaign('/news/video/a-story/', '2026-w43'));
        self::assertSame('/news/?page=2&utm_source=digest&utm_medium=email&utm_campaign=2026-w43', Links::campaign('/news/?page=2', '2026-w43'), 'after & when it has a query');
        self::assertSame('/news/?utm_source=digest&utm_medium=email&utm_campaign=2026-w43#comments', Links::campaign('/news/#comments', '2026-w43'), 'a fragment stays at the end');
    }

    public function testTheMailto(): void
    {
        self::assertSame('mailto:unsubscribe@patternsshowcontrol.co.uk?subject=Unsubscribe%20' . self::TOKEN, Links::mailto('unsubscribe@patternsshowcontrol.co.uk', self::TOKEN));
    }
}
