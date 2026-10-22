<?php

declare(strict_types=1);

namespace Psc\News;

/**
 * What Validator::check() found. A draft may be stored only when ok(): no field errors and no rejections. An
 * article with reasons in $hold is stored as a draft for an administrator, never published automatically. $draft is
 * the draft as it should be stored: cleaned, defaults filled in, AI links to unknown sites unlinked, follow-ups
 * resolved to article ids.
 *
 * Some errors in an AI draft are ones a rewrite of its text can fix (a headline, standfirst or body of the wrong
 * length): they are errors like any other, so ok() stays false, and repairable() says what to fix in words for
 * the model. onlyRepairable() is whether a rewrite could make the draft pass, so the desk repairs it instead of
 * dropping it; the repaired draft is checked again from the start.
 */
final class Result
{
    /** @var array<string, string> field => message, in an administrator's words (a field in a list: sources.2.url) */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<array{rule: string, detail: string}> rule-based refusals: refs, banned_domain, banned_topic, duplicate, follow_up */
    public array $rejections = [];

    /** @var list<string> reasons to keep the article as a draft */
    public array $hold = [];

    /** @var list<string> the phrases to avoid the text uses (an AI draft gets one repair, then is held) */
    public array $tells = [];

    /** @var list<int> the article ids this one follows up, the automatic follow-ups included */
    public array $followUps = [];

    /** @var list<int> duplicates of articles the writer was not shown, kept as follow-ups because of a new source */
    public array $autoFollowUps = [];

    /** How many em and en dashes the text held (replaced with commas on save). */
    public int $dashes = 0;

    /** @var array<string, string> field => what a rewrite must fix, in plain words for the model (AI drafts only) */
    private array $repairs = [];

    /** Whether an error was recorded that a rewrite cannot fix. */
    private bool $unrepairable = false;

    public function __construct(public Draft $draft)
    {
    }

    public function ok(): bool
    {
        return $this->errors === [] && $this->rejections === [];
    }

    public function held(): bool
    {
        return $this->hold !== [];
    }

    public function error(string $field, string $message): void
    {
        $this->errors[$field] ??= $message;
        $this->unrepairable = true;
    }

    /** An error a rewrite of the text can fix: $message for an administrator, $problem for the model. */
    public function repairError(string $field, string $message, string $problem): void
    {
        $this->errors[$field] ??= $message;
        $this->repairs[$field] ??= $problem;
    }

    /**
     * What a rewrite must fix, one problem a line, in plain words for the model.
     *
     * @return list<string>
     */
    public function repairable(): array
    {
        return array_values($this->repairs);
    }

    /** Whether the draft was refused only for problems a rewrite can fix: no rejection, and no other error. */
    public function onlyRepairable(): bool
    {
        return $this->repairs !== [] && $this->rejections === [] && !$this->unrepairable;
    }

    public function reject(string $rule, string $detail): void
    {
        $this->rejections[] = ['rule' => $rule, 'detail' => $detail];
    }

    /**
     * Every reason the draft was refused, one line each, for a log or a run report.
     *
     * @return list<string>
     */
    public function reasons(): array
    {
        $out = [];
        foreach ($this->errors as $field => $message) {
            $out[] = $field . ': ' . $message;
        }
        foreach ($this->rejections as $r) {
            $out[] = $r['rule'] . ': ' . $r['detail'];
        }
        return $out;
    }
}
