<?php

declare(strict_types=1);

namespace Psc\News;

/**
 * The rules every article passes before it is stored, whoever wrote it (docs/NEWS.md §7): the admin editor, the AI
 * desk and the importer all call check(). Lengths are checked on the text as it will be stored (after
 * Prose::undash()); the most a column holds is checked on every save, the least an article needs only when it is
 * published or scheduled, except that an administrator's draft needs its headline.
 *
 * Rules by origin: an AI draft's sources and links must come from the research (refs), and an address the desk
 * may not use is dropped or unlinked rather than refused; banned topics and duplicates refuse AI and import drafts
 * and only warn on manual ones; the phrases to avoid hold an AI draft for repair or review. Errors are in an
 * administrator's words, keyed by field (sources.2.url for a field in a list).
 */
final class Validator
{
    public const TITLE_MIN = 10;
    public const TITLE_MAX = 160;
    public const SEO_TITLE_MAX = 60;
    public const DEK_MIN = 50;
    public const DEK_MAX = 320;
    public const SUMMARY_MIN = 50;
    public const SUMMARY_MAX = 155;
    public const BODY_MIN = 300;
    public const BODY_MIN_MANUAL = 50;
    public const BODY_MAX = 20000;
    public const IMPACT_MAX = 6000;
    public const CATEGORIES_MAX = 3;
    public const TAGS_MAX = 8;
    public const TAG_MIN = 2;
    public const TAG_MAX = 40;
    public const SOURCES_MAX = 12;
    public const SOURCE_TITLE_MAX = 300;
    public const PUBLISHER_MAX = 160;
    public const URL_MAX = 2000;
    public const ITEMS_MAX = 12;
    public const ITEM_MAX = 400;
    public const VERDICT_NOTE_MAX = 320;
    public const BYLINE_MAX = 120;
    public const KEY_MAX = 190;

    /** Duplicate windows, in days: the same story key, a shared source, similar wording (docs/NEWS.md §7). */
    public const STORY_DAYS = 365;
    public const SOURCE_DAYS = 120;
    public const WORDING_DAYS = 60;
    /** The Jaccard overlap of the lower-case words of headline, standfirst and tags at which wording is similar. */
    public const WORDING_OVERLAP = 0.5;

    public const TITLE = 'Enter a headline of 10 to 160 characters.';
    public const DEK = 'Enter a standfirst of 50 to 320 characters.';
    public const CATEGORIES = 'Choose one to three categories.';
    public const BANNED = 'That site is on the banned list.';
    public const CONTACT = 'Remove the email address; link to a page instead.';
    public const DASHES = 'Em dashes were replaced with commas.';
    public const ITEM_SOURCE = 'Give the item a web address that is also listed under Sources.';

    public function __construct(private readonly ArticleStore $store)
    {
    }

    public function check(Draft $draft, Context $ctx): Result
    {
        $r = new Result($draft->normalised());
        $r->dashes = $draft->dashCount();
        $d = $r->draft;
        $publish = $ctx->publishing();
        foreach ($d->problems as $field => $message) {
            $r->error($field, $message);
        }
        if ($r->dashes > 0) {
            $r->warnings[] = self::DASHES;
        }
        foreach ($d->notes as $note) {
            $r->warnings[] = $note['detail'];
        }

        $this->checkFields($d, $ctx, $r, $publish);
        $followSources = $this->checkFollowUps($d, $ctx, $r);
        $this->checkSources($d, $ctx, $r, $publish);
        $this->checkItems($d, $ctx, $r, $publish);
        $this->checkLinks($d, $ctx, $r, $followSources);
        if ($publish && $d->sources === [] && $ctx->origin === 'ai') {
            $r->reject('refs', 'No valid source remains.');
        } elseif ($publish && $d->sources === []) {
            $r->error('sources', 'Add at least one source.');
        }
        $this->checkTopics($d, $ctx, $r);
        foreach ($d->texts() as $field => $text) {
            if (Prose::emails($text)) {
                $r->error($field, self::CONTACT);
            }
        }
        if (in_array($ctx->origin, ['ai', 'import'], true)) {
            $this->checkDuplicates($d, $ctx, $r);
        }
        if ($ctx->origin === 'ai' && $ctx->avoidPhrases !== []) {
            $r->tells = Prose::tells(implode("\n\n", [$d->dek, $d->body, $d->impact, $d->verdictNote]), $ctx->avoidPhrases);
            if ($r->tells !== []) {
                $r->hold[] = 'Uses phrases to avoid: ' . implode(', ', $r->tells) . '.';
            }
        }

        $d->seoTitle = $d->seoTitleOrDefault();
        $d->summary = $d->summaryOrDefault();
        $d->followUps = $r->followUps;
        return $r;
    }

    /**
     * The articles a story duplicates, by the rules of docs/NEWS.md §7: the same story key within 365 days, a source
     * address one of them used within 120 days, or headline, standfirst and tags whose words overlap by half or more
     * within 60 days. The desk's selection step uses it on a headline before anything is written.
     *
     * @param list<string> $tags
     * @param list<string> $sourceKeys canonical keys (Urls::key()) of the story's sources
     * @return array<int, array{why: string, source_keys: list<string>}> article id => why it matched, and its sources
     */
    public function duplicates(string $title, string $dek, array $tags, string $storyKey, array $sourceKeys, Context $ctx): array
    {
        $now = $ctx->now ?? time();
        $recent = $ctx->recent ?? $this->store->recent(self::STORY_DAYS, 2000);
        $words = self::wordSet($title . ' ' . $dek . ' ' . implode(' ', $tags));
        $out = [];
        foreach ($recent as $a) {
            $id = (int) ($a['id'] ?? 0);
            if ($id <= 0 || $id === $ctx->editingId) {
                continue;
            }
            $age = $now - (int) ($a['date'] ?? $a['published_at'] ?? $a['created_at'] ?? $now);
            $theirs = array_values(array_map('strval', (array) ($a['source_keys'] ?? [])));
            $why = null;
            if ($storyKey !== '' && (string) ($a['story_key'] ?? '') === $storyKey && $age <= self::STORY_DAYS * 86400) {
                $why = 'the same story key';
            } elseif ($age <= self::SOURCE_DAYS * 86400 && array_intersect($sourceKeys, $theirs) !== []) {
                $why = 'a source it already used';
            } elseif ($age <= self::WORDING_DAYS * 86400 && self::overlap($words, self::wordSet((string) ($a['title'] ?? '') . ' ' . (string) ($a['dek'] ?? '') . ' ' . str_replace(',', ' ', (string) ($a['tags'] ?? '')))) >= self::WORDING_OVERLAP) {
                $why = 'very similar wording';
            }
            if ($why !== null) {
                $out[$id] = ['why' => $why, 'source_keys' => $theirs];
            }
        }
        return $out;
    }

    /** The Jaccard overlap of two sets of words: shared words over all words, 0 when both are empty. */
    public static function overlap(array $a, array $b): float
    {
        $all = count(array_unique(array_merge($a, $b)));
        return $all > 0 ? count(array_intersect(array_unique($a), array_unique($b))) / $all : 0.0;
    }

    /** @return list<string> the distinct lower-case words of a text */
    public static function wordSet(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $m);
        return array_values(array_unique($m[0]));
    }

    private function checkFields(Draft $d, Context $ctx, Result $r, bool $publish): void
    {
        $len = static fn (string $s): int => mb_strlen($s);
        if ($len($d->title) < self::TITLE_MIN || $len($d->title) > self::TITLE_MAX || str_contains($d->title, "\n")) {
            $r->error('title', self::TITLE);
        }
        if ($len($d->seoTitle) > self::SEO_TITLE_MAX) {
            $r->error('seo_title', 'Keep the SEO title to 60 characters, or leave it empty to use the headline.');
        }
        if ($len($d->dek) > self::DEK_MAX || ($publish && $len($d->dek) < self::DEK_MIN)) {
            $r->error('dek', self::DEK);
        }
        $summary = 'Enter a meta description of 50 to 155 characters, or leave it empty to use the standfirst.';
        if ($d->summary !== '') {
            if ($len($d->summary) > self::SUMMARY_MAX || ($publish && $len($d->summary) < self::SUMMARY_MIN)) {
                $r->error('summary', $summary);
            }
        } elseif ($publish && !isset($r->errors['dek']) && $len($d->summaryOrDefault()) < self::SUMMARY_MIN) {
            $r->error('summary', $summary);
        }
        $min = $ctx->origin === 'manual' ? self::BODY_MIN_MANUAL : self::BODY_MIN;
        if ($len($d->body) > self::BODY_MAX || strlen($d->body) > 65000 || ($publish && $len($d->body) < $min)) {
            $r->error('body', 'Write the article in ' . number_format($min) . ' to 20,000 characters.');
        }
        if ($len($d->impact) > self::IMPACT_MAX) {
            $r->error('impact', 'Keep this section to 6,000 characters.');
        }
        $unknown = array_filter($d->categories, static fn (string $c): bool => !Categories::has($c));
        if ($unknown !== []) {
            $r->error('categories', 'Choose categories from the list.');
        } elseif (count($d->categories) > self::CATEGORIES_MAX || ($publish && $d->categories === [])) {
            $r->error('categories', self::CATEGORIES);
        }
        $badTag = array_filter($d->tags, static fn (string $t): bool => mb_strlen($t) < self::TAG_MIN || mb_strlen($t) > self::TAG_MAX);
        if (count($d->tags) > self::TAGS_MAX || $badTag !== [] || mb_strlen(implode(',', $d->tags)) > 320) {
            $r->error('tags', 'Use up to 8 tags, each 2 to 40 characters.');
        }
        if ($d->verdict !== '' ? !in_array($d->verdict, Draft::VERDICTS, true) : $ctx->origin !== 'manual') {
            $r->error('verdict', 'Choose a verdict.');
        }
        if ($len($d->verdictNote) > self::VERDICT_NOTE_MAX) {
            $r->error('verdict_note', 'Keep the verdict note to 320 characters.');
        }
        if ($len($d->byline) > self::BYLINE_MAX) {
            $r->error('byline', 'Keep the byline to 120 characters.');
        }
        if ($ctx->origin === 'import' && ($d->importKey === '' || $len($d->importKey) > self::KEY_MAX)) {
            $r->error('key', 'Give the file a key of up to 190 characters.');
        }
    }

    /**
     * Every follow-up must be an article that exists (not this one); they are resolved to ids. Returns the
     * canonical keys of their sources, which an AI draft may link to.
     *
     * @return list<string>
     */
    private function checkFollowUps(Draft $d, Context $ctx, Result $r): array
    {
        $ids = [];
        foreach ($d->followUps as $f) {
            $row = is_int($f) ? $this->store->find($f) : $this->store->findBySlug((string) $f, false);
            if ($row === null || (int) $row['id'] === $ctx->editingId) {
                if ($ctx->origin === 'manual') {
                    $r->error('follow_up_of', 'Choose the earlier articles from the list.');
                } else {
                    $r->reject('follow_up', 'The earlier article ' . $f . ' does not exist.');
                }
                continue;
            }
            if (!in_array((int) $row['id'], $ids, true)) {
                $ids[] = (int) $row['id'];
            }
        }
        $r->followUps = $ids;
        return array_values(array_unique(array_merge([], ...array_values($this->store->sourceKeys($ids)))));
    }

    private function checkSources(Draft $d, Context $ctx, Result $r, bool $publish): void
    {
        $ai = $ctx->origin === 'ai';
        $kept = [];
        foreach ($d->sources as $i => $s) {
            $field = 'sources.' . $i;
            $key = $s['url'] !== '' ? Urls::key($s['url']) : null;
            if ($key === null || mb_strlen($s['url']) > self::URL_MAX) {
                if ($ai) {
                    $r->warnings[] = 'Dropped a source that is not a web address on a named site.';
                } else {
                    $r->error($field . '.url', "Enter the source's web address, starting https:// or http://.");
                }
                continue;
            }
            if (Urls::onDomain($s['url'], $ctx->bannedDomains)) {
                if ($ai) {
                    $r->warnings[] = 'Dropped the source on a banned site: ' . Urls::host($s['url']) . '.';
                } else {
                    $r->error($field . '.url', self::BANNED);
                }
                continue;
            }
            if (mb_strlen($s['title']) > self::SOURCE_TITLE_MAX) {
                $r->error($field . '.title', 'Keep the source title to 300 characters.');
            }
            if (mb_strlen($s['publisher']) > self::PUBLISHER_MAX) {
                $r->error($field . '.publisher', 'Keep the publisher to 160 characters.');
            }
            $kept[] = $s;
        }
        if ($ai) {
            $d->sources = $kept;
        }
        if (count($d->sources) > self::SOURCES_MAX) {
            $r->error('sources', 'Use up to 12 sources.');
        }
    }

    private function checkItems(Draft $d, Context $ctx, Result $r, bool $publish): void
    {
        $ai = $ctx->origin === 'ai';
        $sourceKeys = $d->sourceKeys();
        foreach (['confirmed' => "What's confirmed", 'speculation' => 'Still speculation'] as $list => $label) {
            if (count($d->{$list}) > self::ITEMS_MAX) {
                $r->error($list, 'Keep ' . $label . ' to 12 items.');
            }
            $kept = [];
            foreach ($d->{$list} as $i => $item) {
                $field = $list . '.' . $i;
                if ($item['text'] === '') {
                    $r->error($field . '.text', 'Write the item, or clear its web address.');
                    continue;
                }
                if (mb_strlen($item['text']) > self::ITEM_MAX) {
                    $r->error($field . '.text', 'Keep each item to 400 characters.');
                }
                if ($item['url'] === '') {
                    if ($publish) {
                        $r->error($field . '.url', self::ITEM_SOURCE);
                    }
                    $kept[] = $item;
                    continue;
                }
                $key = Urls::key($item['url']);
                $problem = match (true) {
                    $key === null => "Enter the item's web address, starting https:// or http://.",
                    Urls::onDomain($item['url'], $ctx->bannedDomains) => self::BANNED,
                    !in_array($key, $sourceKeys, true) => $publish || $ai ? self::ITEM_SOURCE : null,
                    default => null,
                };
                if ($problem !== null) {
                    if ($ai) {
                        $r->warnings[] = 'Dropped a ' . $list . ' item whose source could not be used.';
                        continue;
                    }
                    $r->error($field . '.url', $problem);
                }
                $kept[] = $item;
            }
            if ($ai) {
                $d->{$list} = $kept;
            }
        }
    }

    /**
     * Links in the body and impact. Every writer's must be http(s) addresses on named sites, never on a banned
     * site; an AI draft's must also be on this site, one of the research's addresses, or a source of an article
     * it follows up, and any other keeps only its text.
     *
     * @param list<string> $followSources
     */
    private function checkLinks(Draft $d, Context $ctx, Result $r, array $followSources): void
    {
        $ai = $ctx->origin === 'ai';
        $allowed = array_merge($d->sourceKeys(), $followSources);
        foreach ($ctx->refs as $ref) {
            $key = is_array($ref) && is_string($ref['url'] ?? null) ? Urls::key($ref['url']) : null;
            if ($key !== null) {
                $allowed[] = $key;
            }
        }
        foreach (['body', 'impact'] as $field) {
            $d->{$field} = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/u', function (array $m) use ($d, $ctx, $r, $ai, $allowed, $field): string {
                $href = $m[2];
                if (Urls::isOwn($href, $ctx->baseUrl !== '' ? $ctx->baseUrl : 'https://invalid.invalid') || !preg_match('#^https?://#i', $href)) {
                    return $m[0];
                }
                $key = Urls::key($href);
                $problem = match (true) {
                    $key === null || mb_strlen($href) > self::URL_MAX => 'Each link needs a web address on a named site, starting https:// or http://.',
                    Urls::onDomain($href, $ctx->bannedDomains) => self::BANNED,
                    $ai && !in_array($key, $allowed, true) => 'not from the research',
                    default => null,
                };
                if ($problem === null) {
                    return $m[0];
                }
                if ($ai) {
                    $r->warnings[] = 'Unlinked "' . $m[1] . '": its address could not be used.';
                    return $m[1];
                }
                $r->error($field, $problem);
                return $m[0];
            }, $d->{$field});
        }
    }

    private function checkTopics(Draft $d, Context $ctx, Result $r): void
    {
        if ($ctx->bannedTopics === []) {
            return;
        }
        $hits = Prose::mentions(implode("\n\n", [$d->title, $d->dek, $d->body, $d->impact, implode(', ', $d->tags)]), $ctx->bannedTopics);
        if ($hits === []) {
            return;
        }
        if ($ctx->origin === 'manual') {
            $r->warnings[] = 'This article mentions a banned topic: ' . implode(', ', $hits) . '.';
        } else {
            $r->reject('banned_topic', 'Mentions a banned topic: ' . implode(', ', $hits) . '.');
        }
    }

    /**
     * A duplicate is refused unless it is a declared follow-up; a duplicate of an article the writer was not shown
     * that brings a source that article did not use becomes a follow-up. A follow-up must bring at least one source
     * none of the articles it follows used.
     */
    private function checkDuplicates(Draft $d, Context $ctx, Result $r): void
    {
        $mine = $d->sourceKeys();
        $declared = $r->followUps;
        foreach ($this->duplicates($d->title, $d->dek, $d->tags, $d->storyKey, $mine, $ctx) as $id => $match) {
            if (in_array($id, $declared, true)) {
                continue;
            }
            $seen = $ctx->seen !== null && in_array($id, $ctx->seen, true);
            $new = array_diff($mine, $match['source_keys']);
            if (!$seen && $new !== []) {
                $r->autoFollowUps[] = $id;
                $r->followUps[] = $id;
                $r->warnings[] = 'Kept as a follow-up to article ' . $id . ' (' . $match['why'] . '), because it brings a new source.';
                continue;
            }
            $r->reject('duplicate', 'Duplicates article ' . $id . ': ' . $match['why'] . ($new === [] ? ', and it brings no new source.' : '.'));
        }
        if ($declared !== []) {
            $used = array_merge([], ...array_values($this->store->sourceKeys($declared)));
            if (array_diff($mine, $used) === []) {
                $r->reject('follow_up', 'A follow-up must bring at least one source the earlier articles did not use.');
            }
        }
    }
}
