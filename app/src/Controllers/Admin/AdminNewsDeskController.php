<?php

declare(strict_types=1);

namespace Psc\Controllers\Admin;

use Psc\App;
use Psc\Controllers\Controller;
use Psc\Http\Request;
use Psc\Http\Response;
use Psc\News\AnthropicNewsModel;
use Psc\News\Pipeline;
use Psc\News\Pricing;
use Psc\News\Prompts;
use Psc\News\Runs;
use Psc\News\Schedule;
use Psc\News\Settings;
use Psc\Support\Audit;

/**
 * The Industry Updates AI desk, for administrators (docs/NEWS.md §8, §11 Desk): its state, last tick, next sessions,
 * spend and runs; pausing, continuing and running a session now; the settings; the calendar; the prompts; and one
 * run's record. Every change goes through Psc\News\Settings (all or nothing) and is audited as desk.*.
 *
 * A form refused for its fields comes back with everything that was typed: the session keeps a full copy of the
 * settings and prompt forms (keepOld() drops lists and cuts long text, and these forms have both).
 */
final class AdminNewsDeskController extends Controller
{
    public const BASE = '/portal/admin/news/desk/';

    private const CONTROLS = ['pause' => 'pause', 'resume' => 'resume', 'run_now' => 'run_now'];
    private const PROMPT_ACTIONS = ['save_brief' => 'brief', 'save_voice' => 'voice', 'reset_brief' => 'brief', 'reset_voice' => 'voice', 'restore' => ''];
    private const PROMPT_NAMES = ['brief' => 'the research brief', 'voice' => 'the voice'];

    /** How many months the calendar shows at once, and how far ahead it goes. */
    public const CALENDAR_MONTHS = 3;
    public const CALENDAR_AHEAD = 12;

    /** The session keys holding a refused form's full input. */
    private const KEPT_SETTINGS = 'news_desk_settings';
    private const KEPT_PROMPT = 'news_desk_prompt';

    /** Every setting the settings form posts (so the ones its fingerprints cover), by the name the form gives it. */
    private const SETTING_NAMES = [
        'max_per_session' => 'Articles per session',
        'min_importance' => 'How newsworthy a story must be',
        'research_model' => 'Model for research',
        'compose_model' => 'Model for choosing and writing',
        'max_searches' => 'Web searches per session',
        'max_fetches' => 'Pages read per session',
        'monthly_budget_usd' => 'Monthly spending cap',
        'watch_urls' => 'Pages to check every session',
        'banned_domains' => 'Banned websites',
        'banned_topics' => 'Banned topics',
        'avoid_phrases' => 'Phrases to avoid',
        'ai_note' => 'The note on AI-written articles',
        'comments' => 'Comments on articles',
        'auto_publish' => 'Publish articles automatically',
        'slots' => 'Sessions',
    ];

    /** The review note on a scheduled AI article that pausing the desk turned back into a draft. */
    public const HELD_ON_PAUSE = 'Held when the desk was paused';

    /**
     * The desk's state as its pages and the admin overview say it: on, or why no session can start. A session
     * starts only at a tick, so the desk is on only while the cron is ticking (Settings::cronState()).
     *
     * @return array{key: string, label: string, badge: string, text: string}
     */
    public static function state(App $app): array
    {
        $settings = new Settings($app);
        $runs = new Runs($app);
        $reason = Pipeline::skipReason($app, $settings, $runs, AnthropicNewsModel::available())
            ?? ['never' => 'no_tick', 'stopped' => 'stopped', 'ok' => null][$settings->cronState($runs->running())];
        return match ($reason) {
            null => ['key' => 'on', 'label' => 'On', 'badge' => 'active', 'text' => 'Sessions run on the schedule below.'],
            'paused' => ['key' => 'paused', 'label' => 'Paused', 'badge' => 'pending', 'text' => 'No sessions run until you continue the desk. A session already running finishes.'],
            'no_key' => ['key' => 'no_key', 'label' => 'Stopped: no key', 'badge' => 'declined', 'text' => 'No Anthropic key is set. Add news.api_key (or chat.api_key) to the server\'s config file, as docs/DEPLOY.md describes.'],
            'sdk_missing' => ['key' => 'sdk_missing', 'label' => 'Stopped: SDK missing', 'badge' => 'declined', 'text' => 'The Anthropic SDK is not installed on the server. Deploy a full build, which includes the vendor folder.'],
            'no_tick' => ['key' => 'no_tick', 'label' => 'Stopped: no tick yet', 'badge' => 'declined', 'text' => 'The cron job has not ticked yet, and a session starts only at a tick. Add the news cron job in hPanel, as docs/DEPLOY.md describes.'],
            'stopped' => ['key' => 'stopped', 'label' => 'Stopped: cron not ticking', 'badge' => 'declined', 'text' => 'The cron job has not ticked for more than 35 minutes, and a session starts only at a tick. Check the news cron job in hPanel.'],
            default => ['key' => 'budget', 'label' => 'Stopped: cap reached', 'badge' => 'declined', 'text' => 'Another session could take this month past its spending cap. Raise the cap in the settings, or sessions start again next month.'],
        };
    }

    // ------------------------------------------------------------------------------------------- the main page

    public function index(Request $request): Response
    {
        $settings = new Settings($this->app);
        $runs = new Runs($this->app);
        $running = $runs->running();
        $state = self::state($this->app);
        return $this->portal('admin/news/desk/index', [
            'state' => $state,
            'enabled' => $settings->get('enabled') === true,
            'tick' => $settings->lastTick(),
            // A session still in its time keeps the cron healthy, however long ago its tick last wrote the heartbeat.
            'tickStale' => $state['key'] !== 'no_key' && $settings->cronState($running) !== 'ok',
            'scheduled' => self::scheduledAi($this->app),
            'upcoming' => Schedule::upcoming(new \DateTimeImmutable('now'), $settings, 6),
            'spent' => $runs->spentThisMonth(),
            'cap' => (int) $settings->get('monthly_budget_usd'),
            'typical' => $runs->p90Cost(),
            'running' => $running,
            'queued' => $runs->queued(),
            'runs' => $runs->recent(20),
            'perWeek' => Schedule::perWeek($settings),
            'hasOverrides' => $settings->get('date_overrides') !== [],
        ], 'The AI desk');
    }

    public function control(Request $request): Response
    {
        $admin = $this->currentUser();
        $action = AdminController::pick($request->input('action'), self::CONTROLS);
        $settings = new Settings($this->app);
        $paused = $settings->get('enabled') !== true;
        switch ($action) {
            case 'pause':
            case 'resume':
                if ($paused === ($action === 'pause')) {
                    return $this->redirect(self::BASE, 'info', $paused ? 'The desk is already paused.' : 'The desk is already running.');
                }
                $settings->save(['enabled' => $action === 'resume'], (int) $admin['id']);
                if ($action === 'resume') {
                    Audit::log($this->app, (int) $admin['id'], 'desk.resumed');
                    return $this->redirect(self::BASE, 'success', 'The desk is running again. Its next session starts at the time shown below.');
                }
                $hold = $request->input('hold') === '1';
                $held = $hold ? $this->holdScheduled((int) $admin['id']) : [];
                Audit::log($this->app, (int) $admin['id'], 'desk.paused', null, $hold ? ['held' => count($held)] : []);
                $left = self::scheduledAi($this->app);
                return $this->redirect(self::BASE, 'success', 'The desk is paused. No session starts until you continue it; one already running finishes.'
                    . ($held !== [] ? ' ' . self::articles(count($held)) . ' it had scheduled ' . (count($held) === 1 ? 'is a draft' : 'are drafts') . ' again, under Held for review.' : '')
                    . ($left > 0 ? ' ' . self::articles($left) . ' it scheduled still ' . ($left === 1 ? 'goes' : 'go') . ' live at ' . ($left === 1 ? 'its time' : 'their times') . '; unpublish any you want to stop from the Scheduled tab.' : ''));
            case 'run_now':
                $state = self::state($this->app);
                if ($state['key'] !== 'on') {
                    return $this->redirect(self::BASE, 'danger', $state['key'] === 'paused'
                        ? 'The desk is paused. Continue the desk first, then run a session.'
                        : 'A session cannot start: ' . lcfirst($state['text']));
                }
                $id = (new Runs($this->app))->queueManual((int) $admin['id']);
                if ($id === null) {
                    return $this->redirect(self::BASE, 'info', 'A session is already queued or running, so no other was added. Its progress shows below.');
                }
                Audit::log($this->app, (int) $admin['id'], 'desk.run_queued', (string) $id);
                return $this->redirect(self::BASE, 'success', 'A session is queued. It starts within 15 minutes, at the next tick of the cron job.');
            default:
                return $this->redirect(self::BASE, 'danger', 'That action is not available. Use one of the buttons on this page.');
        }
    }

    // --------------------------------------------------------------------------------------------- settings

    public function settings(Request $request): Response
    {
        $settings = new Settings($this->app);
        $session = $this->app->session();
        $kept = $session->get(self::KEPT_SETTINGS);
        $session->forget(self::KEPT_SETTINGS);
        $session->takeOld();
        $values = $settings->all();
        $current = $values;
        if (is_array($kept)) {
            $values = array_replace($values, array_intersect_key($kept, $values));
        }
        return $this->portal('admin/news/desk/settings', [
            'values' => $values,
            // The stored values' fingerprints, never the kept copy's: a refused form saves against what is stored now.
            'loaded' => $settings->fingerprints(array_keys(self::SETTING_NAMES)),
            'errors' => $this->takeErrors(),
            'estimates' => [
                'research' => array_map(static fn (string $m): array => Pricing::session($m, $current['compose_model'], (int) $current['max_searches'])['research'], array_combine(array_keys(Settings::MODELS), array_keys(Settings::MODELS))),
                'writing' => array_map(static fn (string $m): array => Pricing::session($current['research_model'], $m, (int) $current['max_searches'])['writing'], array_combine(array_keys(Settings::MODELS), array_keys(Settings::MODELS))),
                'session' => Pricing::session($current['research_model'], $current['compose_model'], (int) $current['max_searches']),
            ],
            'perDay' => Schedule::perDay($settings),
            'perWeek' => Schedule::perWeek($settings),
        ], 'Desk settings');
    }

    public function saveSettings(Request $request): Response
    {
        $admin = $this->currentUser();
        $post = $request->post;
        $values = [];
        foreach (array_diff(array_keys(self::SETTING_NAMES), ['auto_publish', 'slots']) as $name) {
            $values[$name] = $request->input($name);
        }
        $values['auto_publish'] = $request->input('auto_publish') === '1' ? '1' : '0';
        $values['slots'] = self::slotRows($post['slots'] ?? null);
        $settings = new Settings($this->app);
        // A form posts every setting, so one drawn before someone else's save would put their values back: a setting
        // whose stored value is not the one the form was drawn with refuses the save, and the form comes back as typed.
        $loaded = is_array($post['loaded'] ?? null) ? $post['loaded'] : [];
        $stale = [];
        foreach ($settings->fingerprints(array_keys(self::SETTING_NAMES)) as $name => $print) {
            if (!is_string($loaded[$name] ?? null) || !hash_equals($print, $loaded[$name])) {
                $stale[$name] = self::SETTING_NAMES[$name] . ': someone else changed this after you opened the page. Check it, then save again.';
            }
        }
        if ($stale !== []) {
            $this->app->session()->set(self::KEPT_SETTINGS, $values);
            return $this->backWithErrors(self::BASE . 'settings/', $request, $stale, 'The settings were changed by someone else after you opened this page, so nothing was saved. Your changes are kept below: check the highlighted settings, then save again.');
        }
        $result = $settings->save($values, (int) $admin['id']);
        if ($result['errors'] !== []) {
            $this->app->session()->set(self::KEPT_SETTINGS, $values);
            return $this->backWithErrors(self::BASE . 'settings/', $request, $result['errors'], 'The settings were not saved. Check the highlighted fields.');
        }
        if ($result['changed'] === []) {
            return $this->redirect(self::BASE . 'settings/', 'info', 'Nothing changed, so nothing was saved.');
        }
        Audit::log($this->app, (int) $admin['id'], 'desk.settings', null, ['fields' => $result['changed']]);
        return $this->redirect(self::BASE . 'settings/', 'success', 'The settings are saved. The next session uses them.');
    }

    // --------------------------------------------------------------------------------------------- calendar

    public function calendar(Request $request): Response
    {
        $settings = new Settings($this->app);
        $from = $this->from($request->queryString('from'));
        $first = $this->thisMonth();
        return $this->portal('admin/news/desk/calendar', [
            'months' => self::months($from, $settings),
            'from' => $from->format('Y-m'),
            'earlier' => $from > $first ? max($first, $from->modify('-' . self::CALENDAR_MONTHS . ' months'))->format('Y-m') : null,
            'later' => $from->modify('+' . self::CALENDAR_MONTHS . ' months') <= $first->modify('+' . self::CALENDAR_AHEAD . ' months') ? $from->modify('+' . self::CALENDAR_MONTHS . ' months')->format('Y-m') : null,
            'perDay' => Schedule::perDay($settings),
        ], 'Desk calendar');
    }

    public function saveCalendar(Request $request): Response
    {
        $admin = $this->currentUser();
        $settings = new Settings($this->app);
        $from = $this->from($request->input('from'));
        $back = self::BASE . 'calendar/' . ($from > $this->thisMonth() ? '?from=' . $from->format('Y-m') : '');
        $ticked = [];
        foreach (is_array($request->post['days'] ?? null) ? $request->post['days'] : [] as $day) {
            if (is_string($day)) {
                $ticked[$day] = true;
            }
        }
        // Only the months the form drew (it posts them) change: a day the page never showed keeps its state, so a form
        // drawn before the month turned can never switch off a month it did not show.
        $shown = [];
        foreach (is_array($request->post['months'] ?? null) ? array_slice($request->post['months'], 0, self::CALENDAR_MONTHS) : [] as $key) {
            $first = is_string($key) ? $this->shownMonth($key) : null;
            for ($d = $first; $d !== null && $d->format('Y-m') === $key; $d = $d->modify('+1 day')) {
                $shown[$d->format('Y-m-d')] = $key;
            }
        }
        if ($shown === []) {
            return $this->redirect($back, 'warning', 'The calendar has changed since this page was opened, so nothing was saved. Make your changes again below.');
        }
        $reset = $request->input('reset');
        $today = (new \DateTimeImmutable('now', Schedule::zone()))->format('Y-m-d');
        $old = (array) $settings->get('date_overrides');
        $new = [];
        // Days outside the months shown keep their changes.
        foreach ($old as $date => $state) {
            if (!isset($shown[(string) $date])) {
                $new[(string) $date] = $state;
            }
        }
        foreach ($shown as $date => $month) {
            if ($date < $today || $month === $reset) {
                continue;
            }
            $on = isset($ticked[$date]);
            $was = $old[$date] ?? null;
            if ($was !== null && ($was === 'on') === $on) {
                $new[$date] = $was;
            } elseif ($on !== Schedule::isOnByWeekday($date, $settings)) {
                $new[$date] = $on ? 'on' : 'off';
            }
        }
        $result = $settings->save(['date_overrides' => $new], (int) $admin['id']);
        if ($result['errors'] !== []) {
            return $this->redirect($back, 'danger', (string) ($result['errors']['date_overrides'] ?? 'The calendar was not saved.'));
        }
        if ($result['changed'] === []) {
            return $this->redirect($back, 'info', 'Nothing changed, so nothing was saved.');
        }
        $counts = array_count_values(array_values($new));
        Audit::log($this->app, (int) $admin['id'], 'desk.calendar', null, ['on' => $counts['on'] ?? 0, 'off' => $counts['off'] ?? 0] + (preg_match('/^\d{4}-\d{2}$/', $reset) ? ['reset' => $reset] : []));
        $message = 'The calendar is saved.';
        if (preg_match('/^\d{4}-\d{2}$/', $reset) && in_array($reset, $shown, true)) {
            $message = self::monthName($reset) . ' is back to the weekly sessions. Your other changes are saved too.';
        }
        return $this->redirect($back, 'success', $message);
    }

    // ---------------------------------------------------------------------------------------------- prompts

    public function prompt(Request $request): Response
    {
        $settings = new Settings($this->app);
        $session = $this->app->session();
        $kept = $session->get(self::KEPT_PROMPT);
        $session->forget(self::KEPT_PROMPT);
        $session->takeOld();
        $texts = [];
        $versions = [];
        $authors = [];
        foreach (Settings::PROMPT_FIELDS as $field) {
            $texts[$field] = is_array($kept) && is_string($kept[$field] ?? null) ? $kept[$field] : ($field === 'brief' ? $settings->brief() : $settings->voice());
            $versions[$field] = $settings->versions($field, 20);
            foreach ($versions[$field] as $v) {
                if ($v['created_by'] !== null) {
                    $authors[(int) $v['created_by']] = '';
                }
            }
        }
        if ($authors !== []) {
            $ids = array_keys($authors);
            foreach ($this->app->db()->all('SELECT id, name FROM users WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')', $ids) as $u) {
                $authors[(int) $u['id']] = (string) $u['name'];
            }
        }
        return $this->portal('admin/news/desk/prompt', [
            'texts' => $texts,
            'defaults' => ['brief' => $settings->defaultPrompt('brief'), 'voice' => $settings->defaultPrompt('voice')],
            'versions' => $versions,
            'authors' => $authors,
            'fixed' => Prompts::fixed(),
            'errors' => $this->takeErrors(),
            'max' => Settings::PROMPT_MAX,
        ], 'Desk prompts');
    }

    public function savePrompt(Request $request): Response
    {
        $admin = $this->currentUser();
        $settings = new Settings($this->app);
        $action = AdminController::pick($request->input('action'), self::PROMPT_ACTIONS);
        $back = self::BASE . 'prompt/';
        if ($action === '') {
            return $this->redirect($back, 'danger', 'That action is not available. Use one of the buttons on this page.');
        }
        if ($action === 'restore') {
            $id = (int) $request->input('version');
            $row = $id > 0 ? $this->app->db()->one('SELECT field FROM news_prompt_versions WHERE id = ?', [$id]) : null;
            $result = $settings->restorePrompt($id, (int) $admin['id']);
            if ($result['error'] !== null) {
                return $this->redirect($back, 'danger', $result['error'] . ' Pick one from the list.');
            }
            $field = (string) ($row['field'] ?? 'brief');
            if (!$result['changed']) {
                return $this->redirect($back . '#' . $field, 'info', 'That version is already the one in use.');
            }
            Audit::log($this->app, (int) $admin['id'], 'desk.prompt', null, ['field' => $field, 'action' => 'restored', 'version' => $id]);
            return $this->redirect($back . '#' . $field, 'success', ucfirst(self::PROMPT_NAMES[$field]) . ' is back to the version you chose. The next session uses it.');
        }
        $field = self::PROMPT_ACTIONS[$action];
        $name = self::PROMPT_NAMES[$field];
        if (str_starts_with($action, 'reset_')) {
            if (!$settings->resetPrompt($field, (int) $admin['id'])) {
                return $this->redirect($back . '#' . $field, 'info', ucfirst($name) . ' is already the default.');
            }
            Audit::log($this->app, (int) $admin['id'], 'desk.prompt', null, ['field' => $field, 'action' => 'reset']);
            return $this->redirect($back . '#' . $field, 'success', ucfirst($name) . ' is back to the default. The earlier text is kept in its versions.');
        }
        $text = is_string($request->post['text'] ?? null) ? $request->post['text'] : '';
        $result = $settings->savePrompt($field, $text, (int) $admin['id']);
        if ($result['error'] !== null) {
            $this->app->session()->set(self::KEPT_PROMPT, [$field => $text]);
            return $this->backWithErrors($back . '#' . $field, $request, [$field => $result['error']], ucfirst($name) . ' was not saved. ' . $result['error']);
        }
        if (!$result['changed']) {
            return $this->redirect($back . '#' . $field, 'info', 'Nothing changed, so nothing was saved.');
        }
        Audit::log($this->app, (int) $admin['id'], 'desk.prompt', null, ['field' => $field, 'action' => 'saved']);
        return $this->redirect($back . '#' . $field, 'success', ucfirst($name) . ' is saved. The next session uses it.');
    }

    // ------------------------------------------------------------------------------------------------ a run

    public function run(Request $request): Response
    {
        $runs = new Runs($this->app);
        $run = $runs->find($request->attrInt('id'));
        if ($run === null) {
            return $this->notFound();
        }
        $db = $this->app->db();
        $requester = $run['requested_by'] !== null ? $db->one('SELECT id, name FROM users WHERE id = ?', [(int) $run['requested_by']]) : null;
        return $this->portal('admin/news/desk/run', [
            'run' => $run,
            'requester' => $requester,
            'articles' => $db->all('SELECT id, title, status, published_at, review_reason FROM news_articles WHERE run_id = ? ORDER BY id', [(int) $run['id']]),
        ], 'Desk run ' . (int) $run['id']);
    }

    // -------------------------------------------------------------------------------------------- internals

    /**
     * The months the calendar shows from $from: each a name, its key and its weeks (Monday first), each day null
     * outside the month or {date, day, label, past, on, default, changed, sessions}.
     *
     * @return list<array{key: string, name: string, weeks: list<list<array<string, mixed>|null>>}>
     */
    public static function months(\DateTimeImmutable $from, Settings $settings): array
    {
        $today = (new \DateTimeImmutable('now', Schedule::zone()))->format('Y-m-d');
        $overrides = (array) $settings->get('date_overrides');
        $out = [];
        for ($m = 0; $m < self::CALENDAR_MONTHS; $m++) {
            $first = $from->modify('first day of +' . $m . ' months');
            $weeks = [];
            $week = array_fill(0, (int) $first->format('N') - 1, null);
            for ($d = $first; $d->format('m') === $first->format('m'); $d = $d->modify('+1 day')) {
                $date = $d->format('Y-m-d');
                $sessions = count(Schedule::sessionsOn($date, $settings));
                $week[] = [
                    'date' => $date,
                    'day' => (int) $d->format('j'),
                    'label' => $d->format('l j F Y'),
                    'past' => $date < $today,
                    'on' => $sessions > 0,
                    'default' => Schedule::isOnByWeekday($date, $settings),
                    'changed' => isset($overrides[$date]),
                    'sessions' => $sessions,
                ];
                if (count($week) === 7) {
                    $weeks[] = $week;
                    $week = [];
                }
            }
            if ($week !== []) {
                $weeks[] = array_pad($week, 7, null);
            }
            $out[] = ['key' => $first->format('Y-m'), 'name' => $first->format('F Y'), 'weeks' => $weeks];
        }
        return $out;
    }

    /** How many AI articles are scheduled: published with a time still to come. */
    private static function scheduledAi(App $app): int
    {
        return (int) $app->db()->value("SELECT COUNT(*) FROM news_articles WHERE status = 'published' AND origin = 'ai' AND published_at > ?", [time()]);
    }

    /**
     * Turns the AI articles still scheduled into drafts held for review (HELD_ON_PAUSE), each audited as unpublished;
     * returns their ids. Each is one conditional UPDATE, so an article that went live meanwhile is never taken down,
     * and its time is dropped, so publishing it later puts it live then (ArticleStore::unpublish() does the same).
     *
     * @return list<int>
     */
    private function holdScheduled(int $adminId): array
    {
        $db = $this->app->db();
        $held = [];
        foreach ($db->all("SELECT id FROM news_articles WHERE status = 'published' AND origin = 'ai' AND published_at > ? ORDER BY published_at, id", [time()]) as $row) {
            $now = time();
            $id = (int) $row['id'];
            if ($db->exec("UPDATE news_articles SET status = 'draft', published_at = NULL, review_reason = ?, updated_at = ? WHERE id = ? AND status = 'published' AND published_at > ?", [self::HELD_ON_PAUSE, $now, $id, $now]) === 1) {
                $held[] = $id;
                Audit::log($this->app, $adminId, 'news.unpublished', (string) $id, ['was' => 'scheduled', 'by' => 'desk.paused']);
            }
        }
        return $held;
    }

    /** "1 article", "3 articles". */
    private static function articles(int $n): string
    {
        return $n . ' ' . ($n === 1 ? 'article' : 'articles');
    }

    /** "October 2026" for "2026-10". */
    private static function monthName(string $key): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m', $key, Schedule::zone());
        return $d !== false ? $d->format('F Y') : $key;
    }

    /** The first month the calendar shows: ?from=YYYY-MM from this month up to a year ahead, else this month. */
    private function from(string $value): \DateTimeImmutable
    {
        $first = $this->thisMonth();
        $d = preg_match('/^\d{4}-\d{2}$/', $value) ? \DateTimeImmutable::createFromFormat('!Y-m', $value, Schedule::zone()) : false;
        if ($d === false || $d->format('Y-m') !== $value || $d < $first || $d > $first->modify('+' . self::CALENDAR_AHEAD . ' months')) {
            return $first;
        }
        return $d;
    }

    /**
     * The first day of a month a calendar form posted (YYYY-MM), or null when it is not one the calendar could have
     * shown: up to its last month ahead. An earlier month is fine, since its days that have passed are never changed.
     */
    private function shownMonth(string $key): ?\DateTimeImmutable
    {
        $d = preg_match('/^\d{4}-\d{2}$/', $key) ? \DateTimeImmutable::createFromFormat('!Y-m', $key, Schedule::zone()) : false;
        $last = $this->thisMonth()->modify('+' . (self::CALENDAR_AHEAD + self::CALENDAR_MONTHS - 1) . ' months');
        return $d !== false && $d->format('Y-m') === $key && $d <= $last ? $d : null;
    }

    private function thisMonth(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('now', Schedule::zone()))->modify('first day of this month')->setTime(0, 0);
    }

    /**
     * The session rows as posted (slots[i][time], slots[i][days][], slots[i][remove]), strings only.
     *
     * @return list<array{time: string, days: list<string>, remove: string}>
     */
    private static function slotRows(mixed $rows): array
    {
        $out = [];
        foreach (is_array($rows) ? array_slice($rows, 0, Settings::MAX_SLOTS) : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $days = [];
            foreach (is_array($row['days'] ?? null) ? $row['days'] : [] as $day) {
                if (is_string($day)) {
                    $days[] = $day;
                }
            }
            $out[] = [
                'time' => is_string($row['time'] ?? null) ? trim($row['time']) : '',
                'days' => $days,
                'remove' => ($row['remove'] ?? '') === '1' ? '1' : '',
            ];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function currentUser(): array
    {
        $user = $this->app->auth()->user();
        if ($user === null) {
            throw new \LogicException('The desk pages need the auth middleware');
        }
        return $user;
    }
}
