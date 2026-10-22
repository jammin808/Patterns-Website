<?php

declare(strict_types=1);

namespace Psc\News;

use Psc\Content\Content;
use Psc\Support\Names;

/**
 * An article being created or changed, whoever writes it: the AI desk (origin ai), an import file (import) or an
 * administrator (manual). fromArray() reads the article JSON of docs/NEWS.md §6.4 and is the only way text comes
 * in, so every writer's text is cleaned the same way: UTF-8 only, no control, invisible or reordering characters,
 * single-line fields on one line, tags in lower case.
 *
 * An AI draft names its sources by reference (S1, S2 ...) and fromArray() writes the addresses from the research's
 * references, so an address can never be mistyped or invented: an unknown reference is dropped (from the sources,
 * from the confirmed and speculation items, and from inline links, which keep their text), a confirmed item resting
 * on a page the desk neither fetched nor cited moves to speculation, and an item's reference is listed as a source
 * if the draft forgot to. Each such change is a note. Import and manual drafts give full addresses instead.
 *
 * Validator::check() decides whether a draft may be saved or published; ArticleStore writes it.
 */
final class Draft
{
    public const ORIGINS = ['manual', 'ai', 'import'];
    public const STATUSES = ['draft', 'published'];
    public const VERDICTS = ['adopt', 'test', 'watch', 'skip', 'note'];
    public const KINDS = ['primary', 'press', 'report', 'standard', 'other'];

    /** The byline of every AI-written article unless content/news.php names another. */
    public const DESK_BYLINE = 'Industry Updates desk';

    public string $title = '';
    /** The <title>; empty: the headline cut to 60 characters (seoTitleOrDefault()). */
    public string $seoTitle = '';
    /** The standfirst. */
    public string $dek = '';
    /** The meta description; empty: the standfirst cut to 155 characters (summaryOrDefault()). */
    public string $summary = '';
    /** Markdown. */
    public string $body = '';
    /** @var list<array{text: string, url: string}> */
    public array $confirmed = [];
    /** @var list<array{text: string, url: string}> */
    public array $speculation = [];
    /** Markdown: what it means for freelancers and corporate events. */
    public string $impact = '';
    /** One of VERDICTS, or '' (manual articles only). */
    public string $verdict = '';
    public string $verdictNote = '';
    /** @var list<string> category keys, the primary first */
    public array $categories = [];
    /** @var list<string> lower case */
    public array $tags = [];
    /** @var list<array{url: string, title: string, publisher: string, kind: string}> */
    public array $sources = [];
    /** @var list<int|string> the earlier articles this one follows up: ids, or slugs in import files */
    public array $followUps = [];
    public string $storyKey = '';
    public string $byline = '';
    /** draft or published; a published article whose publishAt is in the future is scheduled. */
    public string $status = 'draft';
    public ?int $publishAt = null;
    public bool $commentsOpen = true;
    /** Carries the AI note and the desk's byline: every ai article, imports unless the file says not, manual when ticked. */
    public bool $aiWritten = false;
    /** The address slug to use; null: made from the headline (create) or kept (update). */
    public ?string $slug = null;
    /** An import file's key (news_imports.import_key). */
    public string $importKey = '';
    /** Why an AI article was held as a draft. */
    public ?string $reviewReason = null;
    /** @var array<string, string> field => what could not be read (Validator reports them as errors) */
    public array $problems = [];
    /** @var list<array{rule: string, detail: string}> what fromArray() changed in an AI draft (Validator warns) */
    public array $notes = [];

    public function __construct(public string $origin = 'manual')
    {
        if (!in_array($origin, self::ORIGINS, true)) {
            throw new \InvalidArgumentException('Unknown article origin: ' . $origin);
        }
    }

    /** The byline of desk-written articles (content/news.php 'byline'). */
    public static function deskByline(): string
    {
        $byline = Content::get('news')['byline'] ?? null;
        return is_string($byline) && $byline !== '' ? $byline : self::DESK_BYLINE;
    }

    /**
     * Reads the article JSON (docs/NEWS.md §6.4): title, seo_title, dek, summary, body, confirmed and speculation
     * ([{text, ref}] from the desk, [{text, url}] otherwise), impact, verdict, verdict_note, categories, tags,
     * sources ([{ref, publisher, kind}] from the desk, [{url, title, publisher, kind}] otherwise), follow_up_of,
     * story_key; import files add key, published_at (ISO 8601), byline and ai_written; the editor adds byline,
     * status, publish_at (Unix seconds or a date and time), comments_open, ai_written and slug.
     *
     * @param array<string, mixed> $data
     * @param array<string, array<string, mixed>> $refs the research's references (AI drafts): "S1" => {url, title, via, page_age, cited}
     */
    public static function fromArray(array $data, string $origin, array $refs = []): self
    {
        $d = new self($origin);
        $d->title = self::line($data['title'] ?? '');
        $d->seoTitle = self::line($data['seo_title'] ?? '');
        $d->dek = self::line($data['dek'] ?? '');
        $d->summary = self::line($data['summary'] ?? '');
        $d->body = self::text($data['body'] ?? '');
        $d->impact = self::text($data['impact'] ?? '');
        $d->verdict = strtolower(self::line($data['verdict'] ?? ''));
        $d->verdictNote = self::line($data['verdict_note'] ?? '');
        $d->categories = self::keys($data['categories'] ?? []);
        $d->tags = self::tags($data['tags'] ?? []);
        $d->storyKey = Prose::slug(self::line($data['story_key'] ?? ''), 190);
        $d->commentsOpen = self::flag($data['comments_open'] ?? true, true);
        $slug = Prose::slug(self::line($data['slug'] ?? ''));
        $d->slug = $slug !== '' ? $slug : null;
        $reason = self::line($data['review_reason'] ?? '');
        $d->reviewReason = $reason !== '' ? Prose::cut($reason, 300) : null;
        $d->importKey = self::line($data['key'] ?? '');

        $status = strtolower(self::line($data['status'] ?? ($origin === 'import' ? 'published' : 'draft')));
        if (in_array($status, self::STATUSES, true)) {
            $d->status = $status;
        } else {
            $d->problems['status'] = 'Choose draft or published.';
        }
        $at = $data['publish_at'] ?? $data['published_at'] ?? null;
        if ($at !== null && $at !== '') {
            $d->publishAt = self::time($at);
            if ($d->publishAt === null) {
                $d->problems['published_at'] = 'Enter the publishing time as a date and time, for example 2026-10-22 06:30.';
            }
        }
        foreach (is_array($data['follow_up_of'] ?? null) ? $data['follow_up_of'] : [] as $f) {
            if (is_int($f) || (is_string($f) && ctype_digit($f) && $origin !== 'import')) {
                $d->followUps[] = (int) $f;
            } elseif (is_string($f) && trim($f) !== '') {
                $d->followUps[] = strtolower(trim($f));
            }
        }
        $d->followUps = array_values(array_unique($d->followUps, SORT_REGULAR));

        $byline = self::line($data['byline'] ?? '');
        $d->aiWritten = match ($origin) {
            'ai' => true,
            'import' => self::flag($data['ai_written'] ?? true, true),
            default => self::flag($data['ai_written'] ?? false, false),
        };
        $d->byline = match ($origin) {
            'ai' => self::deskByline(),
            'import' => $byline !== '' ? $byline : self::deskByline(),
            default => $byline,
        };

        if ($origin === 'ai') {
            $d->readReferenced($data, self::refMap($refs));
        } else {
            $d->sources = self::sourceRows($data['sources'] ?? []);
            $d->confirmed = self::itemRows($data['confirmed'] ?? []);
            $d->speculation = self::itemRows($data['speculation'] ?? []);
        }
        return $d;
    }

    /**
     * The draft as the same JSON shape fromArray() reads, with addresses in place of references, so that
     * Draft::fromArray($d->toArray(), 'manual') is the same draft (an editor keeps a failed save this way).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'seo_title' => $this->seoTitle,
            'dek' => $this->dek,
            'summary' => $this->summary,
            'body' => $this->body,
            'confirmed' => $this->confirmed,
            'speculation' => $this->speculation,
            'impact' => $this->impact,
            'verdict' => $this->verdict,
            'verdict_note' => $this->verdictNote,
            'categories' => $this->categories,
            'tags' => $this->tags,
            'sources' => $this->sources,
            'follow_up_of' => $this->followUps,
            'story_key' => $this->storyKey,
            'byline' => $this->byline,
            'origin' => $this->origin,
            'status' => $this->status,
            'publish_at' => $this->publishAt,
            'comments_open' => $this->commentsOpen,
            'ai_written' => $this->aiWritten,
            'slug' => $this->slug,
            'key' => $this->importKey,
            'review_reason' => $this->reviewReason,
        ];
    }

    /**
     * A copy as it is stored: Prose::undash() on every text field (an em dash never reaches the database, whoever
     * wrote it), tags in lower case without repeats.
     */
    public function normalised(): self
    {
        $d = clone $this;
        foreach (['title', 'seoTitle', 'dek', 'summary', 'body', 'impact', 'verdictNote', 'byline'] as $field) {
            $d->{$field} = trim(Prose::undash($d->{$field}));
        }
        foreach (['confirmed', 'speculation'] as $list) {
            $d->{$list} = array_map(static fn (array $i): array => ['text' => trim(Prose::undash($i['text'])), 'url' => $i['url']], $d->{$list});
        }
        $d->sources = array_map(static fn (array $s): array => [
            'url' => $s['url'],
            'title' => trim(Prose::undash($s['title'])),
            'publisher' => trim(Prose::undash($s['publisher'])),
            'kind' => $s['kind'],
        ], $d->sources);
        $d->tags = array_values(array_unique(array_filter(array_map(static fn (string $t): string => trim(mb_strtolower(Prose::undash($t)), " \t,"), $d->tags), static fn (string $t): bool => $t !== '')));
        return $d;
    }

    /** The <title>: the SEO title, or the headline cut to 60 characters. */
    public function seoTitleOrDefault(): string
    {
        return $this->seoTitle !== '' ? $this->seoTitle : Prose::cut($this->title, 60);
    }

    /**
     * The address a new article is given when the draft names none: the SEO title when it is one of its own (not
     * the headline cut short), which makes a shorter address, else the headline; at most Prose::SLUG_MAX characters
     * before ArticleStore numbers it to be unique, and 'article' when nothing is left.
     */
    public function slugBase(): string
    {
        $own = $this->seoTitle !== '' && $this->seoTitle !== Prose::cut($this->title, 60);
        $slug = Prose::slug($own ? $this->seoTitle : $this->title);
        if ($slug === '' && $own) {
            $slug = Prose::slug($this->title);
        }
        return $slug !== '' ? $slug : 'article';
    }

    /** The meta description: the summary, or the standfirst as plain text cut to 155, padded with the headline under 50. */
    public function summaryOrDefault(): string
    {
        if ($this->summary !== '') {
            return $this->summary;
        }
        $summary = Prose::cut(Prose::plain($this->dek), 155);
        if (mb_strlen($summary) < 50) {
            $summary = Prose::cut(trim(($summary !== '' ? rtrim($summary, '.') . '. ' : '') . $this->title), 155);
        }
        return $summary;
    }

    /**
     * Every text field by the name an error is reported under: title, seo_title, dek, summary, body, impact,
     * verdict_note, byline, tags, confirmed.N.text, speculation.N.text, sources.N.title, sources.N.publisher.
     *
     * @return array<string, string>
     */
    public function texts(): array
    {
        $out = [
            'title' => $this->title,
            'seo_title' => $this->seoTitle,
            'dek' => $this->dek,
            'summary' => $this->summary,
            'body' => $this->body,
            'impact' => $this->impact,
            'verdict_note' => $this->verdictNote,
            'byline' => $this->byline,
            'tags' => implode(', ', $this->tags),
        ];
        foreach (['confirmed', 'speculation'] as $list) {
            foreach ($this->{$list} as $i => $item) {
                $out[$list . '.' . $i . '.text'] = $item['text'];
            }
        }
        foreach ($this->sources as $i => $s) {
            $out['sources.' . $i . '.title'] = $s['title'];
            $out['sources.' . $i . '.publisher'] = $s['publisher'];
        }
        return $out;
    }

    /** How many em and en dashes the text fields hold (Prose::undash() replaces them on save). */
    public function dashCount(): int
    {
        return array_sum(array_map(Prose::countDashes(...), $this->texts()));
    }

    /** @return list<string> the canonical keys of the sources' addresses */
    public function sourceKeys(): array
    {
        $keys = [];
        foreach ($this->sources as $s) {
            $key = Urls::key($s['url']);
            if ($key !== null && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /**
     * Every link written as [text](address) in the body, the impact and the confirmed and speculation items, whose
     * text is Markdown too.
     *
     * @return list<string>
     */
    public function inlineLinks(): array
    {
        $md = implode("\n", [$this->body, $this->impact, ...array_column($this->confirmed, 'text'), ...array_column($this->speculation, 'text')]);
        preg_match_all('/\[[^\]]+\]\(([^)\s]+)\)/u', $md, $m);
        return array_values(array_unique($m[1]));
    }

    /**
     * The AI draft's sources, items and inline links, by reference (see the class comment).
     *
     * @param array<string, mixed> $data
     * @param array<string, array{url: string, title: string, via: string, cited: bool}> $refs
     */
    private function readReferenced(array $data, array $refs): void
    {
        $seen = [];
        $addSource = function (array $ref, string $publisher, string $kind) use (&$seen): void {
            if (isset($seen[$ref['url']])) {
                return;
            }
            $seen[$ref['url']] = true;
            $this->sources[] = ['url' => $ref['url'], 'title' => Prose::cut($ref['title'], 300), 'publisher' => $publisher, 'kind' => $kind];
        };
        foreach (is_array($data['sources'] ?? null) ? $data['sources'] : [] as $s) {
            if (!is_array($s)) {
                continue;
            }
            $ref = self::refName($s['ref'] ?? '');
            if ($ref === '') {
                continue;
            }
            if (!isset($refs[$ref])) {
                $this->notes[] = ['rule' => 'refs', 'detail' => 'Dropped the source ' . $ref . ': the research has no such reference.'];
                continue;
            }
            $addSource($refs[$ref], self::line($s['publisher'] ?? ''), self::kind($s['kind'] ?? ''));
        }
        $moved = [];
        foreach (['confirmed', 'speculation'] as $list) {
            foreach (is_array($data[$list] ?? null) ? $data[$list] : [] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $text = self::line($item['text'] ?? '');
                $ref = self::refName($item['ref'] ?? '');
                if ($text === '' && $ref === '') {
                    continue;
                }
                if (!isset($refs[$ref])) {
                    $this->notes[] = ['rule' => 'refs', 'detail' => 'Dropped a ' . $list . ' item citing ' . ($ref !== '' ? $ref : 'no reference') . ', which the research did not return.'];
                    continue;
                }
                // An item's text is Markdown, and its links cite references the way the body's do.
                $text = $this->mapLinks($text, $refs);
                $r = $refs[$ref];
                $addSource($r, '', 'other');
                if ($list === 'confirmed' && $r['via'] !== 'fetch' && !$r['cited']) {
                    $this->notes[] = ['rule' => 'refs', 'detail' => 'Moved a confirmed item to speculation: ' . $ref . ' was neither fetched nor cited.'];
                    $moved[] = ['text' => $text, 'url' => $r['url']];
                    continue;
                }
                $this->{$list}[] = ['text' => $text, 'url' => $r['url']];
            }
        }
        $this->speculation = array_merge($this->speculation, $moved);
        $this->body = $this->mapLinks($this->body, $refs);
        $this->impact = $this->mapLinks($this->impact, $refs);
    }

    /**
     * [text](S3) becomes [text](the address of S3); a link to a reference the research did not return keeps only
     * its text.
     *
     * @param array<string, array{url: string, title: string, via: string, cited: bool}> $refs
     */
    private function mapLinks(string $md, array $refs): string
    {
        return (string) preg_replace_callback('/\[([^\]]+)\]\(\s*([Ss]\d+)\s*\)/u', function (array $m) use ($refs): string {
            $ref = strtoupper($m[2]);
            if (!isset($refs[$ref])) {
                $this->notes[] = ['rule' => 'refs', 'detail' => 'Unlinked "' . $m[1] . '": ' . $ref . ' is not a reference.'];
                return $m[1];
            }
            return '[' . $m[1] . '](' . str_replace([' ', '(', ')'], ['%20', '%28', '%29'], $refs[$ref]['url']) . ')';
        }, $md);
    }

    /**
     * @param array<string, array<string, mixed>> $refs
     * @return array<string, array{url: string, title: string, via: string, cited: bool}>
     */
    private static function refMap(array $refs): array
    {
        $out = [];
        foreach ($refs as $name => $ref) {
            $name = self::refName($name);
            if ($name === '' || !is_array($ref) || !is_string($ref['url'] ?? null) || trim($ref['url']) === '') {
                continue;
            }
            $out[$name] = [
                'url' => trim($ref['url']),
                'title' => self::line($ref['title'] ?? ''),
                'via' => strtolower(self::line($ref['via'] ?? '')),
                'cited' => !empty($ref['cited']),
            ];
        }
        return $out;
    }

    private static function refName(mixed $ref): string
    {
        $ref = strtoupper(self::line($ref));
        return preg_match('/^S\d{1,4}$/', $ref) ? $ref : '';
    }

    /** @return list<array{url: string, title: string, publisher: string, kind: string}> */
    private static function sourceRows(mixed $rows): array
    {
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $s) {
            if (!is_array($s)) {
                continue;
            }
            $row = [
                'url' => self::line($s['url'] ?? ''),
                'title' => self::line($s['title'] ?? ''),
                'publisher' => self::line($s['publisher'] ?? ''),
                'kind' => self::kind($s['kind'] ?? ''),
            ];
            if ($row['url'] !== '' || $row['title'] !== '' || $row['publisher'] !== '') {
                $out[] = $row;
            }
        }
        return $out;
    }

    /** @return list<array{text: string, url: string}> */
    private static function itemRows(mixed $rows): array
    {
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $item) {
            if (is_string($item)) {
                $item = ['text' => $item];
            }
            if (!is_array($item)) {
                continue;
            }
            $row = ['text' => self::line($item['text'] ?? ''), 'url' => self::line($item['url'] ?? '')];
            if ($row['text'] !== '' || $row['url'] !== '') {
                $out[] = $row;
            }
        }
        return $out;
    }

    private static function kind(mixed $kind): string
    {
        $kind = strtolower(self::line($kind));
        return in_array($kind, self::KINDS, true) ? $kind : 'other';
    }

    /** @return list<string> */
    private static function keys(mixed $list): array
    {
        $out = [];
        foreach (is_array($list) ? $list : [] as $k) {
            $k = strtolower(self::line($k));
            if ($k !== '' && !in_array($k, $out, true)) {
                $out[] = $k;
            }
        }
        return $out;
    }

    /** @return list<string> */
    private static function tags(mixed $tags): array
    {
        $parts = is_string($tags) ? explode(',', $tags) : (is_array($tags) ? $tags : []);
        $out = [];
        foreach ($parts as $tag) {
            foreach (explode(',', self::line($tag)) as $t) {
                $t = mb_strtolower(trim($t));
                if ($t !== '' && !in_array($t, $out, true)) {
                    $out[] = $t;
                }
            }
        }
        return $out;
    }

    private static function flag(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
        }
        return $default;
    }

    /** Unix seconds from an int, digits, or a date and time (ISO 8601; without an offset, in the site's time zone). */
    private static function time(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (ctype_digit($value)) {
            return (int) $value > 0 ? (int) $value : null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/', $value)) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }

    /** A single-line text: UTF-8 only, every run of whitespace one space, no control or invisible characters. */
    private static function line(mixed $value): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return '';
        }
        $v = (string) $value;
        if (!mb_check_encoding($v, 'UTF-8')) {
            return '';
        }
        return Names::visible(trim((string) preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $v)));
    }

    /** A multi-line text: UTF-8 only, Unix line ends, no control characters but tab and newline, no invisible ones. */
    private static function text(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            return '';
        }
        $v = str_replace(["\r\n", "\r"], "\n", $value);
        $v = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
        return trim((string) preg_replace(Names::INVISIBLE, '', $v));
    }
}
