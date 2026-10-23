<?php

declare(strict_types=1);

namespace Psc\News;

use Psc\App;
use Psc\Db\Database;

/**
 * The AI desk's settings (docs/NEWS.md §8), held in news_settings as JSON with the defaults below; the prompt texts
 * the admin edits (the research brief and the voice), each change kept as a version; and the desk's internal rows,
 * which no admin page shows: the lease (one run at a time), the heartbeat (the last tick) and the email_* stamps
 * (one admin email of a kind a day).
 *
 * save() takes typed values or a form's strings (numbers as digits, lists as lines, ticks as "1"), checks every one
 * against its bounds and stores all of them or none.
 */
final class Settings
{
    /** Every setting and its default. */
    public const DEFAULTS = [
        'enabled' => true,
        'max_per_session' => 3,
        'min_importance' => 3,
        'slots' => [
            ['time' => '06:00', 'days' => [1, 2, 3, 4, 5, 6, 7]],
            ['time' => '14:30', 'days' => [1, 2, 3, 4, 5, 6, 7]],
        ],
        'date_overrides' => [],
        'auto_publish' => true,
        'research_model' => 'claude-sonnet-5-5',
        'compose_model' => 'claude-opus-5-5',
        'max_searches' => 12,
        'max_fetches' => 6,
        'monthly_budget_usd' => 100,
        'watch_urls' => [],
        'banned_domains' => [],
        'banned_topics' => [],
        'avoid_phrases' => [
            'delve', 'game-changer', 'game changer', "in today's fast-paced", 'ever-evolving', 'navigate the complexities',
            'seamlessly', 'unlock the potential', 'harness the power', 'in conclusion', "it's worth noting",
            'it is worth noting', 'a testament to', 'the landscape of', "let's dive in", 'buckle up', 'moreover',
            'furthermore', 'in the realm of', 'tapestry',
        ],
        'ai_note' => 'Researched and written by our AI desk from the sources listed below. Spotted something off? Tell us and we will put it right.',
        'comments' => 'open',
    ];

    /** The models the admin may choose for research and for writing. */
    public const MODELS = ['claude-opus-5-5' => 'Opus 5.5', 'claude-sonnet-5-5' => 'Sonnet 5.5'];

    public const COMMENT_MODES = [
        'open' => 'Open: comments appear at once',
        'approval' => 'Approval: new comments wait for an administrator',
        'closed' => 'Closed: no new comments',
    ];

    /** Whole-number settings: [least, most]. */
    public const BOUNDS = [
        'max_per_session' => [1, 5],
        'min_importance' => [1, 5],
        'max_searches' => [1, 30],
        'max_fetches' => [0, 20],
        'monthly_budget_usd' => [5, 2000],
    ];

    /** List settings: [most lines, longest line]. */
    public const LISTS = [
        'watch_urls' => [30, 2000],
        'banned_domains' => [200, 500],
        'banned_topics' => [100, 80],
        'avoid_phrases' => [200, 80],
    ];

    /** The last tick older than this means the cron has stopped, unless a session is still in its time (docs/NEWS.md §6.1). */
    public const TICK_STALE = 35 * 60;

    public const MAX_SLOTS = 6;
    public const AI_NOTE_MAX = 300;
    public const PROMPT_MAX = 20000;
    public const PROMPT_FIELDS = ['brief', 'voice'];

    /** @var array<string, mixed>|null stored values by name, read once */
    private ?array $stored = null;

    public function __construct(private readonly App $app)
    {
    }

    /** The desk's Anthropic key: news.api_key, else the assistant's chat.api_key; '' when neither is set. */
    public static function apiKey(App $app): string
    {
        $key = $app->config->string('news.api_key');
        return $key !== '' ? $key : $app->config->string('chat.api_key');
    }

    /** One setting's value, its default until an admin changes it. Throws for a name that is not a setting. */
    public function get(string $name): mixed
    {
        if (!array_key_exists($name, self::DEFAULTS)) {
            throw new \InvalidArgumentException('Unknown news setting: ' . $name);
        }
        $stored = $this->stored()[$name] ?? null;
        return $stored !== null && self::sameType($stored, self::DEFAULTS[$name]) ? $stored : self::DEFAULTS[$name];
    }

    /** @return array<string, mixed> every setting by name */
    public function all(): array
    {
        $out = [];
        foreach (array_keys(self::DEFAULTS) as $name) {
            $out[$name] = $this->get($name);
        }
        return $out;
    }

    /**
     * Checks and stores settings. Names that are not settings are ignored. With any error nothing is stored.
     *
     * @param array<string, mixed> $values
     * @return array{changed: list<string>, errors: array<string, string>}
     */
    public function save(array $values, ?int $userId): array
    {
        $clean = [];
        $errors = [];
        foreach ($values as $name => $value) {
            if (!is_string($name) || !array_key_exists($name, self::DEFAULTS)) {
                continue;
            }
            [$ok, $result] = $this->parse($name, $value);
            if ($ok) {
                $clean[$name] = $result;
            } else {
                $errors[$name] = (string) $result;
            }
        }
        if ($errors !== []) {
            return ['changed' => [], 'errors' => $errors];
        }
        $changed = [];
        foreach ($clean as $name => $value) {
            if (self::encode($value) !== self::encode($this->get($name))) {
                $changed[] = $name;
            }
        }
        if ($changed === []) {
            return ['changed' => [], 'errors' => []];
        }
        $this->db()->transaction(function (Database $db) use ($changed, $clean, $userId): void {
            foreach ($changed as $name) {
                $this->put($db, $name, self::encode($clean[$name]), $userId, time());
            }
        });
        $this->stored = null;
        return ['changed' => $changed, 'errors' => []];
    }

    /**
     * A short fingerprint of each named setting's current value. A form posts the ones it was drawn with, so a save
     * from a page opened before someone else's save can be refused instead of putting their values back.
     *
     * @param list<string> $names
     * @return array<string, string>
     */
    public function fingerprints(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $out[$name] = substr(hash('sha256', self::encode($this->get($name))), 0, 16);
        }
        return $out;
    }

    /**
     * The validation context with these settings: the banned sites and topics, the phrases to avoid and the
     * site's address.
     */
    public function context(string $origin, string $mode = 'publish', ?int $editingId = null): Context
    {
        return new Context(
            origin: $origin,
            mode: $mode,
            bannedDomains: $this->get('banned_domains'),
            bannedTopics: $this->get('banned_topics'),
            avoidPhrases: $this->get('avoid_phrases'),
            editingId: $editingId,
            baseUrl: $this->app->baseUrl(),
        );
    }

    // -------------------------------------------------------------------------------------------- the prompts

    /** The research brief the desk uses: the newest saved version, else content/news/brief.md. */
    public function brief(): string
    {
        return $this->prompt('brief');
    }

    /** The voice the desk writes in: the newest saved version, else content/news/voice.md. */
    public function voice(): string
    {
        return $this->prompt('voice');
    }

    /** The default text of brief or voice (content/news/<field>.md). */
    public function defaultPrompt(string $field): string
    {
        self::promptField($field);
        $file = $this->app->root . '/content/news/' . $field . '.md';
        return is_file($file) ? trim((string) file_get_contents($file)) : '';
    }

    /**
     * Saves a new version of brief or voice. The text is kept as typed apart from line ends, invisible characters
     * and dashes (Prose::undash()); the same text as now is no change.
     *
     * @return array{changed: bool, error: ?string}
     */
    public function savePrompt(string $field, string $text, ?int $userId): array
    {
        self::promptField($field);
        if (!mb_check_encoding($text, 'UTF-8')) {
            return ['changed' => false, 'error' => 'The text could not be read. Paste it again as plain text.'];
        }
        $text = trim(Prose::undash((string) preg_replace(\Psc\Support\Names::INVISIBLE, '', str_replace(["\r\n", "\r"], "\n", $text))));
        if ($text === '' || mb_strlen($text) > self::PROMPT_MAX) {
            $label = $field === 'brief' ? 'the research brief' : 'the voice';
            return ['changed' => false, 'error' => 'Write ' . $label . ' in up to 20,000 characters, or reset it to the default.'];
        }
        if ($text === $this->prompt($field)) {
            return ['changed' => false, 'error' => null];
        }
        $this->db()->insert('news_prompt_versions', ['field' => $field, 'body' => $text, 'created_by' => $userId, 'created_at' => time()]);
        return ['changed' => true, 'error' => null];
    }

    /** Makes the default text current again, as a new version so the history keeps what it replaced. */
    public function resetPrompt(string $field, ?int $userId): bool
    {
        return $this->savePrompt($field, $this->defaultPrompt($field), $userId)['changed'];
    }

    /**
     * Makes an earlier version current again, as a new version.
     *
     * @return array{changed: bool, error: ?string}
     */
    public function restorePrompt(int $versionId, ?int $userId): array
    {
        $row = $this->db()->one('SELECT field, body FROM news_prompt_versions WHERE id = ?', [$versionId]);
        if ($row === null || !in_array($row['field'], self::PROMPT_FIELDS, true)) {
            return ['changed' => false, 'error' => 'That version is no longer available.'];
        }
        return $this->savePrompt((string) $row['field'], (string) $row['body'], $userId);
    }

    /**
     * The saved versions of brief or voice, the newest (the current one) first.
     *
     * @return list<array<string, mixed>> news_prompt_versions rows: id, field, body, created_by, created_at
     */
    public function versions(string $field, int $limit = 50): array
    {
        self::promptField($field);
        return $this->db()->all('SELECT id, field, body, created_by, created_at FROM news_prompt_versions WHERE field = ? ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit), [$field]);
    }

    // -------------------------------------------------------------------------------------- the internal rows

    /** Records a tick (the desk page and /health read it); 't' is the time it was recorded. */
    public function heartbeat(array $tick): void
    {
        $now = time();
        $this->db()->transaction(fn (Database $db) => $this->put($db, 'heartbeat', self::encode(['t' => $now] + $tick), null, $now));
    }

    /** @return array<string, mixed>|null the last tick recorded, with 't', or null before the first */
    public function lastTick(): ?array
    {
        $value = $this->db()->value("SELECT value FROM news_settings WHERE name = 'heartbeat'");
        $tick = is_string($value) ? json_decode($value, true) : null;
        return is_array($tick) ? $tick : null;
    }

    /**
     * Whether the cron is ticking: 'never' before the first tick; 'ok' while the last tick is under TICK_STALE old,
     * or while a session is running and not yet past the time the reaper would fail it (its deadline plus
     * Runs::REAP_AFTER), since the tick that runs it may record nothing until it ends; 'stopped' otherwise.
     *
     * @param array<string, mixed>|null $running the running run (Runs::running())
     */
    public function cronState(?array $running, ?int $now = null): string
    {
        $now ??= time();
        $tick = $this->lastTick();
        if ($tick === null) {
            return 'never';
        }
        if ((int) ($tick['t'] ?? 0) >= $now - self::TICK_STALE) {
            return 'ok';
        }
        return $running !== null && (int) ($running['deadline_at'] ?? 0) + Runs::REAP_AFTER >= $now ? 'ok' : 'stopped';
    }

    /**
     * Takes or renews the lease for a run until $until: true when it was free, had expired, or was already this
     * run's. One conditional UPDATE decides, so two ticks can never both hold it. The row keeps its holder in
     * updated_by and its expiry in updated_at.
     */
    public function lease(int $runId, int $until): bool
    {
        $now = time();
        if ($runId <= 0 || $until <= $now) {
            return false;
        }
        $db = $this->db();
        $this->ensureRow($db, 'lease');
        return $db->exec(
            "UPDATE news_settings SET value = :value, updated_by = :run, updated_at = :until WHERE name = 'lease'"
            . ' AND (updated_by IS NULL OR updated_at <= :now OR updated_by = :holder)',
            ['value' => self::encode(['run' => $runId, 'until' => $until, 'nonce' => bin2hex(random_bytes(4))]), 'run' => $runId, 'until' => $until, 'now' => $now, 'holder' => $runId]
        ) === 1;
    }

    /** Gives the lease up, if this run holds it. */
    public function releaseLease(int $runId): void
    {
        $this->db()->exec("UPDATE news_settings SET value = 'null', updated_by = NULL, updated_at = ? WHERE name = 'lease' AND updated_by = ?", [time(), $runId]);
    }

    /** @return array{run: int, until: int}|null the run holding the lease and until when, or null when it is free */
    public function leaseHolder(): ?array
    {
        $row = $this->db()->one("SELECT updated_by, updated_at FROM news_settings WHERE name = 'lease'");
        if ($row === null || $row['updated_by'] === null || (int) $row['updated_at'] <= time()) {
            return null;
        }
        return ['run' => (int) $row['updated_by'], 'until' => (int) $row['updated_at']];
    }

    /**
     * Whether an admin email of this kind (failed, empty, held ...) may go now: true at most once per $every
     * seconds, and only to the caller that claimed it.
     */
    public function claimEmail(string $kind, int $every = 86400): bool
    {
        if (!preg_match('/^[a-z0-9_]{1,40}$/', $kind)) {
            throw new \InvalidArgumentException('Bad email kind: ' . $kind);
        }
        $now = time();
        $db = $this->db();
        $name = 'email_' . $kind;
        if ($this->ensureRow($db, $name, $now)) {
            return true;
        }
        return $db->exec('UPDATE news_settings SET updated_at = ?, value = ? WHERE name = ? AND updated_at <= ?', [$now, self::encode($now), $name, $now - $every]) === 1;
    }

    /**
     * The site's IndexNow key (docs/NEWS.md §5): 32 lower-case hex characters, which both readings of the protocol's
     * key rules allow, made the first time the announcer needs it and kept from then on, so the key file at
     * /{key}.txt always answers for the pings already sent. Null when none has been made and $create is false (the
     * key file's route never makes one).
     */
    public function indexNowKey(bool $create = true): ?string
    {
        $value = $this->internal(self::INDEXNOW_KEY);
        if (is_string($value) && preg_match('/^[a-f0-9]{32}$/', $value) === 1) {
            return $value;
        }
        if (!$create) {
            return null;
        }
        $db = $this->db();
        $key = bin2hex(random_bytes(16));
        try {
            if ($db->value('SELECT 1 FROM news_settings WHERE name = ?', [self::INDEXNOW_KEY]) === null) {
                $db->insert('news_settings', ['name' => self::INDEXNOW_KEY, 'value' => self::encode($key), 'updated_by' => null, 'updated_at' => time()]);
                return $key;
            }
            // A row that holds no usable key (never written by this code) is given one.
            $db->update('news_settings', ['value' => self::encode($key), 'updated_at' => time()], ['name' => self::INDEXNOW_KEY]);
            return $key;
        } catch (\PDOException $e) {
            if (!ArticleStore::isDuplicate($e)) {
                throw $e;
            }
        }
        // Another process made it first: its key is the one.
        $value = $this->internal(self::INDEXNOW_KEY);
        return is_string($value) ? $value : null;
    }

    /** The row that holds the IndexNow key. */
    public const INDEXNOW_KEY = 'indexnow_key';

    /**
     * An internal row's value, decoded (the announcer's key and each channel's state), or null before it is first
     * written. Never a setting: get() refuses every name that is not in DEFAULTS, and these names never are.
     */
    public function internal(string $name): mixed
    {
        self::internalName($name);
        $value = $this->db()->value('SELECT value FROM news_settings WHERE name = ?', [$name]);
        return is_string($value) ? json_decode($value, true) : null;
    }

    /** Writes an internal row (see internal()). */
    public function putInternal(string $name, mixed $value): void
    {
        self::internalName($name);
        $this->db()->transaction(fn (Database $db) => $this->put($db, $name, self::encode($value), null, time()));
    }

    // ------------------------------------------------------------------------------------------- internals

    private static function internalName(string $name): void
    {
        if (preg_match('/^[a-z0-9_]{1,64}$/', $name) !== 1 || array_key_exists($name, self::DEFAULTS)) {
            throw new \InvalidArgumentException('Not an internal row: ' . $name);
        }
    }

    private function db(): Database
    {
        return $this->app->db();
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        if ($this->stored === null) {
            $this->stored = [];
            foreach ($this->db()->all('SELECT name, value FROM news_settings') as $row) {
                if (array_key_exists((string) $row['name'], self::DEFAULTS) && is_string($row['value'])) {
                    $this->stored[(string) $row['name']] = json_decode($row['value'], true);
                }
            }
        }
        return $this->stored;
    }

    private function prompt(string $field): string
    {
        self::promptField($field);
        $body = $this->db()->value('SELECT body FROM news_prompt_versions WHERE field = ? ORDER BY created_at DESC, id DESC LIMIT 1', [$field]);
        return is_string($body) && $body !== '' ? $body : $this->defaultPrompt($field);
    }

    private static function promptField(string $field): void
    {
        if (!in_array($field, self::PROMPT_FIELDS, true)) {
            throw new \InvalidArgumentException('Unknown prompt: ' . $field);
        }
    }

    private function put(Database $db, string $name, string $value, ?int $userId, int $at): void
    {
        if ($db->value('SELECT 1 FROM news_settings WHERE name = ?', [$name]) === null) {
            $db->insert('news_settings', ['name' => $name, 'value' => $value, 'updated_by' => $userId, 'updated_at' => $at]);
        } else {
            $db->update('news_settings', ['value' => $value, 'updated_by' => $userId, 'updated_at' => $at], ['name' => $name]);
        }
    }

    /** Inserts an internal row if it is missing; true when this call inserted it. */
    private function ensureRow(Database $db, string $name, int $at = 0): bool
    {
        if ($db->value('SELECT 1 FROM news_settings WHERE name = ?', [$name]) !== null) {
            return false;
        }
        try {
            $db->insert('news_settings', ['name' => $name, 'value' => $at > 0 ? self::encode($at) : 'null', 'updated_by' => null, 'updated_at' => $at]);
            return true;
        } catch (\PDOException $e) {
            if (!ArticleStore::isDuplicate($e)) {
                throw $e;
            }
            return false;
        }
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function sameType(mixed $value, mixed $default): bool
    {
        return gettype($value) === gettype($default) || (is_int($default) && is_float($value));
    }

    /**
     * One setting from what was posted: [true, the value to store] or [false, the error in an admin's words].
     *
     * @return array{0: bool, 1: mixed}
     */
    private function parse(string $name, mixed $value): array
    {
        if (is_bool(self::DEFAULTS[$name])) {
            $flag = is_bool($value) ? $value : filter_var(is_scalar($value) ? (string) $value : '', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            return $flag !== null ? [true, $flag] : [false, 'Choose yes or no.'];
        }
        if (isset(self::BOUNDS[$name])) {
            [$min, $max] = self::BOUNDS[$name];
            $n = is_int($value) ? $value : (is_string($value) && preg_match('/^\s*\d+\s*$/', $value) ? (int) $value : null);
            if ($n !== null && $n >= $min && $n <= $max) {
                return [true, $n];
            }
            return [false, match ($name) {
                'max_per_session' => 'Enter a number of articles per session from 1 to 5.',
                'min_importance' => 'Choose how newsworthy a story must be, from 1 to 5.',
                'max_searches' => 'Enter a number of web searches per session from 1 to 30.',
                'max_fetches' => 'Enter a number of pages read per session from 0 to 20.',
                default => 'Enter a monthly cap from 5 to 2000 US dollars, in whole dollars.',
            }];
        }
        if (isset(self::LISTS[$name])) {
            return $this->parseList($name, $value);
        }
        return match ($name) {
            'research_model', 'compose_model' => is_string($value) && isset(self::MODELS[$value]) ? [true, $value] : [false, 'Choose Opus 5.5 or Sonnet 5.5.'],
            'comments' => is_string($value) && isset(self::COMMENT_MODES[$value]) ? [true, $value] : [false, 'Choose open, approval or closed.'],
            'ai_note' => self::parseNote($value),
            'slots' => self::parseSlots($value),
            'date_overrides' => self::parseOverrides($value),
            default => [false, 'That setting cannot be changed here.'],
        };
    }

    /** @return array{0: bool, 1: mixed} */
    private function parseList(string $name, mixed $value): array
    {
        [$most, $longest] = self::LISTS[$name];
        $lines = is_array($value) ? $value : explode("\n", str_replace("\r", "\n", is_scalar($value) ? (string) $value : ''));
        $out = [];
        $seen = [];
        $n = 0;
        foreach ($lines as $line) {
            $n++;
            $line = is_scalar($line) ? \Psc\Support\Names::visible(trim(str_replace("\t", ' ', (string) $line))) : '';
            if ($line === '') {
                continue;
            }
            if (!mb_check_encoding($line, 'UTF-8') || mb_strlen($line) > $longest) {
                return [false, match ($name) {
                    'watch_urls' => 'Line ' . $n . ' is too long for a web address.',
                    'banned_domains' => 'Line ' . $n . ' is too long for a website name.',
                    default => 'Keep each line to ' . $longest . ' characters (line ' . $n . ').',
                }];
            }
            if ($name === 'watch_urls') {
                if (Urls::canonical($line) === null) {
                    return [false, 'Line ' . $n . ' is not a web address. Enter full addresses starting https://, one per line.'];
                }
                $key = (string) Urls::key($line);
            } elseif ($name === 'banned_domains') {
                $line = (string) Urls::normaliseDomain($line);
                if ($line === '') {
                    return [false, 'Line ' . $n . ' is not a website name. Enter names such as example.com, one per line.'];
                }
                $key = $line;
            } else {
                $line = Prose::undash($line);
                $key = mb_strtolower($line);
            }
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $line;
            }
        }
        if (count($out) > $most) {
            return [false, 'Keep the list to ' . $most . ' lines.'];
        }
        return [true, $out];
    }

    /** @return array{0: bool, 1: mixed} */
    private static function parseNote(mixed $value): array
    {
        $note = is_string($value) && mb_check_encoding($value, 'UTF-8') ? \Psc\Support\Names::visible(trim((string) preg_replace('/\s+/u', ' ', $value))) : '';
        $note = Prose::undash($note);
        if ($note === '' || mb_strlen($note) > self::AI_NOTE_MAX) {
            return [false, 'Enter the note shown on AI-written articles, up to 300 characters.'];
        }
        return [true, $note];
    }

    /**
     * Sessions from the form's rows: each a time (HH:MM, London) and the weekdays it runs (1 Monday to 7 Sunday);
     * a blank row or one ticked Remove is left out. At most MAX_SLOTS, one per time, sorted by time.
     *
     * @return array{0: bool, 1: mixed}
     */
    private static function parseSlots(mixed $value): array
    {
        if (!is_array($value)) {
            return [false, 'Enter each session as a time and the days it runs.'];
        }
        $slots = [];
        foreach ($value as $row) {
            if (!is_array($row) || !empty($row['remove'])) {
                continue;
            }
            $time = is_string($row['time'] ?? null) ? trim($row['time']) : '';
            if ($time === '') {
                continue;
            }
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                return [false, 'Enter each session time as HH:MM, for example 06:00.'];
            }
            $days = [];
            foreach (is_array($row['days'] ?? null) ? $row['days'] : [] as $day) {
                if ((is_int($day) || (is_string($day) && ctype_digit($day))) && (int) $day >= 1 && (int) $day <= 7) {
                    $days[(int) $day] = (int) $day;
                }
            }
            if ($days === []) {
                return [false, 'Tick at least one day for the session at ' . $time . ', or tick Remove.'];
            }
            if (isset($slots[$time])) {
                return [false, 'Two sessions are at ' . $time . '. Give each session its own time.'];
            }
            ksort($days);
            $slots[$time] = ['time' => $time, 'days' => array_values($days)];
        }
        if (count($slots) > self::MAX_SLOTS) {
            return [false, 'Keep to 6 sessions.'];
        }
        ksort($slots);
        return [true, array_values($slots)];
    }

    /**
     * Calendar overrides: date (YYYY-MM-DD) => off or on. Dates before today in London are dropped.
     *
     * @return array{0: bool, 1: mixed}
     */
    private static function parseOverrides(mixed $value): array
    {
        if (!is_array($value)) {
            return [false, 'Enter calendar dates as YYYY-MM-DD.'];
        }
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('Y-m-d');
        $out = [];
        foreach ($value as $date => $state) {
            $date = (string) $date;
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Europe/London'));
            if ($d === false || $d->format('Y-m-d') !== $date) {
                return [false, 'Enter calendar dates as YYYY-MM-DD.'];
            }
            if (!in_array($state, ['on', 'off'], true)) {
                return [false, 'Mark each changed day as on or off.'];
            }
            if ($date >= $today) {
                $out[$date] = $state;
            }
        }
        if (count($out) > 400) {
            return [false, 'Keep the calendar to 400 changed days.'];
        }
        ksort($out);
        return [true, $out];
    }
}
