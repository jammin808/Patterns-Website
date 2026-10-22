<?php

declare(strict_types=1);

namespace Psc\News;

use Psc\App;
use Psc\Db\Database;

/**
 * The AI desk's tick and session (docs/NEWS.md §6).
 *
 * A tick (tools/news.php, every 15 minutes) writes the heartbeat, reaps runs whose process died (a failed run, so
 * the admin is told), imports the repository's article files, runs the session that is queued or due, and writes the
 * heartbeat again, each step on its own so one failing never stops the others; a session the tick runs refreshes the
 * heartbeat before its model calls, so a long one never reads as a stopped cron. A due slot is claimed once, and an
 * older slot due beside it lapses (claimed and skipped), so it never runs late after it; when the desk is paused, has
 * no key or SDK, or the session could take the month past its cap, the claimed run is recorded as skipped with the
 * reason and nothing is spent.
 *
 * A session holds the lease until its deadline and runs: context, research (web tools, free text, the brief and its
 * numbered references stored as soon as it ends), select (every candidate with a decision; the server applies the
 * count, the importance bar, the window and dedup itself), compose (one call per story, so one bad article never
 * sinks the others), validate (the shared Validator, one repair for the phrases to avoid and the limits it can fix),
 * check (Claude Haiku 5.5 reads all of the article's public text; a flag or no answer holds the article for a person)
 * and publish (one transaction under the lease, dedup checked again against the database, published times 10 to 30
 * minutes apart). Only a live article is offered or accepted as a follow-up; drafts and held articles stay in the
 * context, marked, so their stories still count as duplicates. A research answer that was refused, or whose brief has
 * no end line, is never used.
 *
 * Money: before every model call the deadline, the session's spending ceiling and the lease are checked; after
 * every response its usage and estimated cost are written to the run, so a run that fails still shows what it spent.
 * The database is checked (App::dbAlive()) before every database step that follows a model call, because a long
 * call can outlive a MySQL connection. A failure ends the run as failed with its reason; nothing is half-published,
 * and a failed slot is never retried (the searches would be billed again).
 */
final class Pipeline
{
    public const RESEARCH_TOKENS = 32000;
    public const SELECT_TOKENS = 16000;
    public const COMPOSE_TOKENS = 24000;
    public const CHECK_TOKENS = 4000;

    /** The articles the desk is shown: the last 120 days, at most 150. */
    public const CONTEXT_DAYS = 120;
    public const CONTEXT_MAX = 150;

    /** A story's newest source is at most this old: 7 days, 14 for legal, regulatory and standards news. */
    public const NEWS_DAYS = 7;
    public const WINDOW_DAYS = 14;

    /** A session in a tick refreshes the heartbeat before a model call once this many seconds have passed since it was written. */
    public const HEARTBEAT_EVERY = 300;

    /** The references kept from one research call, the first seen first. */
    public const MAX_REFS = 120;

    /** A run's log keeps this many steps. */
    public const MAX_STEPS = 80;

    /** Why a claimed run did not start, as the run list says it. */
    public const SKIPS = [
        'paused' => 'Skipped: the desk is paused.',
        'no_key' => 'Skipped: no Anthropic key is set for the desk (news.api_key, or chat.api_key).',
        'sdk_missing' => 'Skipped: the Anthropic SDK is not installed on the server.',
        'budget' => "Skipped: this session could take the month's estimated spend past its cap.",
        'lapsed' => 'Skipped: a later session was due at the same time and ran in its place.',
    ];

    private Settings $settings;
    private Runs $runs;
    private ArticleStore $store;
    private Validator $validator;

    // The session being run.
    private int $runId = 0;
    /** Whether the session runs inside a cron tick, whose heartbeat it keeps fresh. */
    private bool $ticking = false;
    private int $deadline = 0;
    /** @var array<string, int> */
    private array $usage = [];
    private int $costMicros = 0;
    /** @var array<string, true> */
    private array $models = [];
    /** @var list<array{t: int, step: string, ms: int, note: string, counts: array<string, int>}> */
    private array $log = [];

    public function __construct(private readonly App $app)
    {
        $this->settings = new Settings($app);
        $this->runs = new Runs($app);
        $this->store = new ArticleStore($app);
        $this->validator = new Validator($this->store);
    }

    /**
     * Why a session may not start now, or null: paused, no_key, sdk_missing (no model could be built) or budget
     * (this month's estimated spend plus the 90th-percentile cost of a session would pass the cap).
     */
    public static function skipReason(App $app, Settings $settings, Runs $runs, bool $modelAvailable): ?string
    {
        return match (true) {
            $settings->get('enabled') !== true => 'paused',
            Settings::apiKey($app) === '' => 'no_key',
            !$modelAvailable => 'sdk_missing',
            $runs->spentThisMonth() + $runs->p90Cost() > (float) $settings->get('monthly_budget_usd') => 'budget',
            default => null,
        };
    }

    /**
     * One cron tick. $model is the desk's model, or null when none can be built (the SDK is missing). The report is
     * the one JSON line tools/news.php prints and logs: its usage keys are usage_in and usage_out, never "tokens",
     * which the log would mask.
     *
     * @return array<string, mixed>
     */
    public function tick(\DateTimeImmutable $now, ?NewsModel $model): array
    {
        $report = self::emptyTick();
        $this->beat($report, ['run' => null, 'status' => 'started']);
        try {
            // Deadlines are real times, whatever time the tick is asked about.
            $reaped = $this->runs->reapStale(time());
            $report['reaped'] = count($reaped);
            if ($reaped !== []) {
                $this->afterwards(['status' => 'failed', 'summary' => Runs::REAPED], max($reaped));
            }
        } catch (\Throwable $e) {
            $this->tickFailed($report, 'reap', $e);
        }
        try {
            $imported = (new Importer($this->app))->run();
            $report['imported'] = count($imported['imported']);
            $report['import_failed'] = count($imported['failed']);
        } catch (\Throwable $e) {
            $this->tickFailed($report, 'import', $e);
        }
        try {
            $queued = $this->runs->queued();
            $id = $queued !== null ? (int) $queued['id'] : null;
            // A slot claimed by a tick that died before starting it is never run late, outside its window.
            if ($queued !== null && $queued['kind'] === 'scheduled' && (int) $queued['created_at'] < time() - Schedule::WINDOW) {
                $this->runs->skip((int) $id, 'Skipped: the tick that claimed it stopped before the session started, and its time has passed.');
                $id = null;
            }
            if ($id === null) {
                $last = $this->runs->lastStarted();
                $taken = $this->runs->slotsSince($now->getTimestamp() - 2 * 86400);
                $due = Schedule::slotDue($now, $this->settings, $last, $taken);
                $id = $due !== null ? $this->runs->claim($due, 'scheduled', null) : null;
                // The latest due slot runs; every older one still due lapses, claimed and skipped, so it never runs late.
                while ($due !== null) {
                    $taken[] = $due;
                    $due = Schedule::slotDue($now, $this->settings, $last, $taken);
                    $lapsed = $due !== null ? $this->runs->claim($due, 'scheduled', null) : null;
                    if ($lapsed !== null) {
                        $this->runs->skip($lapsed, self::SKIPS['lapsed']);
                    }
                }
            }
            if ($id !== null) {
                $this->ticking = true;
                try {
                    $report = $this->startRun($id, $model, $now) + $report;
                } finally {
                    $this->ticking = false;
                }
            }
        } catch (\Throwable $e) {
            $this->tickFailed($report, 'session', $e);
        }
        $this->beat($report, ['run' => $report['run'], 'status' => $report['status']]);
        $this->app->log->info('news tick', $report);
        return $report;
    }

    /**
     * A session now (tools/news.php run): queued and started at once, refused while another is queued or running.
     *
     * @return array<string, mixed>
     */
    public function runNow(?NewsModel $model, \DateTimeImmutable $now, ?int $userId = null): array
    {
        $id = $this->runs->queueManual($userId);
        if ($id === null) {
            return ['status' => 'refused', 'summary' => 'A session is already queued or running.'] + self::emptyTick();
        }
        return $this->startRun($id, $model, $now) + self::emptyTick();
    }

    /**
     * Runs one queued session (docs/NEWS.md §6.2) and returns its report. A run that is not queued is left alone.
     *
     * @return array<string, mixed>
     */
    public function run(int $runId, NewsModel $model, \DateTimeImmutable $now): array
    {
        $this->runId = $runId;
        $this->usage = ['usage_in' => 0, 'usage_out' => 0, 'cache_write' => 0, 'cache_read' => 0, 'searches' => 0, 'fetches' => 0];
        $this->costMicros = 0;
        $this->models = [];
        $this->log = [];
        $this->deadline = time() + max(5, $this->app->config->int('news.session_minutes', 40)) * 60;
        $row = $this->runs->find($runId);
        if ($row === null || !$this->runs->start($runId, $this->deadline)) {
            return $this->report('refused', 'The run was not waiting to start, so it was left as it was.');
        }
        if (!$this->settings->lease($runId, $this->deadline)) {
            $summary = 'Failed: another session held the desk, so this one did not start.';
            $this->runs->finish($runId, 'failed', ['summary' => $summary]);
            $outcome = $this->report('failed', $summary);
            $this->afterwards($outcome, $runId);
            return $outcome;
        }
        $this->step('start', microtime(true), $row['kind'] === 'manual' ? 'Started by hand.' : 'The ' . Schedule::label((string) $row['slot']) . ' session.');
        try {
            $outcome = $this->session($row, $model, $now);
        } catch (\Throwable $e) {
            $outcome = $this->failed($e);
        } finally {
            try {
                $this->settings->releaseLease($runId);
            } catch (\Throwable $e) {
                $this->app->log->exception($e, 'news lease release');
            }
        }
        $this->afterwards($outcome, $runId);
        return $outcome;
    }

    // ------------------------------------------------------------------------------------------------ the session

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function session(array $row, NewsModel $model, \DateTimeImmutable $now): array
    {
        $n = (int) $this->settings->get('max_per_session');
        $min = (int) $this->settings->get('min_importance');

        // 1. Context.
        $t = microtime(true);
        $recent = $this->store->recent(self::CONTEXT_DAYS, self::CONTEXT_MAX);
        $seen = array_map(static fn (array $a): int => (int) $a['id'], $recent);
        $context = $this->context($recent, $n, $min);
        $this->step('context', $t, '', ['articles' => count($recent)]);

        // 2. Research.
        $t = microtime(true);
        $research = $model->research($this->call(
            (string) $this->settings->get('research_model'),
            Prompts::researchRules() . "\n\n" . $this->settings->brief(),
            $this->researchTurn($row, $now, $context, $n),
            self::RESEARCH_TOKENS,
            'high',
            tools: $this->tools(),
        ));
        $this->app->dbAlive();
        $refs = array_slice($research['refs'], 0, self::MAX_REFS, true);
        // A refused answer is discarded whole; a brief is only ever its finished parts (between both marker lines).
        $refused = $research['stop'] === 'refusal';
        $brief = $refused ? '' : trim((string) $research['text']);
        $this->runs->record($this->runId, ['brief' => $brief !== '' ? $brief : null, 'refs' => $refs] + $this->totals());
        $this->step('research', $t, 'Stopped with ' . $research['stop'] . ' on ' . $research['model'] . '.', [
            'refs' => count($refs), 'searches' => $this->usage['searches'], 'fetches' => $this->usage['fetches'],
        ]);
        if ($refused) {
            throw new \RuntimeException('The research was declined (it stopped with refusal), so its answer was discarded.');
        }
        if ($brief === '') {
            throw new \RuntimeException('The research ended without a finished brief (it stopped with ' . $research['stop'] . ').');
        }

        // 3. Select.
        $t = microtime(true);
        $select = $model->structured($this->call(
            (string) $this->settings->get('compose_model'),
            Prompts::selectRules(),
            [['role' => 'user', 'content' => $this->selectTurn($brief, $refs, $context, $n, $min, $now)]],
            self::SELECT_TOKENS,
            'medium',
            schema: Prompts::selectSchema(),
        ));
        $this->app->dbAlive();
        if ($select['data'] === null) {
            throw new \RuntimeException('The choice of stories came back without a usable answer (it stopped with ' . $select['stop'] . ').');
        }
        $considered = $this->candidates($select['data'], $refs);
        [$considered, $chosen] = $this->pick($considered, $refs, $seen, $now, $n, $min);
        $this->runs->record($this->runId, ['candidates' => count($considered), 'considered' => $considered]);
        $this->step('select', $t, '', ['candidates' => count($considered), 'chosen' => count($chosen)]);
        if ($chosen === []) {
            return $this->end('skipped', self::slowNews($considered), $considered, [], []);
        }

        // 4 to 6. Compose, validate and check each story; one that fails is dropped and the others go on.
        $ctx = $this->settings->context('ai')->with(['refs' => $refs, 'seen' => $seen]);
        $ready = [];
        foreach ($chosen as $i) {
            $item = $this->write($model, $considered[$i], $brief, $refs, $context, $ctx);
            $considered[$i]['outcome'] = $item['outcome'];
            if ($item['result'] !== null) {
                $ready[$i] = $item;
            }
        }

        // 7. Publish.
        $t = microtime(true);
        $this->app->dbAlive();
        if (!$this->settings->lease($this->runId, $this->deadline)) {
            throw new \RuntimeException('The session lost the desk before publishing, so nothing was published.');
        }
        [$published, $drafts, $held, $outcomes] = $this->publish($ready, $ctx, $seen);
        foreach ($outcomes as $i => $outcome) {
            $considered[$i]['outcome'] = $outcome;
        }
        $this->step('publish', $t, '', ['published' => count($published), 'drafts' => count($drafts), 'held' => count($held)]);
        $written = count($published) + count($drafts);
        $summary = match (true) {
            $written === 0 => 'Nothing was written: every chosen story was dropped (see the candidates).',
            $published === [] && $held === [] => 'Wrote ' . self::articles(count($drafts)) . ' as drafts for review (publishing automatically is off).',
            default => trim(($published !== [] ? 'Published ' . self::articles(count($published)) . '.' : '')
                . ($held !== [] ? ' Held ' . self::articles(count($held)) . ' for review.' : '')
                . (count($drafts) > count($held) ? ' Kept ' . self::articles(count($drafts) - count($held)) . ' as drafts.' : '')),
        };
        return $this->end('done', $summary, $considered, $published, $drafts, $held);
    }

    /**
     * Composes, validates, repairs and checks one story. result is null when it was dropped; hold lists why a
     * person must read it first.
     *
     * @param array<string, mixed> $candidate
     * @param array<string, array<string, mixed>> $refs
     * @return array{result: ?Result, hold: list<string>, outcome: array<string, mixed>}
     */
    private function write(NewsModel $model, array $candidate, string $brief, array $refs, string $context, Context $ctx): array
    {
        $t = microtime(true);
        $headline = (string) $candidate['headline'];
        $drop = function (string $why, array $rules = []) use ($t, $headline): array {
            $this->step('compose', $t, Prose::cut($headline, 120) . ': ' . $why);
            return ['result' => null, 'hold' => [], 'outcome' => ['status' => 'dropped', 'note' => $why, 'rules' => $rules]];
        };
        $composeModel = (string) $this->settings->get('compose_model');
        $compose = $model->structured($this->call(
            $composeModel,
            $this->composeSystem(),
            [['role' => 'user', 'content' => $this->composeTurn($brief, $refs, $candidate, $context)]],
            self::COMPOSE_TOKENS,
            'high',
            schema: Prompts::articleSchema(),
        ));
        $this->app->dbAlive();
        if ($compose['data'] === null) {
            return $drop(match ($compose['stop']) {
                'refusal' => 'The model declined to write it.',
                'max_tokens' => 'The article ran past its length limit.',
                default => 'The answer was not the article asked for.',
            });
        }
        $data = $this->withFollowUps($compose['data'], $candidate);
        $result = $this->validator->check(Draft::fromArray($data, 'ai', $refs), $ctx);
        if (!$result->ok() && !$result->onlyRepairable()) {
            return $drop('Refused by the rules.', self::rules($result));
        }

        // One repair for the phrases to avoid and for what only a rewrite can fix: an article it leaves refused is
        // dropped, one that still uses a phrase is held.
        $fix = $result->ok() ? [] : $result->repairable();
        if ($result->tells !== [] || $fix !== []) {
            $ask = [];
            if ($result->tells !== []) {
                $ask[] = 'The phrases to avoid it uses: ' . implode(', ', $result->tells) . '.';
            }
            if ($fix !== []) {
                $ask[] = "What it must fix:\n- " . implode("\n- ", $fix);
            }
            $repair = $model->structured($this->call(
                $composeModel,
                Prompts::repairRules(),
                [['role' => 'user', 'content' => implode("\n\n", $ask) . "\n\nThe article:\n" . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]],
                self::COMPOSE_TOKENS,
                'medium',
                schema: Prompts::articleSchema(),
            ));
            $this->app->dbAlive();
            $again = $repair['data'] !== null ? $this->validator->check(Draft::fromArray($this->withFollowUps($repair['data'], $candidate), 'ai', $refs), $ctx) : null;
            if ($again !== null && $again->ok()) {
                $result = $again;
            }
            if (!$result->ok()) {
                return $drop('Refused by the rules, and its repair did not fix it.', self::rules($again ?? $result));
            }
            $this->step('repair', $t, $result->tells === [] ? 'Repaired.' : 'Still uses: ' . implode(', ', $result->tells) . '.');
        }
        $hold = $result->hold;

        // The check, failing closed: no answer holds the article.
        $d = $result->draft;
        $check = $model->structured($this->call(
            $this->app->config->string('news.check_model', 'claude-haiku-5-5'),
            Prompts::checkRules(),
            [['role' => 'user', 'content' => $this->checkTurn($d)]],
            self::CHECK_TOKENS,
            'low',
            schema: Prompts::checkSchema(),
        ));
        $this->app->dbAlive();
        $flags = $check['data'];
        if ($flags === null) {
            $hold[] = 'The automatic check did not answer, so a person should read it before it goes out.';
        } else {
            $banned = Prose::mentions(implode("\n", array_map('strval', (array) ($flags['banned_topics'] ?? []))), (array) $this->settings->get('banned_topics'));
            if ($banned !== []) {
                return $drop('The check found a banned topic.', [['rule' => 'banned_topic', 'detail' => 'The check found: ' . implode(', ', $banned) . '.']]);
            }
            $why = Prose::cut(trim((string) ($flags['reason'] ?? '')), 200);
            if (!empty($flags['names_private_individual'])) {
                $hold[] = 'It may name a private individual' . ($why !== '' ? ': ' . $why : '.');
            }
            if (!empty($flags['alleges_wrongdoing'])) {
                $hold[] = 'It may allege wrongdoing' . ($why !== '' ? ': ' . $why : '.');
            }
        }
        $this->step('compose', $t, Prose::cut($headline, 120) . ($hold !== [] ? ': held.' : ': ready.'), ['words' => Prose::words($d->body)]);
        return ['result' => $result, 'hold' => array_values(array_unique($hold)), 'outcome' => ['status' => 'ready', 'note' => implode(' ', $hold), 'rules' => []]];
    }

    /**
     * Stores the session's articles in one transaction: each checked again against the database (an article
     * written since, or earlier in this session, makes it a duplicate), then published 10 to 30 minutes after the
     * one before, or kept as a draft when held or when publishing automatically is off.
     *
     * @param array<int, array{result: Result, hold: list<string>, outcome: array<string, mixed>}> $ready
     * @param list<int> $seen
     * @return array{0: list<int>, 1: list<int>, 2: list<int>, 3: array<int, array<string, mixed>>}
     */
    private function publish(array $ready, Context $ctx, array $seen): array
    {
        $auto = $this->settings->get('auto_publish') === true;
        return $this->app->db()->transaction(function (Database $db) use ($ready, $ctx, $seen, $auto): array {
            $published = [];
            $drafts = [];
            $held = [];
            $outcomes = [];
            $at = null;
            foreach ($ready as $i => $item) {
                // Checked again as the desk wrote it: the follow-ups the duplicate rule added are found again (and
                // held below when not live), while one the desk declared must be live (Validator::checkFollowUps()).
                $draft = clone $item['result']->draft;
                $draft->followUps = array_values(array_diff($draft->followUps, $item['result']->autoFollowUps));
                $again = $this->validator->check($draft, $ctx->with(['recent' => null, 'seen' => array_merge($seen, $published, $drafts)]));
                if (!$again->ok()) {
                    $outcomes[$i] = ['status' => 'dropped', 'note' => 'Refused when checked again before publishing.', 'rules' => self::rules($again)];
                    continue;
                }
                $d = $again->draft;
                $d->origin = 'ai';
                $d->aiWritten = true;
                $d->byline = Draft::deskByline();
                // A follow-up goes out only after the article it follows: one that is not live holds it for a person.
                $hold = $item['hold'];
                foreach (array_diff($d->followUps, $this->liveOnly(array_map('intval', $d->followUps))) as $earlier) {
                    $hold[] = 'It follows up article ' . $earlier . ', which is not published.';
                }
                if ($hold !== [] || !$auto) {
                    $d->status = 'draft';
                    $d->publishAt = null;
                    $d->reviewReason = $hold !== [] ? Prose::cut(implode(' ', $hold), 300) : null;
                } else {
                    $at = $at === null ? time() : $at + random_int(10, 30) * 60;
                    $d->status = 'published';
                    $d->publishAt = $at;
                }
                $id = $this->store->create($d, null, $this->runId);
                if ($d->status === 'published') {
                    $published[] = $id;
                } else {
                    $drafts[] = $id;
                }
                if ($hold !== []) {
                    $held[] = $id;
                }
                $outcomes[$i] = ['status' => $d->status === 'published' ? 'published' : ($hold !== [] ? 'held' : 'draft'), 'article' => $id, 'at' => $d->publishAt, 'note' => (string) $d->reviewReason, 'rules' => []];
            }
            return [$published, $drafts, $held, $outcomes];
        });
    }

    /**
     * Ends the session: the run row gets its counts, usage, cost, summary, candidates and log.
     *
     * @param list<array<string, mixed>> $considered
     * @param list<int> $published
     * @param list<int> $drafts
     * @param list<int> $held
     * @return array<string, mixed>
     */
    private function end(string $status, string $summary, array $considered, array $published, array $drafts, array $held = []): array
    {
        $this->app->dbAlive();
        $fields = ['candidates' => count($considered), 'articles' => count($published) + count($drafts), 'held' => count($held), 'summary' => $summary, 'considered' => $considered, 'log' => $this->log] + $this->totals();
        if (!$this->runs->finish($this->runId, $status, $fields)) {
            $this->app->log->warning('news run was no longer running when it finished', ['run' => $this->runId, 'status' => $status]);
        }
        return $this->report($status, $summary, ['candidates' => $fields['candidates'], 'articles' => $fields['articles'], 'held' => $fields['held'], 'published' => $published, 'drafts' => $drafts, 'held_ids' => $held]);
    }

    /** @return array<string, mixed> */
    private function failed(\Throwable $e): array
    {
        $this->app->log->exception($e, 'news run ' . $this->runId);
        $reason = self::reason($e);
        $this->step('failed', microtime(true), $reason);
        $summary = Prose::cut('Failed: ' . $reason, 500);
        try {
            $this->app->dbAlive();
            $this->runs->finish($this->runId, 'failed', ['summary' => $summary, 'log' => $this->log] + $this->totals());
        } catch (\Throwable $again) {
            $this->app->log->exception($again, 'news run ' . $this->runId . ' finish');
        }
        return $this->report('failed', $summary);
    }

    /**
     * The admin emails after a run, each kind at most once a day: a failed run (a reaped one too), an article held for
     * review, and three sessions in a row that wrote nothing (failed ones included).
     *
     * @param array<string, mixed> $outcome
     */
    private function afterwards(array $outcome, int $runId): void
    {
        try {
            $run = '/portal/admin/news/desk/runs/' . $runId . '/';
            if ($outcome['status'] === 'failed' && $this->settings->claimEmail('failed')) {
                $this->email('A desk session failed', ['kind' => 'failed', 'summary' => (string) $outcome['summary'], 'run' => $run]);
            }
            $held = (array) ($outcome['held_ids'] ?? []);
            if ($held !== [] && $this->settings->claimEmail('held')) {
                $articles = [];
                foreach ($held as $id) {
                    $a = $this->store->find((int) $id);
                    if ($a !== null) {
                        $articles[] = ['title' => (string) $a['title'], 'reason' => (string) ($a['review_reason'] ?? ''), 'url' => '/portal/admin/news/' . (int) $id . '/'];
                    }
                }
                $this->email(count($articles) === 1 ? 'An article is waiting for review' : 'Articles are waiting for review', ['kind' => 'held', 'articles' => $articles, 'run' => $run]);
            }
            if (in_array($outcome['status'], ['done', 'skipped', 'failed'], true)) {
                $empty = $this->runs->consecutiveEmpty();
                if ($empty >= 3 && $this->settings->claimEmail('empty')) {
                    $this->email('The desk has written nothing for ' . $empty . ' sessions', ['kind' => 'empty', 'count' => $empty, 'summary' => (string) $outcome['summary'], 'run' => $run]);
                }
            }
        } catch (\Throwable $e) {
            $this->app->log->exception($e, 'news desk email');
        }
    }

    /** @param array<string, mixed> $data */
    private function email(string $subject, array $data): void
    {
        $this->app->auth()->notifyAdmins('Industry Updates: ' . lcfirst($subject), 'news-desk-admin', $data + ['desk' => '/portal/admin/news/desk/']);
    }

    // ---------------------------------------------------------------------------------------------- the money

    /**
     * A model call with the hooks that keep the session inside its deadline, ceiling and lease, and that record the
     * usage of every response as it arrives.
     *
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>>|null $tools
     * @param array<string, mixed>|null $schema
     * @return array<string, mixed>
     */
    private function call(string $model, string $system, array $messages, int $maxTokens, string $effort, ?array $tools = null, ?array $schema = null): array
    {
        $call = [
            'model' => $model,
            'system' => $system,
            'messages' => $messages,
            'maxTokens' => $maxTokens,
            'effort' => $effort,
            'before' => function (): void {
                $this->guard();
            },
            'after' => function (array $usage, string $served): void {
                $this->spend($usage, $served);
            },
        ];
        if ($tools !== null) {
            $call['tools'] = $tools;
        }
        if ($schema !== null) {
            $call['schema'] = $schema;
        }
        return $call;
    }

    /**
     * Before a model call: within the deadline and the ceiling, and still holding the lease. A session in a tick also
     * refreshes the heartbeat every HEARTBEAT_EVERY seconds, so a long one never reads as a stopped cron (a call lasts
     * at most news.timeout_seconds, well inside the 35 minutes the readers allow).
     */
    private function guard(): void
    {
        if (time() >= $this->deadline) {
            throw new \RuntimeException('The session reached its deadline before its next model call.');
        }
        $ceiling = (float) $this->app->config->get('news.session_ceiling_usd', 5);
        if ($this->costMicros >= (int) round($ceiling * 1000000)) {
            throw new \RuntimeException('The session reached its spending ceiling of ' . Pricing::usd($ceiling) . ' USD (estimated) before its next model call.');
        }
        $this->app->dbAlive();
        if (!$this->settings->lease($this->runId, $this->deadline)) {
            throw new \RuntimeException('The session lost the desk to another run.');
        }
        if ($this->ticking) {
            try {
                if ((int) ($this->settings->lastTick()['t'] ?? 0) <= time() - self::HEARTBEAT_EVERY) {
                    $this->settings->heartbeat(['run' => $this->runId, 'status' => 'running']);
                }
            } catch (\Throwable $e) {
                $this->app->log->exception($e, 'news heartbeat');
            }
        }
    }

    /**
     * After a response: its usage and estimated cost are added and written to the run at once. A write that fails
     * is logged and the totals kept, so the next write (or the finish) records them.
     *
     * @param array<string, int> $usage
     */
    private function spend(array $usage, string $model): void
    {
        foreach ($this->usage as $k => $v) {
            $this->usage[$k] = $v + max(0, (int) ($usage[$k] ?? 0));
        }
        $this->costMicros += Pricing::micros($usage, $model);
        $this->models[$model] = true;
        try {
            $this->app->dbAlive();
            $this->runs->record($this->runId, $this->totals());
        } catch (\Throwable $e) {
            $this->app->log->exception($e, 'news run ' . $this->runId . ' usage');
        }
    }

    /** @return array<string, mixed> the run's usage columns */
    private function totals(): array
    {
        return $this->usage + ['cost_micros' => $this->costMicros, 'models' => implode(', ', array_keys($this->models))];
    }

    // ------------------------------------------------------------------------------------------ the selection

    /**
     * The select step's candidates, cleaned: refs only those the research returned.
     *
     * @param array<string, mixed> $data
     * @param array<string, array<string, mixed>> $refs
     * @return list<array<string, mixed>>
     */
    private function candidates(array $data, array $refs): array
    {
        $line = static fn (mixed $v, int $max): string => Prose::cut(trim((string) preg_replace('/\s+/u', ' ', is_scalar($v) ? (string) $v : '')), $max);
        $ids = static fn (mixed $v): array => array_values(array_unique(array_filter(array_map('intval', is_array($v) ? $v : []), static fn (int $id): bool => $id > 0)));
        $out = [];
        foreach (is_array($data['considered'] ?? null) ? $data['considered'] : [] as $c) {
            if (!is_array($c)) {
                continue;
            }
            $decision = is_string($c['decision'] ?? null) ? strtolower($c['decision']) : '';
            $out[] = [
                'headline' => Prose::undash($line($c['headline'] ?? '', 190)),
                'story_key' => Prose::slug($line($c['story_key'] ?? '', 190), 190),
                'importance' => max(1, min(5, (int) ($c['importance'] ?? 1))),
                'newest_source_date' => $line($c['newest_source_date'] ?? '', 20),
                'decision' => in_array($decision, Prompts::DECISIONS, true) ? $decision : 'not_relevant',
                // The window: 14 days for legal or standards news, 7 for the rest (a missing answer gets 7).
                'category' => is_string($c['category'] ?? null) && Categories::has($c['category']) ? $c['category'] : '',
                'standards' => ($c['standards'] ?? false) === true,
                'duplicate_of' => $ids($c['duplicate_of'] ?? []),
                // Only live articles: a follow-up of one not published yet would link to a page nobody can open.
                'follow_up_of' => $this->liveOnly($ids($c['follow_up_of'] ?? [])),
                'refs' => array_values(array_unique(array_filter(array_map(static fn (mixed $r): string => strtoupper(trim(is_string($r) ? $r : '')), is_array($c['refs'] ?? null) ? $c['refs'] : []), static fn (string $r): bool => isset($refs[$r])))),
                'reason' => Prose::undash($line($c['reason'] ?? '', 300)),
            ];
        }
        return $out;
    }

    /**
     * The server's own selection: the model's write decisions at or above the importance bar, inside the window,
     * resting on references the research returned, and neither a duplicate of an article it was shown nor a
     * follow-up without a new source, then the N most important. Each candidate gets the server's decision and why.
     *
     * @param list<array<string, mixed>> $considered
     * @param array<string, array<string, mixed>> $refs
     * @param list<int> $seen
     * @return array{0: list<array<string, mixed>>, 1: list<int>} the candidates, and the indexes chosen
     */
    private function pick(array $considered, array $refs, array $seen, \DateTimeImmutable $now, int $n, int $min): array
    {
        $today = $now->setTimezone(Schedule::zone())->setTime(0, 0);
        $ctx = $this->settings->context('ai')->with(['seen' => $seen]);
        $keep = [];
        $keys = [];
        foreach ($considered as $i => $c) {
            $sources = [];
            foreach ($c['refs'] as $ref) {
                $key = Urls::key((string) $refs[$ref]['url']);
                if ($key !== null) {
                    $sources[] = $key;
                }
            }
            // Days from today to the newest source: a story older than its window, or dated after tomorrow, is out.
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $c['newest_source_date'], Schedule::zone());
            $days = $date !== false && $date->format('Y-m-d') === $c['newest_source_date'] ? (int) $today->diff($date)->format('%r%a') : null;
            $window = self::windowDays($c);
            [$server, $why] = match (true) {
                $c['decision'] !== 'write' => [$c['decision'], ''],
                $c['importance'] < $min => ['low_importance', 'Below the importance the desk needs (' . $min . ').'],
                $days === null || $days > 1 || $days < -$window => ['out_of_window', 'Its newest source is not dated within the last ' . $window . ' days.'],
                $sources === [] => ['no_sources', 'It names no reference the research returned.'],
                default => $this->duplicate($c, $sources, $ctx, $seen),
            };
            if ($server === 'write') {
                foreach ($keys as $j => [$storyKey, $theirs]) {
                    if (($c['story_key'] !== '' && $c['story_key'] === $storyKey) || array_intersect($sources, $theirs) !== []) {
                        [$server, $why] = ['duplicate', 'The same story as "' . Prose::cut((string) $considered[$j]['headline'], 80) . '" in this session.'];
                        break;
                    }
                }
            }
            $considered[$i]['server'] = $server;
            $considered[$i]['server_note'] = $why;
            if ($server === 'write') {
                $keep[] = $i;
                $keys[$i] = [$c['story_key'], $sources];
            }
        }
        usort($keep, static fn (int $a, int $b): int => [$considered[$b]['importance'], $a] <=> [$considered[$a]['importance'], $b]);
        $chosen = array_slice($keep, 0, $n);
        foreach (array_slice($keep, $n) as $i) {
            $considered[$i]['server'] = 'not_chosen';
            $considered[$i]['server_note'] = 'More important stories filled the session.';
        }
        sort($chosen);
        return [$considered, $chosen];
    }

    /**
     * The server's dedup of one candidate (Validator::duplicates()): a match it declares as a follow-up is allowed
     * if it brings a new source; a match against an article it was not shown, with a new source, will be kept as a
     * follow-up; any other match is a duplicate.
     *
     * @param array<string, mixed> $c
     * @param list<string> $sources
     * @param list<int> $seen
     * @return array{0: string, 1: string}
     */
    private function duplicate(array $c, array $sources, Context $ctx, array $seen): array
    {
        $declared = $c['follow_up_of'];
        foreach ($this->validator->duplicates((string) $c['headline'], '', [], (string) $c['story_key'], $sources, $ctx) as $id => $match) {
            if (in_array($id, $declared, true)) {
                continue;
            }
            if (in_array($id, $seen, true) || array_diff($sources, $match['source_keys']) === []) {
                return ['duplicate', 'Duplicates article ' . $id . ' (' . $match['why'] . ').'];
            }
        }
        if ($declared !== []) {
            $used = array_merge([], ...array_values($this->store->sourceKeys($declared)));
            if (array_diff($sources, $used) === []) {
                return ['duplicate', 'A follow-up that brings no source the earlier articles did not use.'];
            }
        }
        return ['write', ''];
    }

    /** @param list<array<string, mixed>> $considered */
    private static function slowNews(array $considered): string
    {
        if ($considered === []) {
            return 'Slow news: the research found no candidates.';
        }
        $labels = [
            'duplicate' => ['duplicate', 'duplicates'],
            'low_importance' => ['below the importance bar', 'below the importance bar'],
            'out_of_window' => ['too old', 'too old'],
            'banned' => ['on a banned topic', 'on banned topics'],
            'not_relevant' => ['not relevant', 'not relevant'],
            'no_sources' => ['without a usable source', 'without a usable source'],
        ];
        $counts = array_count_values(array_map(static fn (array $c): string => (string) ($c['server'] ?? $c['decision']), $considered));
        $parts = [];
        foreach ($labels as $key => [$one, $many]) {
            if (($counts[$key] ?? 0) > 0) {
                $parts[] = $counts[$key] . ' ' . ($counts[$key] === 1 ? $one : $many);
            }
        }
        $total = count($considered);
        return Prose::cut('Slow news: ' . $total . ' ' . ($total === 1 ? 'candidate' : 'candidates') . ' considered' . ($parts !== [] ? ', ' . implode(', ', $parts) : '') . '.', 500);
    }

    // ----------------------------------------------------------------------------------------- the prompts

    /** @param list<array<string, mixed>> $recent */
    private function context(array $recent, int $n, int $min): string
    {
        $lines = [
            'Articles already published, scheduled or in draft, the newest first, as id | date | story key | live or not published | title.'
                . ' Only a live article may be followed up or linked to; one not published yet still counts for duplicates:',
        ];
        foreach ($recent as $a) {
            $lines[] = (int) $a['id'] . ' | ' . date('Y-m-d', (int) $a['date']) . ' | ' . ((string) ($a['story_key'] ?? '') !== '' ? $a['story_key'] : '-')
                . ' | ' . ($this->store->isLive($a) ? 'live' : 'not published') . ' | ' . $a['title'];
        }
        if ($recent === []) {
            $lines[] = '(none yet)';
        }
        $lines[] = '';
        $lines[] = 'Categories, as key: name. What it covers:';
        foreach (Categories::all() as $key => $c) {
            $lines[] = $key . ': ' . $c['name'] . '. ' . $c['description'];
        }
        $lines[] = '';
        $lines[] = 'This session writes up to ' . $n . ' ' . ($n === 1 ? 'article' : 'articles') . '. A story needs an importance of ' . $min . ' or more, from 1 to 5.';
        $lines[] = '';
        $topics = (array) $this->settings->get('banned_topics');
        $lines[] = 'Banned topics (never research or write about them):';
        foreach ($topics !== [] ? $topics : ['(none)'] as $topic) {
            $lines[] = '- ' . $topic;
        }
        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array<string, string>>
     */
    private function researchTurn(array $row, \DateTimeImmutable $now, string $context, int $n): array
    {
        $local = $now->setTimezone(Schedule::zone());
        $lines = [
            'Today is ' . $local->format('l j F Y') . ', ' . $local->format('H:i') . ' in London. '
                . ($row['kind'] === 'manual' ? 'This session was started by hand.' : 'This is the ' . Schedule::label((string) $row['slot']) . ' session.'),
            '',
            $context,
        ];
        $watch = (array) $this->settings->get('watch_urls');
        if ($watch !== []) {
            $lines[] = '';
            $lines[] = 'Pages to check this session (read them with web fetch):';
            foreach ($watch as $url) {
                $lines[] = '- ' . $url;
            }
        }
        $lines[] = '';
        $lines[] = 'Find up to ' . ($n + 3) . ' candidate developments from the last ' . self::NEWS_DAYS . ' days (' . self::WINDOW_DAYS . ' days for legal, regulatory and standards news), then write the brief between ' . Prompts::BRIEF_START . ' and ' . Prompts::BRIEF_END . '. For each candidate give:';
        foreach ([
            'a headline and a story key;',
            'the ids of the articles above that it duplicates or follows up, if any;',
            'its importance from 1 to 5, with the reason;',
            'the date of its newest source;',
            'the verified facts and the speculation, each followed by the address it rests on;',
            'who it affects.',
        ] as $ask) {
            $lines[] = '- ' . $ask;
        }
        return [['role' => 'user', 'content' => implode("\n", $lines)]];
    }

    /** @param array<string, array<string, mixed>> $refs */
    private function selectTurn(string $brief, array $refs, string $context, int $n, int $min, \DateTimeImmutable $now): string
    {
        return "The research brief:\n\n" . $brief . "\n\n" . self::refList($refs) . "\n\n" . $context
            . "\n\nToday is " . $now->setTimezone(Schedule::zone())->format('l j F Y') . ' in London. The window: a story\'s newest source is at most '
            . self::NEWS_DAYS . ' days old, ' . self::WINDOW_DAYS . ' for legal, regulatory and standards news.'
            . "\n\nReturn every candidate in the brief. Decide write for at most " . $n . ', each with an importance of ' . $min . ' or more.';
    }

    private function composeSystem(): string
    {
        $phrases = (array) $this->settings->get('avoid_phrases');
        return Prompts::composeRules() . "\n\nThe voice\n\n" . $this->settings->voice()
            . ($phrases !== [] ? "\n\nPhrases to avoid in the standfirst, body, impact and verdict note:\n- " . implode("\n- ", $phrases) : '');
    }

    /**
     * The compose call's message as two blocks: first what every compose call of the session shares (the brief, the
     * references and the context), marked for the cache so the calls after the first read it (and the system prompt
     * before it) from there; then this story.
     *
     * @param array<string, array<string, mixed>> $refs
     * @param array<string, mixed> $candidate
     * @return list<array<string, mixed>>
     */
    private function composeTurn(string $brief, array $refs, array $candidate, string $context): array
    {
        $follows = [];
        foreach ($candidate['follow_up_of'] as $id) {
            $a = $this->store->find((int) $id);
            if ($a !== null && $this->store->isLive($a)) {
                $follows[] = $id . ' (' . Paths::article($a) . '): ' . $a['title'];
            }
        }
        return [
            ['type' => 'text', 'text' => "The research brief:\n\n" . $brief . "\n\n" . self::refList($refs) . "\n\n" . $context, 'cacheControl' => ['type' => 'ephemeral']],
            ['type' => 'text', 'text' => "Write the article for this story.\n"
                . 'Headline: ' . $candidate['headline'] . "\n"
                . 'Story key: ' . $candidate['story_key'] . "\n"
                . 'References: ' . implode(', ', $candidate['refs']) . "\n"
                . 'Follows up: ' . ($follows !== [] ? implode('; ', $follows) : 'none')],
        ];
    }

    /**
     * The check's message: the banned topics, then every part of the article a reader sees, between the article
     * markers (a marker inside the text is removed, so nothing in it can close them).
     */
    private function checkTurn(Draft $d): string
    {
        $topics = (array) $this->settings->get('banned_topics');
        $clean = static fn (string $text): string => trim(str_ireplace(['<article>', '</article>'], '', $text));
        $items = static fn (array $list): string => $list !== [] ? '- ' . implode("\n- ", array_map(static fn (array $i): string => $clean((string) $i['text']), $list)) : '(none)';
        $sources = array_map(static fn (array $s): string => $clean(trim($s['title'] . ' (' . $s['publisher'] . ')')), $d->sources);
        return "Banned topics:\n" . ($topics !== [] ? '- ' . implode("\n- ", $topics) : '(none)')
            . "\n\n<article>\nTitle: " . $clean($d->title) . "\n\nSEO title: " . $clean($d->seoTitle) . "\n\nStandfirst: " . $clean($d->dek)
            . "\n\nSummary: " . $clean($d->summary) . "\n\nBody:\n" . $clean($d->body) . "\n\nWhat it means:\n" . $clean($d->impact)
            . "\n\nWhat's confirmed:\n" . $items($d->confirmed) . "\n\nStill speculation:\n" . $items($d->speculation)
            . "\n\nVerdict: " . $d->verdict . '. ' . $clean($d->verdictNote)
            . "\n\nSources:\n" . ($sources !== [] ? '- ' . implode("\n- ", $sources) : '(none)') . "\n</article>";
    }

    /**
     * Every reference with the address it stands for, so a fact the brief rests on an address gets that address's
     * name; the models still answer with names only, and the server writes the addresses.
     *
     * @param array<string, array<string, mixed>> $refs
     */
    private static function refList(array $refs): string
    {
        $lines = ['The references, as [name] address | title | site | page age | how it was found | cited (refer to them by name only):'];
        foreach ($refs as $name => $r) {
            $how = ($r['via'] ?? '') === 'fetch' ? 'read' : 'search result';
            $lines[] = '[' . $name . '] ' . $r['url'] . ' | ' . ((string) ($r['title'] ?? '') !== '' ? $r['title'] : '(no title)') . ' | ' . (Urls::host((string) $r['url']) ?? '') . ' | '
                . ((string) ($r['page_age'] ?? '') !== '' ? $r['page_age'] : 'age unknown') . ' | ' . $how . ' | ' . (!empty($r['cited']) ? 'cited' : 'not cited');
        }
        if ($refs === []) {
            $lines[] = '(none)';
        }
        return implode("\n", $lines);
    }

    /** @return list<array<string, mixed>> the server tools, with what the session allows */
    private function tools(): array
    {
        $blocked = (array) $this->settings->get('banned_domains');
        $search = ['type' => 'web_search_20260209', 'name' => 'web_search', 'maxUses' => (int) $this->settings->get('max_searches'),
            'userLocation' => ['type' => 'approximate', 'country' => 'GB', 'timezone' => Schedule::ZONE]];
        $fetch = ['type' => 'web_fetch_20260209', 'name' => 'web_fetch', 'maxUses' => (int) $this->settings->get('max_fetches'),
            'maxContentTokens' => 8000, 'citations' => ['enabled' => false]];
        if ($blocked !== []) {
            $search['blockedDomains'] = $blocked;
            $fetch['blockedDomains'] = $blocked;
        }
        return $fetch['maxUses'] > 0 ? [$search, $fetch] : [$search];
    }

    // ------------------------------------------------------------------------------------------- the rest

    /**
     * The article's follow-ups: what the model wrote and what the candidate declared, live articles only.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function withFollowUps(array $data, array $candidate): array
    {
        $mine = array_map('intval', is_array($data['follow_up_of'] ?? null) ? $data['follow_up_of'] : []);
        $data['follow_up_of'] = $this->liveOnly(array_merge($mine, $candidate['follow_up_of']));
        return $data;
    }

    /**
     * The ids of live articles among $ids (published, and due by now), each once.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    private function liveOnly(array $ids): array
    {
        $out = [];
        foreach (array_unique($ids) as $id) {
            $a = $id > 0 ? $this->store->find($id) : null;
            if ($a !== null && $this->store->isLive($a)) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * How old, in days, a candidate's newest source may be: WINDOW_DAYS for legal or standards news, NEWS_DAYS for
     * every other story.
     *
     * @param array<string, mixed> $c
     */
    private static function windowDays(array $c): int
    {
        return ($c['category'] ?? '') === 'legal' || ($c['standards'] ?? false) === true ? self::WINDOW_DAYS : self::NEWS_DAYS;
    }

    /** @return list<array{rule: string, detail: string}> */
    private static function rules(Result $r): array
    {
        $out = $r->rejections;
        foreach ($r->errors as $field => $message) {
            $out[] = ['rule' => $field, 'detail' => $message];
        }
        return $out;
    }

    /**
     * Starts a claimed or queued run, or records why it is skipped.
     *
     * @return array<string, mixed>
     */
    private function startRun(int $id, ?NewsModel $model, \DateTimeImmutable $now): array
    {
        $row = $this->runs->find($id);
        $reason = self::skipReason($this->app, $this->settings, $this->runs, $model !== null);
        if ($reason !== null || $model === null) {
            $summary = self::SKIPS[$reason ?? 'sdk_missing'];
            $this->runs->skip($id, $summary);
            return ['run' => $id, 'slot' => $row['slot'] ?? null, 'status' => 'skipped', 'reason' => $reason ?? 'sdk_missing', 'summary' => $summary];
        }
        return ['slot' => $row['slot'] ?? null, 'reason' => null] + $this->run($id, $model, $now);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function report(string $status, string $summary, array $extra = []): array
    {
        return ['run' => $this->runId, 'status' => $status, 'summary' => $summary] + $extra + ['candidates' => 0, 'articles' => 0, 'held' => 0]
            + $this->usage + ['cost_usd' => round($this->costMicros / 1000000, 4)];
    }

    /** @return array<string, mixed> */
    private static function emptyTick(): array
    {
        return [
            't' => gmdate('c'), 'reaped' => 0, 'imported' => 0, 'import_failed' => 0, 'run' => null, 'slot' => null,
            'status' => 'idle', 'reason' => null, 'summary' => '', 'candidates' => 0, 'articles' => 0, 'held' => 0,
            'usage_in' => 0, 'usage_out' => 0, 'cache_write' => 0, 'cache_read' => 0, 'searches' => 0, 'fetches' => 0,
            'cost_usd' => 0.0, 'errors' => [],
        ];
    }

    /**
     * Writes the heartbeat; a failure is one of the tick's errors, never the end of the tick.
     *
     * @param array<string, mixed> $report
     * @param array<string, mixed> $tick
     */
    private function beat(array &$report, array $tick): void
    {
        try {
            $this->app->dbAlive();
            $this->settings->heartbeat($tick);
        } catch (\Throwable $e) {
            $this->tickFailed($report, 'heartbeat', $e);
        }
    }

    /** @param array<string, mixed> $report */
    private function tickFailed(array &$report, string $step, \Throwable $e): void
    {
        $this->app->log->exception($e, 'news tick ' . $step);
        $report['errors'][$step] = self::reason($e);
    }

    /** One step of the run's log: its name, how long it took, a note and its counts (never headers or a trace). */
    private function step(string $step, float $started, string $note = '', array $counts = []): void
    {
        if (count($this->log) >= self::MAX_STEPS) {
            return;
        }
        $this->log[] = ['t' => time(), 'step' => $step, 'ms' => (int) round((microtime(true) - $started) * 1000), 'note' => Prose::cut($note, 300), 'counts' => array_map('intval', $counts)];
    }

    /** A failure as one line for the run list: the message only, never the request, its headers or the trace. */
    private static function reason(\Throwable $e): string
    {
        $message = Prose::cut(trim((string) preg_replace('/\s+/u', ' ', $e->getMessage())), 240);
        if (str_starts_with($e::class, 'Anthropic\\')) {
            $short = substr((string) strrchr($e::class, '\\'), 1);
            return 'The Anthropic API call failed (' . $short . ')' . ($message !== '' ? ': ' . $message : '.');
        }
        return $message !== '' ? $message : 'An unexpected error (' . $e::class . ').';
    }

    private static function articles(int $n): string
    {
        return $n . ' ' . ($n === 1 ? 'article' : 'articles');
    }
}
