<?php

declare(strict_types=1);

namespace Psc\News;

/**
 * What Validator::check() judges a draft against: who wrote it, whether it is being saved as a draft or published,
 * the research's references (AI drafts), the desk's banned sites, banned topics and phrases to avoid, and the
 * articles a duplicate is looked for among. Settings::context() builds one from the stored settings.
 */
final class Context
{
    /**
     * @param string $origin manual, ai or import (Draft::ORIGINS)
     * @param string $mode draft (an administrator's draft needs only a headline) or publish (the full rules; also
     *                     schedule). AI and import drafts are always judged as publish.
     * @param array<string, array<string, mixed>> $refs AI only: "S1" => {url, title, via, page_age, cited}
     * @param list<string> $bannedDomains
     * @param list<string> $bannedTopics
     * @param list<string> $avoidPhrases
     * @param list<array<string, mixed>>|null $recent ArticleStore::recent() rows to look for duplicates among; null:
     *                                               the Validator reads the last 365 days itself
     * @param int|null $editingId the article being changed, never its own duplicate or follow-up
     * @param string $baseUrl the site's address: links to its host are its own
     * @param list<int>|null $seen the article ids the writer was shown (the desk's context). A duplicate of an
     *                             article it was not shown, bringing a new source, becomes a follow-up; null: none
     * @param int|null $now the time the rules are applied at (tests); null: now
     */
    public function __construct(
        public readonly string $origin,
        public readonly string $mode = 'publish',
        public readonly array $refs = [],
        public readonly array $bannedDomains = [],
        public readonly array $bannedTopics = [],
        public readonly array $avoidPhrases = [],
        public readonly ?array $recent = null,
        public readonly ?int $editingId = null,
        public readonly string $baseUrl = '',
        public readonly ?array $seen = null,
        public readonly ?int $now = null,
    ) {
        if (!in_array($origin, Draft::ORIGINS, true)) {
            throw new \InvalidArgumentException('Unknown article origin: ' . $origin);
        }
        if (!in_array($mode, ['draft', 'publish'], true)) {
            throw new \InvalidArgumentException('Unknown validation mode: ' . $mode);
        }
    }

    /**
     * A copy with some fields replaced, by their constructor names: $ctx->with(['refs' => $refs, 'seen' => $ids]).
     *
     * @param array<string, mixed> $fields
     */
    public function with(array $fields): self
    {
        return new self(...array_merge(get_object_vars($this), $fields));
    }

    /** Whether the full rules apply: always for AI and import drafts, on publish and schedule for manual ones. */
    public function publishing(): bool
    {
        return $this->mode === 'publish' || $this->origin !== 'manual';
    }
}
