<?php

declare(strict_types=1);

namespace Psc\Tests\Unit\News;

use Psc\News\ArticleStore;
use Psc\News\Context;
use Psc\News\Draft;
use Psc\News\Markdown;
use Psc\News\Prose;
use Psc\News\Result;
use Psc\News\Validator;
use Psc\Tests\TestCase;

/** The rules of docs/NEWS.md §7, by origin: the fields first, then what the stored draft became. */
final class ValidatorTest extends TestCase
{
    private const BASE = 'https://patternsshowcontrol.test';
    private const EM = "\u{2014}";

    /** @param array<string, mixed> $over */
    private static function article(array $over = []): array
    {
        return $over + [
            'title' => 'LED processor firmware adds frame remapping for corporate walls',
            'dek' => 'The new firmware lets one processor drive two odd-shaped walls, which saves a box on most conference sets.',
            'body' => str_repeat('The firmware arrived this week with release notes that are, for once, readable and specific about what changed on the outputs. ', 4),
            'impact' => 'Fewer processors on a typical conference set, so less rack space and one fewer thing to fail.',
            'verdict' => 'test',
            'verdict_note' => 'Try it on a rehearsal day before a show.',
            'categories' => ['video', 'technology'],
            'tags' => ['LED', 'Processing'],
            'sources' => [['url' => 'https://vendor.example/releases/7-2', 'title' => 'Release notes 7.2', 'publisher' => 'Vendor', 'kind' => 'primary']],
            'confirmed' => [['text' => 'Version 7.2 adds frame remapping.', 'url' => 'https://vendor.example/releases/7-2']],
            'story_key' => 'vendor-processor-firmware-7-2',
            'key' => 'test-import',
        ];
    }

    private function check(Draft $draft, string $origin, string $mode = 'publish', array $fields = []): Result
    {
        $ctx = (new Context(origin: $origin, mode: $mode, baseUrl: self::BASE))->with($fields);
        return (new Validator(new ArticleStore($this->app)))->check($draft, $ctx);
    }

    public function testAnAdministratorsDraftNeedsOnlyAHeadline(): void
    {
        $r = $this->check(Draft::fromArray(['title' => 'A headline that is long enough'], 'manual'), 'manual', 'draft');
        self::assertSame([], $r->errors);
        self::assertTrue($r->ok());
        self::assertSame(Validator::TITLE, $this->check(Draft::fromArray(['title' => 'Too short'], 'manual'), 'manual', 'draft')->errors['title'] ?? null);
    }

    public function testPublishingAppliesTheFullRules(): void
    {
        $r = $this->check(Draft::fromArray(['title' => 'A headline that is long enough'], 'manual'), 'manual', 'publish');
        $keys = array_keys($r->errors);
        sort($keys);
        self::assertSame(['body', 'categories', 'dek', 'sources'], $keys);
        self::assertSame(Validator::DEK, $r->errors['dek']);
        self::assertSame(Validator::CATEGORIES, $r->errors['categories']);
        self::assertFalse($r->ok());
        self::assertTrue($this->check(Draft::fromArray(self::article(['verdict' => '']), 'manual'), 'manual')->ok(), 'a manual article may leave the verdict empty');
        self::assertSame('Choose a verdict.', $this->check(Draft::fromArray(self::article(['verdict' => '']), 'import'), 'import')->errors['verdict'] ?? null);
        self::assertSame('Choose a verdict.', $this->check(Draft::fromArray(self::article(['verdict' => 'maybe']), 'manual'), 'manual', 'draft')->errors['verdict'] ?? null);
    }

    public function testTheLongestValuesAreRefusedEvenOnADraft(): void
    {
        $r = $this->check(Draft::fromArray(['title' => 'A headline that is long enough', 'dek' => str_repeat('x', 321), 'body' => str_repeat('y', 20001), 'tags' => ['ok', 'x'], 'categories' => ['video', 'audio', 'staging', 'tips']], 'manual'), 'manual', 'draft');
        self::assertSame(Validator::DEK, $r->errors['dek'] ?? null);
        self::assertSame('Write the article in 50 to 20,000 characters.', $r->errors['body'] ?? null);
        self::assertSame('Use up to 8 tags, each 2 to 40 characters.', $r->errors['tags'] ?? null);
        self::assertSame(Validator::CATEGORIES, $r->errors['categories'] ?? null);
        self::assertSame('Choose categories from the list.', $this->check(Draft::fromArray(['title' => 'A headline that is long enough', 'categories' => ['rumours']], 'manual'), 'manual', 'draft')->errors['categories'] ?? null);
        self::assertSame('Write the article in 300 to 20,000 characters.', $this->check(Draft::fromArray(self::article(['body' => str_repeat('z', 299)]), 'import'), 'import')->errors['body'] ?? null);
    }

    public function testEmptySeoTitleAndSummaryAreFilledIn(): void
    {
        $title = 'A rather long headline about an LED processor firmware release that goes on for a while';
        $r = $this->check(Draft::fromArray(self::article(['title' => $title]), 'manual'), 'manual');
        self::assertTrue($r->ok(), implode('; ', $r->reasons()));
        self::assertSame('A rather long headline about an LED processor firmware', $r->draft->seoTitle);
        self::assertLessThanOrEqual(60, mb_strlen($r->draft->seoTitle));
        self::assertSame(self::article()['dek'], $r->draft->summary);
        $short = $this->check(Draft::fromArray(['title' => 'A headline that is long enough', 'dek' => 'Short.'], 'manual'), 'manual', 'draft');
        self::assertSame('Short. A headline that is long enough', $short->draft->summary, 'padded with the headline');
        self::assertSame('Keep the SEO title to 60 characters, or leave it empty to use the headline.', $this->check(Draft::fromArray(self::article(['seo_title' => str_repeat('s', 61)]), 'manual'), 'manual')->errors['seo_title'] ?? null);
        self::assertArrayHasKey('summary', $this->check(Draft::fromArray(self::article(['summary' => 'Too short']), 'manual'), 'manual')->errors);
    }

    public function testDashesAreReplacedAndCounted(): void
    {
        $r = $this->check(Draft::fromArray(self::article(['title' => 'Firmware 7.2 ' . self::EM . ' frame remapping arrives', 'confirmed' => [['text' => 'It ships ' . self::EM . ' today.', 'url' => 'https://vendor.example/releases/7-2']]]), 'manual'), 'manual');
        self::assertTrue($r->ok(), implode('; ', $r->reasons()));
        self::assertSame(2, $r->dashes);
        self::assertContains(Validator::DASHES, $r->warnings);
        self::assertSame('Firmware 7.2, frame remapping arrives', $r->draft->title);
        self::assertSame('It ships, today.', $r->draft->confirmed[0]['text']);
    }

    public function testNoEmailAddressInAnyTextField(): void
    {
        $r = $this->check(Draft::fromArray(self::article(['body' => self::article()['body'] . ' Write to press@vendor.example for a demo.', 'verdict_note' => '[Ask](mailto:someone)']), 'manual'), 'manual');
        self::assertSame(Validator::CONTACT, $r->errors['body'] ?? null);
        self::assertSame(Validator::CONTACT, $r->errors['verdict_note'] ?? null);
        self::assertSame(Validator::CONTACT, $this->check(Draft::fromArray(self::article(['sources' => [['url' => 'https://vendor.example/releases/7-2', 'title' => 'Mail ben@example.com', 'publisher' => '', 'kind' => 'press']]]), 'manual'), 'manual')->errors['sources.0.title'] ?? null);
    }

    public function testSourcesAndItems(): void
    {
        $r = $this->check(Draft::fromArray(self::article(['sources' => [['url' => 'ftp://vendor.example/x', 'title' => 'x']]]), 'manual'), 'manual');
        self::assertSame("Enter the source's web address, starting https:// or http://.", $r->errors['sources.0.url'] ?? null);
        $many = array_map(static fn (int $i): array => ['url' => 'https://vendor.example/p' . $i, 'title' => 'P' . $i], range(1, 13));
        self::assertSame('Use up to 12 sources.', $this->check(Draft::fromArray(self::article(['sources' => $many]), 'manual'), 'manual')->errors['sources'] ?? null);

        $orphan = self::article(['confirmed' => [['text' => 'A fact from somewhere else.', 'url' => 'https://elsewhere.example/a']]]);
        self::assertSame(Validator::ITEM_SOURCE, $this->check(Draft::fromArray($orphan, 'manual'), 'manual')->errors['confirmed.0.url'] ?? null);
        self::assertArrayNotHasKey('confirmed.0.url', $this->check(Draft::fromArray($orphan, 'manual'), 'manual', 'draft')->errors, 'a draft may still be gathering its sources');
        self::assertSame(Validator::ITEM_SOURCE, $this->check(Draft::fromArray(self::article(['speculation' => [['text' => 'A rumour.', 'url' => '']]]), 'manual'), 'manual')->errors['speculation.0.url'] ?? null);
        self::assertSame('Keep each item to 400 characters.', $this->check(Draft::fromArray(self::article(['confirmed' => [['text' => str_repeat('f', 401), 'url' => 'https://vendor.example/releases/7-2']]]), 'manual'), 'manual')->errors['confirmed.0.text'] ?? null);
    }

    public function testBannedSitesAreRefusedOrDroppedAndBannedTopicsRefuseAiAndImports(): void
    {
        $banned = ['bannedDomains' => ['vendor.example']];
        $r = $this->check(Draft::fromArray(self::article(), 'manual'), 'manual', 'publish', $banned);
        self::assertSame(Validator::BANNED, $r->errors['sources.0.url'] ?? null);
        self::assertSame(Validator::BANNED, $r->errors['confirmed.0.url'] ?? null);
        $link = self::article(['sources' => [['url' => 'https://ok.example/a', 'title' => 'A']], 'confirmed' => [], 'body' => self::article()['body'] . ' See [this](https://www.vendor.example/x).']);
        self::assertSame(Validator::BANNED, $this->check(Draft::fromArray($link, 'manual'), 'manual', 'publish', $banned)->errors['body'] ?? null);

        $topics = ['bannedTopics' => ['frame remapping']];
        $import = $this->check(Draft::fromArray(self::article(), 'import'), 'import', 'publish', $topics);
        self::assertSame([['rule' => 'banned_topic', 'detail' => 'Mentions a banned topic: frame remapping.']], $import->rejections);
        $manual = $this->check(Draft::fromArray(self::article(), 'manual'), 'manual', 'publish', $topics);
        self::assertTrue($manual->ok(), 'an administrator is only warned');
        self::assertContains('This article mentions a banned topic: frame remapping.', $manual->warnings);
    }

    public function testFollowUpsMustExistAndResolveToIds(): void
    {
        $earlier = $this->makeArticle(['slug' => 'the-earlier-story']);
        self::assertSame('Choose the earlier articles from the list.', $this->check(Draft::fromArray(self::article(['follow_up_of' => ['999']]), 'manual'), 'manual')->errors['follow_up_of'] ?? null);
        $missing = $this->check(Draft::fromArray(self::article(['follow_up_of' => ['no-such-story']]), 'import'), 'import');
        self::assertSame([['rule' => 'follow_up', 'detail' => 'The earlier article no-such-story does not exist.']], $missing->rejections);
        $found = $this->check(Draft::fromArray(self::article(['follow_up_of' => ['the-earlier-story']]), 'import'), 'import');
        self::assertTrue($found->ok(), implode('; ', $found->reasons()));
        self::assertSame([$earlier], $found->followUps);
        self::assertSame([$earlier], $found->draft->followUps);
        self::assertArrayHasKey('follow_up_of', $this->check(Draft::fromArray(self::article(['follow_up_of' => [$earlier]]), 'manual'), 'manual', 'publish', ['editingId' => $earlier])->errors, 'never its own follow-up');
    }

    /** @return array<string, array<string, mixed>> */
    private static function refs(): array
    {
        return [
            'S1' => ['url' => 'https://vendor.example/releases/7-2', 'title' => 'Release notes 7.2', 'via' => 'fetch', 'page_age' => '2 days', 'cited' => false],
            'S2' => ['url' => 'https://forum.example/thread/9', 'title' => 'A forum thread', 'via' => 'search', 'page_age' => '', 'cited' => false],
            'S3' => ['url' => 'https://press.example/story', 'title' => 'Trade press story', 'via' => 'search', 'page_age' => '', 'cited' => true],
        ];
    }

    /** @param array<string, mixed> $over */
    private static function aiArticle(array $over = []): array
    {
        $a = self::article();
        unset($a['key']);
        return $over + [
            'sources' => [['ref' => 'S1', 'publisher' => 'Vendor', 'kind' => 'primary'], ['ref' => 'S9', 'publisher' => 'Nobody', 'kind' => 'press']],
            'confirmed' => [
                ['text' => 'Version 7.2 adds frame remapping.', 'ref' => 's1'],
                ['text' => 'Two outputs can share one wall.', 'ref' => 'S2'],
                ['text' => 'The trade press saw it working.', 'ref' => 'S3'],
                ['text' => 'An invented fact.', 'ref' => 'S7'],
            ],
            'speculation' => [['text' => 'A price cut may follow.', 'ref' => 'S3']],
            'body' => $a['body'] . ' Read [the notes](S1), [a rumour](S8), [an outside page](https://elsewhere.example/x) and [our guide](/workflows/).',
            'byline' => 'Someone Else',
        ] + $a;
    }

    public function testAnAiDraftCitesOnlyTheResearchsReferences(): void
    {
        $draft = Draft::fromArray(self::aiArticle(), 'ai', self::refs());
        $r = $this->check($draft, 'ai', 'publish', ['refs' => self::refs()]);
        self::assertTrue($r->ok(), implode('; ', $r->reasons()));
        $d = $r->draft;
        self::assertSame(['https://vendor.example/releases/7-2', 'https://forum.example/thread/9', 'https://press.example/story'], array_column($d->sources, 'url'), 'S9 dropped; the items\' references listed as sources');
        self::assertSame('Release notes 7.2', $d->sources[0]['title'], 'titles come from the research');
        self::assertSame(['Version 7.2 adds frame remapping.', 'The trade press saw it working.'], array_column($d->confirmed, 'text'));
        self::assertSame(['A price cut may follow.', 'Two outputs can share one wall.'], array_column($d->speculation, 'text'), 'an item resting on an unread, uncited page moves to speculation');
        self::assertStringContainsString('[the notes](https://vendor.example/releases/7-2)', $d->body);
        self::assertStringContainsString(' a rumour,', $d->body, 'an unknown reference keeps only its text');
        self::assertStringContainsString(' an outside page and', $d->body, 'an address the research did not return is unlinked');
        self::assertStringContainsString('[our guide](/workflows/)', $d->body, 'links to this site stay');
        self::assertSame('Industry Updates desk', $d->byline);
        self::assertTrue($d->aiWritten);
        self::assertCount(5, array_filter($r->warnings, static fn (string $w): bool => str_contains($w, 'Dropped') || str_contains($w, 'Moved') || str_contains($w, 'Unlinked')));
    }

    public function testAnAiDraftWithNoValidSourceIsRejected(): void
    {
        $none = $this->check(Draft::fromArray(self::aiArticle(['sources' => [['ref' => 'S9']], 'confirmed' => [], 'speculation' => []]), 'ai', self::refs()), 'ai', 'publish', ['refs' => self::refs()]);
        self::assertSame([['rule' => 'refs', 'detail' => 'No valid source remains.']], $none->rejections);
        $banned = $this->check(Draft::fromArray(self::aiArticle(['sources' => [['ref' => 'S1']], 'confirmed' => [['text' => 'A fact.', 'ref' => 'S1']], 'speculation' => []]), 'ai', self::refs()), 'ai', 'publish', ['refs' => self::refs(), 'bannedDomains' => ['vendor.example']]);
        self::assertSame([['rule' => 'refs', 'detail' => 'No valid source remains.']], $banned->rejections, 'a banned site is dropped like an unknown reference');
        self::assertSame([], $banned->draft->confirmed);
    }

    public function testPhrasesToAvoidHoldAnAiDraft(): void
    {
        $draft = Draft::fromArray(self::aiArticle(['dek' => 'A game changer for anyone who has to delve into LED processing on a conference set this year.']), 'ai', self::refs());
        $r = $this->check($draft, 'ai', 'publish', ['refs' => self::refs(), 'avoidPhrases' => ['delve', 'game changer', 'tapestry']]);
        self::assertTrue($r->ok());
        self::assertSame(['delve', 'game changer'], $r->tells);
        self::assertSame(['Uses phrases to avoid: delve, game changer.'], $r->hold);
        self::assertTrue($r->held());
        $manual = $this->check(Draft::fromArray(self::article(['dek' => 'A game changer for anyone who has to delve into LED processing on a conference set this year.']), 'manual'), 'manual', 'publish', ['avoidPhrases' => ['delve']]);
        self::assertSame([], $manual->tells, 'an administrator writes in their own words');
    }

    public function testTheSameStoryKeyIsADuplicateUnlessItBringsANewSource(): void
    {
        $id = $this->makeArticle(['story_key' => 'vendor-processor-firmware-7-2', 'sources' => [['url' => 'https://vendor.example/releases/7-2', 'title' => 'Notes']], 'confirmed' => []]);
        $same = $this->check(Draft::fromArray(self::article(), 'import'), 'import');
        self::assertSame([['rule' => 'duplicate', 'detail' => 'Duplicates article ' . $id . ': the same story key, and it brings no new source.']], $same->rejections);

        $newSource = self::article(['sources' => [['url' => 'https://vendor.example/releases/7-2', 'title' => 'Notes'], ['url' => 'https://press.example/review', 'title' => 'Review']]]);
        $auto = $this->check(Draft::fromArray($newSource, 'import'), 'import');
        self::assertTrue($auto->ok(), implode('; ', $auto->reasons()));
        self::assertSame([$id], $auto->autoFollowUps, 'a duplicate of an article the writer was not shown becomes a follow-up');
        self::assertSame([$id], $auto->draft->followUps);

        $seen = $this->check(Draft::fromArray($newSource, 'import'), 'import', 'publish', ['seen' => [$id]]);
        self::assertSame('duplicate', $seen->rejections[0]['rule'] ?? null, 'the writer was shown it and did not say it follows it');
        self::assertTrue($this->check(Draft::fromArray(self::article(), 'manual'), 'manual')->ok(), 'an administrator decides for themself');
        self::assertTrue($this->check(Draft::fromArray(self::article(), 'import'), 'import', 'publish', ['editingId' => $id])->ok(), 'an article is not its own duplicate');
    }

    public function testASharedSourceOrSimilarWordingIsADuplicateWithinItsWindow(): void
    {
        $shared = $this->makeArticle(['title' => 'Something else entirely about audio', 'dek' => 'Nothing alike in these words at all, about radio microphones and spectrum this time.', 'sources' => [['url' => 'https://www.vendor.example/releases/7-2/?utm_source=a', 'title' => 'Notes']], 'confirmed' => []]);
        $r = $this->check(Draft::fromArray(self::article(['story_key' => 'other']), 'import'), 'import', 'publish', ['seen' => [$shared]]);
        self::assertSame([['rule' => 'duplicate', 'detail' => 'Duplicates article ' . $shared . ': a source it already used, and it brings no new source.']], $r->rejections);

        $this->db->exec('DELETE FROM news_articles');
        $old = $this->makeArticle(['title' => self::article()['title'], 'dek' => self::article()['dek'], 'tags' => ['led', 'processing'], 'sources' => [['url' => 'https://old.example/a', 'title' => 'Old']], 'confirmed' => [], 'published_at' => time() - 61 * 86400]);
        self::assertTrue($this->check(Draft::fromArray(self::article(['story_key' => 'other']), 'import'), 'import', 'publish', ['seen' => [$old]])->ok(), 'similar wording counts for 60 days');
        $this->db->update('news_articles', ['published_at' => time() - 59 * 86400], ['id' => $old]);
        $similar = $this->check(Draft::fromArray(self::article(['story_key' => 'other']), 'import'), 'import', 'publish', ['seen' => [$old]]);
        self::assertSame('Duplicates article ' . $old . ': very similar wording.', $similar->rejections[0]['detail'] ?? null);
        self::assertEqualsWithDelta(1 / 3, Validator::overlap(['a', 'b'], ['b', 'c']), 0.0001);
        self::assertSame(0.0, Validator::overlap([], []));
    }

    public function testAFollowUpMustBringANewSource(): void
    {
        $earlier = $this->makeArticle(['slug' => 'firmware-7-1', 'story_key' => 'vendor-processor-firmware-7-2', 'sources' => [['url' => 'https://vendor.example/releases/7-2', 'title' => 'Notes']], 'confirmed' => []]);
        $nothingNew = $this->check(Draft::fromArray(self::article(['follow_up_of' => ['firmware-7-1']]), 'import'), 'import', 'publish', ['seen' => [$earlier]]);
        self::assertSame([['rule' => 'follow_up', 'detail' => 'A follow-up must bring at least one source the earlier articles did not use.']], $nothingNew->rejections, 'declared, so not a duplicate, but nothing new');
        $withNew = self::article(['follow_up_of' => ['firmware-7-1'], 'sources' => [['url' => 'https://vendor.example/releases/7-2', 'title' => 'Notes'], ['url' => 'https://press.example/7-2-review', 'title' => 'Review']]]);
        $ok = $this->check(Draft::fromArray($withNew, 'import'), 'import', 'publish', ['seen' => [$earlier]]);
        self::assertTrue($ok->ok(), implode('; ', $ok->reasons()));
        self::assertSame([$earlier], $ok->followUps);
        self::assertSame([], $ok->autoFollowUps);
    }

    /** An item's text is Markdown and printed with its links, so its links pass the body's rules. */
    public function testLinksInsideItemsAreCheckedLikeTheBodys(): void
    {
        $refs = self::refs();
        $ai = Draft::fromArray(self::aiArticle([
            'confirmed' => [
                ['text' => 'Grab the patch from [the vendor](https://evil.example/download.exe).', 'ref' => 'S1'],
                ['text' => 'The [release notes](S1) say so, and [a forum](https://other.example/y) agrees.', 'ref' => 'S1'],
            ],
            'speculation' => [['text' => 'A [rumour](S8) says more is coming.', 'ref' => 'S3']],
        ]), 'ai', $refs);
        $r = $this->check($ai, 'ai', 'publish', ['refs' => $refs, 'bannedDomains' => ['evil.example']]);
        self::assertTrue($r->ok(), implode('; ', $r->reasons()));
        self::assertSame(
            ['Grab the patch from the vendor.', 'The [release notes](https://vendor.example/releases/7-2) say so, and a forum agrees.'],
            array_column($r->draft->confirmed, 'text'),
            'a banned address and one the research never returned keep only their text; [x](S1) is S1\'s address'
        );
        self::assertSame(['A rumour says more is coming.'], array_column($r->draft->speculation, 'text'), 'an unknown reference keeps only its text');
        self::assertContains('Unlinked "the vendor": its address could not be used.', $r->warnings);
        self::assertContains('Unlinked "a forum": its address could not be used.', $r->warnings);
        self::assertContains('Unlinked "rumour": S8 is not a reference.', $r->warnings);
        self::assertStringNotContainsString('<a ', Markdown::inline($r->draft->confirmed[0]['text']), 'never printed as a link');

        $bad = self::article([
            'confirmed' => [['text' => 'Grab the patch from [the vendor](https://www.evil.example/x).', 'url' => 'https://vendor.example/releases/7-2']],
            'speculation' => [['text' => 'See [this page](https://127.0.0.1/x) and [that one](https://other.example/y).', 'url' => 'https://vendor.example/releases/7-2']],
        ]);
        foreach (['manual', 'import'] as $origin) {
            $r = $this->check(Draft::fromArray($bad, $origin), $origin, 'publish', ['bannedDomains' => ['evil.example']]);
            self::assertSame(Validator::BANNED, $r->errors['confirmed.0.text'] ?? null, $origin);
            self::assertSame('Each link needs a web address on a named site, starting https:// or http://.', $r->errors['speculation.0.text'] ?? null, $origin);
            self::assertSame($bad['confirmed'][0]['text'], $r->draft->confirmed[0]['text'], $origin . ': the writer fixes it');
        }
        $outside = self::article(['confirmed' => [['text' => 'Shown at [a trade show](https://show.example/day-1).', 'url' => 'https://vendor.example/releases/7-2']]]);
        self::assertTrue($this->check(Draft::fromArray($outside, 'manual'), 'manual')->ok(), 'an administrator may link anywhere not banned');
    }

    /** The banned topics are looked for in every word the article prints as its own. */
    public function testBannedTopicsAreFoundInTheItemsAndTheVerdictNote(): void
    {
        $topics = ['bannedTopics' => ['fraud']];
        $rejected = [['rule' => 'banned_topic', 'detail' => 'Mentions a banned topic: fraud.']];
        $inItem = self::article(['speculation' => [['text' => 'Joe Bloggs of Acme was convicted of fraud.', 'url' => 'https://vendor.example/releases/7-2']]]);
        self::assertSame($rejected, $this->check(Draft::fromArray($inItem, 'import'), 'import', 'publish', $topics)->rejections, 'a speculation item');
        $inNote = self::article(['verdict_note' => 'Skip it while the maker is under a fraud inquiry.']);
        self::assertSame($rejected, $this->check(Draft::fromArray($inNote, 'import'), 'import', 'publish', $topics)->rejections, 'the verdict note');

        $refs = self::refs();
        $ai = Draft::fromArray(self::aiArticle(['confirmed' => [['text' => 'The maker admitted fraud last year.', 'ref' => 'S1']]]), 'ai', $refs);
        self::assertSame($rejected, $this->check($ai, 'ai', 'publish', ['refs' => $refs] + $topics)->rejections, 'a confirmed item in an AI draft');

        $manual = $this->check(Draft::fromArray($inItem, 'manual'), 'manual', 'publish', $topics);
        self::assertTrue($manual->ok(), 'an administrator is only warned');
        self::assertContains('This article mentions a banned topic: fraud.', $manual->warnings);
        $sourceTitle = self::article(['sources' => [['url' => 'https://vendor.example/releases/7-2', 'title' => 'Fraud squad notes', 'publisher' => 'Vendor', 'kind' => 'primary']]]);
        self::assertSame([], $this->check(Draft::fromArray($sourceTitle, 'import'), 'import', 'publish', $topics)->rejections, 'a source\'s own page title is someone else\'s words');
    }

    /** An AI draft is not thrown away for an overshoot that costs nothing to fix: it is fixed, with a warning. */
    public function testAnAiDraftsCosmeticOvershootsAreFixedNotRefused(): void
    {
        $refs = self::refs();
        $seo = 'LED processor firmware adds frame remapping for corporate walls';
        $summary = 'The new firmware lets one processor drive two odd-shaped walls, which saves a box, a cable run and some rack space on most of the conference sets this season.';
        $tags = ['one', 'two', 'three', 'four', 'five', 'six', 'seven', 'x', 'eight', 'nine'];
        $items = array_map(static fn (int $i): array => ['text' => 'Fact number ' . $i . ' from the notes.', 'ref' => 'S1'], range(1, 13));
        $r = $this->check(Draft::fromArray(self::aiArticle(['seo_title' => $seo, 'summary' => $summary, 'tags' => $tags, 'confirmed' => $items, 'speculation' => []]), 'ai', $refs), 'ai', 'publish', ['refs' => $refs]);
        self::assertTrue($r->ok(), implode('; ', $r->reasons()));
        $d = $r->draft;
        self::assertSame(Prose::cut($seo, 60), $d->seoTitle);
        self::assertLessThanOrEqual(60, mb_strlen($d->seoTitle));
        self::assertSame(Prose::cut($summary, 155), $d->summary);
        self::assertGreaterThan(155, mb_strlen($summary));
        self::assertSame(['one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight'], $d->tags, 'a tag too short goes, then the first 8 stay');
        self::assertSame(array_slice(array_column($items, 'text'), 0, 12), array_column($d->confirmed, 'text'));
        foreach (['Cut the SEO title to 60 characters.', 'Cut the meta description to 155 characters.', 'Kept 8 of the 10 tags: up to 8, each 2 to 40 characters.', "Kept the first 12 of the 13 items in What's confirmed."] as $warning) {
            self::assertContains($warning, $r->warnings);
        }

        $short = $this->check(Draft::fromArray(self::aiArticle(['summary' => 'Too short']), 'ai', $refs), 'ai', 'publish', ['refs' => $refs]);
        self::assertTrue($short->ok(), implode('; ', $short->reasons()));
        self::assertSame(self::article()['dek'], $short->draft->summary, 'a summary too short gives way to the standfirst');

        $manual = $this->check(Draft::fromArray(self::article(['seo_title' => $seo, 'tags' => $tags]), 'manual'), 'manual');
        self::assertArrayHasKey('seo_title', $manual->errors, 'an administrator is told, not overruled');
        self::assertArrayHasKey('tags', $manual->errors);
    }

    /** Fourteen sources keep twelve: every one an item cites, then the first of the rest. */
    public function testAnAiDraftKeepsTwelveSourcesThoseItsItemsCiteFirst(): void
    {
        $refs = [];
        foreach (range(1, 14) as $n) {
            $refs['S' . $n] = ['url' => 'https://site' . $n . '.example/story', 'title' => 'Story ' . $n, 'via' => 'fetch', 'page_age' => '', 'cited' => false];
        }
        $draft = Draft::fromArray(self::aiArticle([
            'sources' => array_map(static fn (int $n): array => ['ref' => 'S' . $n, 'publisher' => 'Site ' . $n, 'kind' => 'press'], range(1, 14)),
            'confirmed' => [['text' => 'The fourteenth site has the fact.', 'ref' => 'S14']],
            'speculation' => [],
            'body' => self::article()['body'],
        ]), 'ai', $refs);
        $r = $this->check($draft, 'ai', 'publish', ['refs' => $refs]);
        self::assertTrue($r->ok(), implode('; ', $r->reasons()));
        $kept = array_map(static fn (int $n): string => 'https://site' . $n . '.example/story', [...range(1, 11), 14]);
        self::assertSame($kept, array_column($r->draft->sources, 'url'));
        self::assertSame(['The fourteenth site has the fact.'], array_column($r->draft->confirmed, 'text'), 'its item keeps its source');
        self::assertContains('Kept 12 of the 14 sources, those the items cite first.', $r->warnings);
    }

    /** Lengths only a rewrite can fix are still refused, and say what the rewrite must fix. */
    public function testAnAiDraftsLengthsARewriteCanFixAreRepairable(): void
    {
        $refs = self::refs();
        $title = trim(str_repeat('A headline that will not stop ', 6));
        $item = str_repeat('word ', 90) . 'end.';
        $over = ['title' => $title, 'dek' => 'Too short a standfirst.', 'body' => 'A body far too short to publish.', 'verdict_note' => str_repeat('n', 321), 'confirmed' => [['text' => $item, 'ref' => 'S1']]];
        $r = $this->check(Draft::fromArray(self::aiArticle($over), 'ai', $refs), 'ai', 'publish', ['refs' => $refs]);
        self::assertFalse($r->ok(), 'refused until a rewrite passes');
        self::assertTrue($r->onlyRepairable());
        self::assertSame(['title', 'dek', 'body', 'verdict_note', 'confirmed.0.text'], array_keys($r->errors));
        self::assertSame(Validator::TITLE, $r->errors['title'], 'an administrator\'s words stay the error');
        self::assertSame([
            'The title is 179 characters long; it must be 10 to 160.',
            'The dek is 23 characters long; it must be 50 to 320.',
            'The body is 32 characters long; write at least 300.',
            'The verdict_note is 321 characters long; keep it to 320.',
            'The confirmed item that begins "' . trim(str_repeat('word ', 10)) . '" is 454 characters long; keep each item to 400.',
        ], $r->repairable());

        $linked = $this->check(Draft::fromArray(self::aiArticle(['confirmed' => [['text' => str_repeat('fact ', 75) . '[notes](S1).', 'ref' => 'S1']]]), 'ai', $refs), 'ai', 'publish', ['refs' => $refs]);
        self::assertTrue($linked->ok(), 'an item is measured as it reads, not by the address its reference became: ' . implode('; ', $linked->reasons()));

        $banned = $this->check(Draft::fromArray(self::aiArticle($over), 'ai', $refs), 'ai', 'publish', ['refs' => $refs, 'bannedTopics' => ['headline']]);
        self::assertFalse($banned->onlyRepairable(), 'a rejection is never repaired');
        self::assertNotSame([], $banned->repairable());
        $hard = $this->check(Draft::fromArray(self::aiArticle($over + ['categories' => ['rumours']]), 'ai', $refs), 'ai', 'publish', ['refs' => $refs]);
        self::assertFalse($hard->onlyRepairable(), 'nor an error a rewrite of the text cannot fix');
        $manual = $this->check(Draft::fromArray(self::article(['title' => $title]), 'manual'), 'manual');
        self::assertSame(Validator::TITLE, $manual->errors['title'] ?? null);
        self::assertSame([], $manual->repairable(), 'only the desk repairs');
        self::assertFalse($manual->onlyRepairable());
    }

    public function testDraftProblemsAndImportKeysAreErrors(): void
    {
        $r = $this->check(Draft::fromArray(self::article(['published_at' => 'next Tuesday', 'key' => '']), 'import'), 'import');
        self::assertSame('Enter the publishing time as a date and time, for example 2026-10-22 06:30.', $r->errors['published_at'] ?? null);
        self::assertSame('Give the file a key of up to 190 characters.', $r->errors['key'] ?? null);
        self::assertSame(['published_at: Enter the publishing time as a date and time, for example 2026-10-22 06:30.', 'key: Give the file a key of up to 190 characters.'], $r->reasons());
        $when = Draft::fromArray(self::article(['published_at' => '2026-10-20T08:30:00+01:00']), 'import');
        self::assertSame(1792481400, $when->publishAt);
    }
}
