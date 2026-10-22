<?php

declare(strict_types=1);

namespace Psc\Tests\Unit\News;

use Psc\Content\Content;
use Psc\News\Categories;
use Psc\News\Draft;
use Psc\Tests\TestCase;

/** The fixed categories and the section's words in content/news.php: the keys, the lengths search engines show. */
final class CategoriesTest extends TestCase
{
    public function testTheFourteenKeysAndTheirNamesAreFixed(): void
    {
        self::assertSame([
            'video' => 'Video', 'audio' => 'Audio', 'lighting' => 'Lighting', 'staging' => 'Staging',
            'production' => 'Production', 'showcalling' => 'Showcalling', 'streaming' => 'Streaming',
            'automation' => 'Automation', 'interactive' => 'Interactive', 'ai' => 'AI', 'technology' => 'Technology',
            'freelance' => 'Freelance', 'legal' => 'Legal', 'tips' => 'Tips',
        ], array_map(static fn (array $c): string => $c['name'], Categories::all()));
        self::assertSame(Categories::KEYS, array_keys(Content::get('news')['categories']), 'the content file lists them all, in order');
        self::assertTrue(Categories::has('ai'));
        self::assertFalse(Categories::has('AI'));
        self::assertFalse(Categories::has('rumours'));
        self::assertSame('Showcalling', Categories::name('showcalling'));
        self::assertSame('', Categories::name('nope'));
        self::assertNull(Categories::get('nope'));
    }

    public function testEachCategoryPageHasATitleDescriptionIntroAndIcon(): void
    {
        $sprite = (string) file_get_contents(PSC_ROOT . '/public/assets/img/icons.svg');
        foreach (Categories::all() as $key => $c) {
            self::assertLessThanOrEqual(60, mb_strlen($c['title']), $key . ': the title tag');
            self::assertStringEndsWith(' | Industry Updates', $c['title'], $key);
            self::assertGreaterThanOrEqual(50, mb_strlen($c['description']), $key . ': the description');
            self::assertLessThanOrEqual(155, mb_strlen($c['description']), $key . ': the description');
            self::assertNotSame('', $c['intro'], $key);
            self::assertLessThanOrEqual(2, preg_match_all('/[.!?](?:\s|$)/', $c['intro']), $key . ': one or two sentences');
            self::assertStringContainsString('id="i-' . $c['icon'] . '"', $sprite, $key . ': the icon is in the sprite');
            self::assertSame(mb_strtoupper(mb_substr($c['description'], 0, 1)) . mb_substr($c['description'], 1), $c['description'], $key . ': sentence case');
        }
    }

    public function testTheSectionsWordsAreComplete(): void
    {
        $news = Content::get('news');
        self::assertSame('Industry Updates desk', $news['byline']);
        self::assertSame($news['byline'], Draft::deskByline());
        self::assertSame(Draft::VERDICTS, array_keys($news['verdicts']));
        self::assertSame(
            ['Worth adopting', 'Worth testing', 'One to watch', 'Safe to skip for now', 'Good to know'],
            array_values(array_map(static fn (array $v): string => $v['label'], $news['verdicts']))
        );
        self::assertSame(['c-callout--success', 'c-callout--info', 'c-callout--warning', '', 'c-callout--info'], array_values(array_column($news['verdicts'], 'callout')));
        self::assertSame([
            'confirmed' => "What's confirmed",
            'speculation' => 'Still speculation',
            'impact' => 'What it means for freelancers and corporate events',
            'sources' => 'Sources',
            'later' => 'Later updates',
            'follow_up' => 'Follow-up to',
        ], $news['sections']);
        self::assertSame('Industry Updates', $news['index']['title']);
        foreach (['index', 'category', 'search'] as $state) {
            self::assertNotSame('', $news['empty'][$state] ?? '', $state);
        }
        self::assertNotSame('', $news['search']['label']);
    }

    public function testTheContentFileHasNoDashesUsedAsPunctuation(): void
    {
        $text = (string) file_get_contents(PSC_ROOT . '/content/news.php');
        self::assertStringNotContainsString("\u{2014}", $text);
        self::assertStringNotContainsString("\u{2013}", $text);
    }
}
