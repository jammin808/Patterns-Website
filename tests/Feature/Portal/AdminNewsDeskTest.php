<?php

declare(strict_types=1);

namespace Psc\Tests\Feature\Portal;

use Psc\Http\Response;
use Psc\News\Pipeline;
use Psc\News\Runs;
use Psc\News\Settings;
use Psc\Tests\Fakes\FakeNewsModel;
use Psc\Tests\TestCase;

/**
 * The AI desk's admin pages (docs/NEWS.md §8, §11 Desk, §12 Feature: desk): who may open them, the admin fences,
 * pausing, continuing and running a session now, the settings and their validation, the calendar, the prompts and
 * their versions, and a run's record. Stored rows, audit rows and flashes first; page words beside them.
 */
final class AdminNewsDeskTest extends TestCase
{
    private const DESK = '/portal/admin/news/desk/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp(['news' => ['api_key' => 'sk-test']]);
    }

    /** @return iterable<string, array{string}> every GET page of the desk */
    public static function deskPages(): iterable
    {
        foreach ([self::DESK, self::DESK . 'settings/', self::DESK . 'calendar/', self::DESK . 'prompt/', self::DESK . 'runs/1/'] as $path) {
            yield $path => [$path];
        }
    }

    private function settings(): Settings
    {
        return new Settings($this->app);
    }

    /** @return array{type: string, message: string} */
    private static function lastFlash(): array
    {
        $all = array_values($_SESSION['_flash'] ?? []);
        self::assertNotSame([], $all, 'a flash message was queued');
        return end($all);
    }

    private static function assertRedirect(Response $r, string $location): void
    {
        self::assertSame(303, $r->status);
        self::assertSame($location, $r->getHeader('Location'));
    }

    /** @return list<array<string, mixed>> */
    private function auditRows(string $action): array
    {
        return $this->db->all('SELECT * FROM audit_log WHERE action = ? ORDER BY id', [$action]);
    }

    private function mainOf(Response $r): string
    {
        $start = strpos($r->body, '<main class="pt-main"');
        $end = strpos($r->body, '</main>');
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        return substr($r->body, (int) $start, (int) $end - (int) $start);
    }

    /** A finished run with candidates, references and a log, as a real session leaves it. */
    private function finishedRun(): int
    {
        $id = (new Runs($this->app))->queueManual(null);
        self::assertNotNull($id);
        (new Pipeline($this->app))->run($id, new FakeNewsModel(), new \DateTimeImmutable('now'));
        return $id;
    }

    /** The settings form as an administrator sends it unchanged, with the fingerprints the page drew it with. */
    private function settingsForm(array $over = [], ?array $loaded = null): array
    {
        $loaded ??= $this->loadedFingerprints();
        $s = $this->settings()->all();
        $slots = [];
        foreach ($s['slots'] as $i => $slot) {
            $slots[$i] = ['time' => $slot['time'], 'days' => array_map('strval', $slot['days'])];
        }
        return $over + [
            'max_per_session' => (string) $s['max_per_session'],
            'min_importance' => (string) $s['min_importance'],
            'auto_publish' => $s['auto_publish'] ? '1' : '',
            'research_model' => $s['research_model'],
            'compose_model' => $s['compose_model'],
            'max_searches' => (string) $s['max_searches'],
            'max_fetches' => (string) $s['max_fetches'],
            'monthly_budget_usd' => (string) $s['monthly_budget_usd'],
            'watch_urls' => implode("\n", $s['watch_urls']),
            'banned_domains' => implode("\n", $s['banned_domains']),
            'banned_topics' => implode("\n", $s['banned_topics']),
            'avoid_phrases' => implode("\n", $s['avoid_phrases']),
            'ai_note' => $s['ai_note'],
            'comments' => $s['comments'],
            'slots' => $slots,
            'digest_weekday' => (string) $s['digest_weekday'],
            'digest_time' => $s['digest_time'],
            'digest_per_tick' => (string) $s['digest_per_tick'],
            'digest_per_hour' => (string) $s['digest_per_hour'],
            'digest_per_day' => (string) $s['digest_per_day'],
            'loaded' => $loaded,
        ];
    }

    /** @return array<string, string> the settings page's hidden fingerprints, one per setting it posts */
    private function loadedFingerprints(): array
    {
        preg_match_all('#<input type="hidden" name="loaded\[([a-z_]+)\]" value="([0-9a-f]{16})">#', $this->request('GET', self::DESK . 'settings/')->body, $m);
        self::assertCount(20, $m[1], 'every setting the form posts, the weekly digest\'s five among them');
        return array_combine($m[1], $m[2]);
    }

    // ------------------------------------------------------------------------------------------- the fences

    /** Every desk page signed out and as a client, every form as a client, and posts without the token. */
    public function testOnlyAdministratorsReachTheDesk(): void
    {
        foreach (self::deskPages() as [$path]) {
            $r = $this->request('GET', $path);
            self::assertSame(302, $r->status, $path);
            self::assertSame('/portal/login/?next=' . rawurlencode($path), $r->getHeader('Location'));
        }
        $this->signInClient();
        foreach (self::deskPages() as [$path]) {
            self::assertSame(403, $this->request('GET', $path)->status, $path);
        }
        foreach ([self::DESK => ['action' => 'pause'], self::DESK . 'settings/' => [], self::DESK . 'calendar/' => [], self::DESK . 'prompt/' => ['action' => 'reset_brief']] as $path => $post) {
            self::assertSame(403, $this->post($path, $post)->status, $path);
        }
        self::assertTrue($this->settings()->get('enabled'), 'a client changed nothing');

        $_SESSION = [];
        $this->jar = [];
        $this->signInAdmin();
        self::assertSame(419, $this->post(self::DESK, ['action' => 'pause'], false)->status);
        self::assertSame(419, $this->post(self::DESK . 'settings/', $this->settingsForm(['max_per_session' => '5']), false)->status);
        self::assertTrue($this->settings()->get('enabled'));
        self::assertSame(3, $this->settings()->get('max_per_session'));
    }

    public function testEveryDeskPageRendersWithinTheAdminFences(): void
    {
        $this->signInAdmin();
        $run = $this->finishedRun();
        $this->settings()->savePrompt('brief', 'A shorter brief for the test.', null);
        $this->settings()->save(['date_overrides' => [(new \DateTimeImmutable('first day of next month'))->format('Y-m-d') => 'off']], null);
        $next = (new \DateTimeImmutable('first day of next month'))->format('Y-m');
        $tables = 0;
        foreach ([self::DESK, self::DESK . 'settings/', self::DESK . 'calendar/', self::DESK . 'calendar/?from=' . $next, self::DESK . 'prompt/', self::DESK . 'runs/' . $run . '/'] as $path) {
            $r = $this->request('GET', $path);
            self::assertSame(200, $r->status, $path);
            self::assertSame(1, substr_count($r->body, '<h1 class="pt-title"'), $path . ' has one page title');
            $main = $this->mainOf($r);
            self::assertStringNotContainsString('style="', $main, $path . ' has no inline styles');
            self::assertStringNotContainsString('<script', $main, $path . ' has no inline script');
            self::assertStringNotContainsString("\u{2014}", $r->body, $path . ' has no em dash');
            self::assertMatchesRegularExpression('#<link rel="stylesheet" href="/assets/css/pages/admin-desk\.css\?v=[^"]+">#', $r->body, $path . ' loads the desk stylesheet');
            $tables += $this->assertWideTablesStack($path, $main);
        }
        self::assertGreaterThanOrEqual(10, $tables, 'the runs, months, candidates, steps and references tables were checked');
        self::assertSame(404, $this->request('GET', self::DESK . 'runs/9999/')->status);
    }

    /** Wide tables stack on phones with every cell labelled by its column (AdminTest's fence); returns how many. */
    private function assertWideTablesStack(string $path, string $main): int
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8">' . $main . '</main>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($doc);
        $wide = 0;
        foreach ($xpath->query('//table') ?: [] as $table) {
            $headings = [];
            foreach ($xpath->query('./thead/tr/th', $table) ?: [] as $th) {
                $headings[] = trim((string) preg_replace('/\s+/u', ' ', $th->textContent));
            }
            self::assertNotSame('', trim((string) $xpath->query('./caption', $table)?->item(0)?->textContent), $path . ': every table has a caption');
            if (count($headings) < 4) {
                continue;
            }
            $wide++;
            self::assertContains('pt-table--stack', explode(' ', (string) $table->getAttribute('class')), $path);
            foreach ($xpath->query('./tbody/tr', $table) ?: [] as $tr) {
                $i = 0;
                foreach ($tr->childNodes as $cell) {
                    if ($cell instanceof \DOMElement && in_array($cell->nodeName, ['td', 'th'], true)) {
                        if ($cell->nodeName === 'td') {
                            self::assertSame($headings[$i] ?? null, $cell->getAttribute('data-label'), $path . ': column ' . ($i + 1));
                        }
                        $i++;
                    }
                }
            }
        }
        return $wide;
    }

    // ------------------------------------------------------------------------------------------ the main page

    public function testTheMainPageSaysTheStateTheTickTheSessionsAndTheSpend(): void
    {
        $this->signInAdmin();
        $page = $this->request('GET', self::DESK)->body;
        self::assertStringContainsString('<span class="pt-badge pt-badge--declined">Stopped: no tick yet</span>', $page, 'no session starts before the cron ticks');
        self::assertStringNotContainsString('>On</span>', $page);
        self::assertStringContainsString('The cron job has not ticked yet.', $page);
        self::assertSame(6, substr_count($this->mainOf($this->request('GET', self::DESK)), '<li><time datetime='), 'the next six sessions');
        self::assertStringContainsString('No sessions have run yet.', $page);

        $this->settings()->heartbeat(['run' => null, 'status' => 'idle']);
        $run = $this->finishedRun();
        $page = $this->request('GET', self::DESK)->body;
        self::assertStringContainsString('<span class="pt-badge pt-badge--active">On</span>', $page);
        self::assertStringNotContainsString('has not ticked', $page);
        self::assertStringNotContainsString('more than 35 minutes ago', $page);
        self::assertStringContainsString('href="/portal/admin/news/desk/runs/' . $run . '/"', $page);
        self::assertStringContainsString('<span class="pt-badge pt-badge--done">Done</span>', $page);
        self::assertStringContainsString('Published 1 article.', $page);
        $cost = (int) $this->db->value('SELECT cost_micros FROM news_runs WHERE id = ?', [$run]);
        self::assertStringContainsString('$' . number_format($cost / 1000000, 2), $page, 'this month\'s spend');

        // A tick older than 35 minutes is a warning to check the cron job.
        $this->db->exec("UPDATE news_settings SET value = ? WHERE name = 'heartbeat'", [json_encode(['t' => time() - 36 * 60])]);
        $page = $this->request('GET', self::DESK)->body;
        self::assertStringContainsString('more than 35 minutes ago', $page);
        self::assertStringContainsString('<span class="pt-badge pt-badge--declined">Stopped: cron not ticking</span>', $page);

        $this->bootApp(['news' => ['api_key' => ''], 'chat' => ['api_key' => '']]);
        $this->signInAdmin();
        $page = $this->request('GET', self::DESK)->body;
        self::assertStringContainsString('Stopped: no key', $page);
        self::assertStringNotContainsString('more than 35 minutes', $page, 'no key, no cron warning');
    }

    /** A tick busy with a long session may write nothing for a while: the session still in its time is the cron alive. */
    public function testASessionStillInItsTimeKeepsTheCronHealthy(): void
    {
        $this->signInAdmin();
        $this->settings()->heartbeat(['run' => null, 'status' => 'idle']);
        $this->db->exec("UPDATE news_settings SET value = ? WHERE name = 'heartbeat'", [json_encode(['t' => time() - 36 * 60])]);
        $id = (int) (new Runs($this->app))->queueManual(null);
        $this->db->exec("UPDATE news_runs SET status = 'running', started_at = ?, deadline_at = ? WHERE id = ?", [time() - 38 * 60, time() + 2 * 60, $id]);
        self::assertSame('ok', $this->settings()->cronState((new Runs($this->app))->running()));
        $page = $this->request('GET', self::DESK)->body;
        self::assertStringContainsString('A session is running now', $page);
        self::assertStringNotContainsString('more than 35 minutes ago', $page);
        self::assertStringContainsString('<span class="pt-badge pt-badge--active">On</span>', $page);

        // Past its deadline by more than the reaper allows, its process has gone: the cron is stopped.
        $this->db->exec('UPDATE news_runs SET deadline_at = ? WHERE id = ?', [time() - Runs::REAP_AFTER - 60, $id]);
        $page = $this->request('GET', self::DESK)->body;
        self::assertStringContainsString('more than 35 minutes ago', $page);
        self::assertStringContainsString('Stopped: cron not ticking', $page);
    }

    /** The admin overview's card says the desk's state as the desk page does, the cron included. */
    public function testTheOverviewCardSaysWhenTheCronIsNotTicking(): void
    {
        $this->signInAdmin();
        $card = static function (string $page): string {
            $start = strpos($page, 'id="ov-news"');
            self::assertNotFalse($start, 'the Industry Updates card');
            return substr($page, (int) $start, 2000);
        };
        $page = $card($this->request('GET', '/portal/admin/')->body);
        self::assertStringContainsString('<span class="pt-badge pt-badge--disabled">Stopped: the cron job has not ticked yet</span>', $page);
        self::assertStringNotContainsString('Running', $page);

        $this->settings()->heartbeat(['run' => null, 'status' => 'idle']);
        self::assertStringContainsString('<span class="pt-badge pt-badge--active">Running</span>', $card($this->request('GET', '/portal/admin/')->body));

        $this->db->exec("UPDATE news_settings SET value = ? WHERE name = 'heartbeat'", [json_encode(['t' => time() - 36 * 60])]);
        $page = $card($this->request('GET', '/portal/admin/')->body);
        self::assertStringContainsString('<span class="pt-badge pt-badge--disabled">Stopped: the cron job is not ticking</span>', $page);
        self::assertStringNotContainsString('Running', $page);

        $this->settings()->save(['enabled' => false], null);
        self::assertStringContainsString('<span class="pt-badge pt-badge--pending">Paused</span>', $card($this->request('GET', '/portal/admin/')->body), 'a pause says so first');
    }

    public function testPauseAndContinue(): void
    {
        $admin = $this->signInAdmin();
        self::assertRedirect($this->post(self::DESK, ['action' => 'pause']), self::DESK);
        self::assertSame('success', self::lastFlash()['type']);
        self::assertFalse($this->settings()->get('enabled'));
        self::assertCount(1, $this->auditRows('desk.paused'));
        self::assertSame($admin, (int) $this->auditRows('desk.paused')[0]['user_id']);
        self::assertStringContainsString('<span class="pt-badge pt-badge--pending">Paused</span>', $this->request('GET', self::DESK)->body);
        self::assertStringContainsString('Continue the desk', $this->request('GET', self::DESK)->body);

        $this->post(self::DESK, ['action' => 'pause']);
        self::assertSame(['info', 'The desk is already paused.'], array_values(self::lastFlash()));
        self::assertCount(1, $this->auditRows('desk.paused'), 'nothing changed, nothing audited');

        $this->post(self::DESK, ['action' => 'resume']);
        self::assertTrue($this->settings()->get('enabled'));
        self::assertCount(1, $this->auditRows('desk.resumed'));

        $this->post(self::DESK, ['action' => 'explode']);
        self::assertSame('danger', self::lastFlash()['type']);
    }

    public function testPausingHoldsTheArticlesASessionHasScheduledWhenTicked(): void
    {
        $admin = $this->signInAdmin();
        $this->settings()->heartbeat(['run' => null, 'status' => 'idle']);
        $live = $this->makeArticle(['origin' => 'ai']);
        $soon = $this->makeArticle(['origin' => 'ai', 'published_at' => time() + 600]);
        $later = $this->makeArticle(['origin' => 'ai', 'published_at' => time() + 1800]);
        $manual = $this->makeArticle(['published_at' => time() + 900]);
        $page = $this->request('GET', self::DESK)->body;
        self::assertStringContainsString('<input type="checkbox" id="f-hold" name="hold" value="1" form="desk-control" checked', $page, 'ticked by default');
        self::assertStringContainsString('the articles the desk has already scheduled (2 now)', $page);
        self::assertStringContainsString('<form method="post" action="/portal/admin/news/desk/" id="desk-control">', $page);

        self::assertRedirect($this->post(self::DESK, ['action' => 'pause', 'hold' => '1']), self::DESK);
        self::assertFalse($this->settings()->get('enabled'));
        $row = fn (int $id): array => (array) $this->db->one('SELECT status, published_at, review_reason FROM news_articles WHERE id = ?', [$id]);
        foreach ([$soon, $later] as $id) {
            self::assertSame(['draft', null, 'Held when the desk was paused'], array_values($row($id)), 'held: a draft with no time');
        }
        self::assertSame('published', $row($live)['status'], 'a live article is never taken down');
        self::assertSame('published', $row($manual)['status'], 'only the desk\'s own articles');
        self::assertSame([(string) $soon, (string) $later], array_column($this->auditRows('news.unpublished'), 'subject'));
        self::assertSame(['was' => 'scheduled', 'by' => 'desk.paused'], json_decode((string) $this->auditRows('news.unpublished')[0]['meta'], true));
        self::assertSame($admin, (int) $this->auditRows('news.unpublished')[0]['user_id']);
        self::assertSame(['held' => 2], json_decode((string) $this->auditRows('desk.paused')[0]['meta'], true));
        self::assertSame('success', self::lastFlash()['type']);
        self::assertStringContainsString('2 articles it had scheduled are drafts again, under Held for review.', self::lastFlash()['message']);
        self::assertStringContainsString('Held when the desk was paused', $this->request('GET', '/portal/admin/news/?tab=held')->body);

        // Not ticked, the schedule stands and the flash and the page say so.
        $this->post(self::DESK, ['action' => 'resume']);
        $next = $this->makeArticle(['origin' => 'ai', 'published_at' => time() + 1200]);
        $this->post(self::DESK, ['action' => 'pause']);
        self::assertFalse($this->settings()->get('enabled'));
        self::assertSame('published', $row($next)['status']);
        self::assertCount(2, $this->auditRows('news.unpublished'));
        self::assertNull($this->auditRows('desk.paused')[1]['meta']);
        self::assertStringContainsString('1 article it scheduled still goes live at its time; unpublish any you want to stop from the Scheduled tab.', self::lastFlash()['message']);
        $page = $this->request('GET', self::DESK)->body;
        self::assertStringContainsString('1 article the desk has scheduled still goes live at its time.', $page);
        self::assertStringContainsString('href="/portal/admin/news/?tab=scheduled"', $page);
        self::assertStringNotContainsString('name="hold"', $page, 'a paused desk has nothing to pause');
    }

    public function testRunNowQueuesOneSessionAndIsRefusedWhileOneIsQueued(): void
    {
        $admin = $this->signInAdmin();
        $this->settings()->heartbeat(['run' => null, 'status' => 'idle']);
        self::assertRedirect($this->post(self::DESK, ['action' => 'run_now']), self::DESK);
        self::assertSame('success', self::lastFlash()['type']);
        $runs = $this->db->all('SELECT * FROM news_runs');
        self::assertCount(1, $runs);
        self::assertSame(['manual', 'queued', $admin], [$runs[0]['kind'], $runs[0]['status'], (int) $runs[0]['requested_by']]);
        self::assertSame((string) $runs[0]['id'], $this->auditRows('desk.run_queued')[0]['subject']);
        self::assertStringContainsString('A session is queued.', $this->request('GET', self::DESK)->body);

        $this->post(self::DESK, ['action' => 'run_now']);
        self::assertSame('info', self::lastFlash()['type']);
        self::assertCount(1, $this->db->all('SELECT * FROM news_runs'), 'refused while one is queued');

        $this->db->exec("UPDATE news_runs SET status = 'running', started_at = ?, deadline_at = ?", [time(), time() + 2400]);
        $this->post(self::DESK, ['action' => 'run_now']);
        self::assertCount(1, $this->db->all('SELECT * FROM news_runs'), 'refused while one is running');
        self::assertStringContainsString('A session is running now', $this->request('GET', self::DESK)->body);
    }

    public function testRunNowIsRefusedWhilePausedOrWithoutAKey(): void
    {
        $this->signInAdmin();
        $this->post(self::DESK, ['action' => 'run_now']);
        self::assertSame('danger', self::lastFlash()['type']);
        self::assertStringContainsString('A session cannot start: the cron job has not ticked yet', self::lastFlash()['message']);
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM news_runs'), 'no tick, no session');

        $this->settings()->heartbeat(['run' => null, 'status' => 'idle']);
        $this->settings()->save(['enabled' => false], null);
        $this->post(self::DESK, ['action' => 'run_now']);
        self::assertSame(['danger', 'The desk is paused. Continue the desk first, then run a session.'], array_values(self::lastFlash()));

        $this->bootApp(['news' => ['api_key' => ''], 'chat' => ['api_key' => '']]);
        $this->signInAdmin();
        $this->post(self::DESK, ['action' => 'run_now']);
        self::assertSame('danger', self::lastFlash()['type']);
        self::assertStringContainsString('no Anthropic key is set', self::lastFlash()['message']);
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM news_runs'));
        self::assertSame([], $this->auditRows('desk.run_queued'));
    }

    // ----------------------------------------------------------------------------------------- the settings

    public function testSettingsAreSavedAllAtOnce(): void
    {
        $admin = $this->signInAdmin();
        $r = $this->post(self::DESK . 'settings/', $this->settingsForm([
            'max_per_session' => '2',
            'min_importance' => '4',
            'auto_publish' => '',
            'research_model' => 'claude-opus-5-5',
            'max_fetches' => '0',
            'banned_domains' => "https://www.spam.example/page\nspam.example",
            'comments' => 'approval',
            'slots' => [
                ['time' => '06:00', 'days' => ['1', '2', '3', '4', '5'], 'remove' => ''],
                ['time' => '14:30', 'days' => ['1'], 'remove' => '1'],
                ['time' => '', 'days' => ['3']],
                ['time' => '19:15', 'days' => ['6', '7']],
            ],
        ]));
        self::assertRedirect($r, self::DESK . 'settings/');
        self::assertSame('success', self::lastFlash()['type']);
        $s = $this->settings()->all();
        self::assertSame([2, 4, false, 'claude-opus-5-5', 0, ['spam.example'], 'approval'], [$s['max_per_session'], $s['min_importance'], $s['auto_publish'], $s['research_model'], $s['max_fetches'], $s['banned_domains'], $s['comments']]);
        self::assertSame([['time' => '06:00', 'days' => [1, 2, 3, 4, 5]], ['time' => '19:15', 'days' => [6, 7]]], $s['slots'], 'the removed and the blank rows are gone');
        $audit = $this->auditRows('desk.settings');
        self::assertCount(1, $audit);
        self::assertSame($admin, (int) $audit[0]['user_id']);
        self::assertSame(['fields' => ['max_per_session', 'min_importance', 'research_model', 'max_fetches', 'banned_domains', 'comments', 'auto_publish', 'slots']], json_decode((string) $audit[0]['meta'], true));

        $page = $this->request('GET', self::DESK . 'settings/')->body;
        self::assertStringContainsString('runs 7 sessions a week: Monday 1, Tuesday 1, Wednesday 1, Thursday 1, Friday 1, Saturday 1, Sunday 1', $page);
        self::assertMatchesRegularExpression('#id="f-research-model-claude-opus-5-5" name="research_model" value="claude-opus-5-5" checked#', $page);

        $this->post(self::DESK . 'settings/', $this->settingsForm());
        self::assertSame(['info', 'Nothing changed, so nothing was saved.'], array_values(self::lastFlash()));
        self::assertCount(1, $this->auditRows('desk.settings'));
    }

    public function testARefusedSaveStoresNothingAndComesBackAsTyped(): void
    {
        $this->signInAdmin();
        $phrases = implode("\n", array_map(static fn (int $i): string => 'a phrase to avoid that is number ' . $i . ' on the list', range(1, 150)));
        self::assertGreaterThan(5000, strlen($phrases));
        $r = $this->post(self::DESK . 'settings/', $this->settingsForm([
            'max_per_session' => '9',
            'avoid_phrases' => $phrases,
            'slots' => [['time' => '6am', 'days' => ['1']]],
        ]));
        self::assertRedirect($r, self::DESK . 'settings/');
        self::assertSame(['max_per_session', 'slots'], array_keys($_SESSION['_errors'] ?? []));
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM news_settings'), 'all or nothing');
        self::assertSame([], $this->auditRows('desk.settings'));

        $page = $this->request('GET', self::DESK . 'settings/')->body;
        self::assertStringContainsString('Enter a number of articles per session from 1 to 5.', $page);
        self::assertStringContainsString('href="#f-slot-1-time"', $page, 'the summary links to the first session time');
        self::assertMatchesRegularExpression('#id="f-max-per-session" name="max_per_session"[^>]*aria-invalid="true"[^>]*value="9"#', $page);
        self::assertStringContainsString('value="6am"', $page);
        self::assertStringContainsString('a phrase to avoid that is number 150 on the list', $page, 'the long list is kept whole');

        $again = $this->request('GET', self::DESK . 'settings/')->body;
        self::assertStringNotContainsString('aria-invalid="true"', $again, 'the errors were shown once');
        self::assertStringContainsString('value="3"', $again);
    }

    /** A tab opened before another admin saved would put their values back: it is refused, and comes back as typed. */
    public function testASettingsFormOpenedBeforeSomeoneElsesSaveIsRefused(): void
    {
        $this->signInAdmin();
        $stale = $this->settingsForm(['max_per_session' => '2']);
        $other = $this->makeUser('other@example.test', 'correct horse battery', 'admin');
        self::assertSame(['banned_domains'], $this->settings()->save(['banned_domains' => 'spam.example'], $other)['changed']);

        $r = $this->post(self::DESK . 'settings/', $stale);
        self::assertRedirect($r, self::DESK . 'settings/');
        self::assertSame('danger', self::lastFlash()['type']);
        self::assertStringContainsString('changed by someone else after you opened this page, so nothing was saved', self::lastFlash()['message']);
        self::assertSame(['banned_domains'], array_keys($_SESSION['_errors'] ?? []), 'only the setting someone else changed');
        self::assertSame(['spam.example'], $this->settings()->get('banned_domains'), 'their change stands');
        self::assertSame(3, $this->settings()->get('max_per_session'), 'nothing of the stale form was stored');
        self::assertSame([], $this->auditRows('desk.settings'));

        $page = $this->request('GET', self::DESK . 'settings/')->body;
        self::assertStringContainsString('<a href="#f-banned-domains">Banned websites: someone else changed this after you opened the page. Check it, then save again.</a>', $page);
        self::assertMatchesRegularExpression('#id="f-max-per-session" name="max_per_session"[^>]*value="2"#', $page, 'the form comes back as typed');

        // Saved again from the page that showed the refusal, it goes through: its fingerprints are the stored values'.
        preg_match_all('#name="loaded\[([a-z_]+)\]" value="([0-9a-f]{16})"#', $page, $m);
        $this->post(self::DESK . 'settings/', $this->settingsForm(['max_per_session' => '2'], array_combine($m[1], $m[2])));
        self::assertSame('success', self::lastFlash()['type']);
        self::assertSame([2, ['spam.example']], [$this->settings()->get('max_per_session'), $this->settings()->get('banned_domains')]);

        // A form that carries no fingerprints at all is refused for every setting.
        $this->post(self::DESK . 'settings/', ['loaded' => null] + $this->settingsForm(['max_per_session' => '4']));
        self::assertCount(20, $_SESSION['_errors'] ?? [], 'every setting the form posts');
        self::assertSame(2, $this->settings()->get('max_per_session'));
    }

    public function testTheSettingsShowTheEstimatedCostOfEachModel(): void
    {
        $this->signInAdmin();
        $page = $this->request('GET', self::DESK . 'settings/')->body;
        self::assertMatchesRegularExpression('#Sonnet 5\.5, about \$0\.\d\d to \$0\.\d\d a session#', $page);
        self::assertMatchesRegularExpression('#Opus 5\.5, about \$\d\.\d\d to \$\d\.\d\d a session#', $page);
        self::assertMatchesRegularExpression('#a session costs about \$1\.\d\d to \$\d\.\d\d, so about \$\d+(\.\d\d)? to \$\d+(\.\d\d)? a month at 14 sessions a week#', $page);
    }

    // ---------------------------------------------------------------------------------------- the calendar

    public function testTheCalendarTurnsDaysOnAndOffAndResetsAMonth(): void
    {
        $admin = $this->signInAdmin();
        // Weekdays only, so a Saturday is off by its weekday.
        $this->settings()->save(['slots' => [['time' => '06:00', 'days' => [1, 2, 3, 4, 5]]]], null);
        $first = new \DateTimeImmutable('first day of next month', new \DateTimeZone('Europe/London'));
        $month = $first->format('Y-m');
        $saturday = ($first->format('N') === '6' ? $first : $first->modify('next saturday'))->format('Y-m-d');
        $monday = ($first->format('N') === '1' ? $first : $first->modify('next monday'))->format('Y-m-d');
        $later = $first->modify('+5 months')->format('Y-m-d');
        $this->settings()->save(['date_overrides' => [$later => 'off']], null);

        // Every weekday ticked as shown, except the first Monday; the first Saturday ticked.
        $weekly = [];
        foreach (\Psc\Controllers\Admin\AdminNewsDeskController::months($first, $this->settings()) as $m) {
            foreach ($m['weeks'] as $week) {
                foreach ($week as $d) {
                    if ($d !== null && $d['on']) {
                        $weekly[] = $d['date'];
                    }
                }
            }
        }
        $days = array_values(array_diff($weekly, [$monday]));
        $days[] = $saturday;
        $months = self::monthKeys($first);
        $r = $this->post(self::DESK . 'calendar/', ['from' => $month, 'months' => $months, 'days' => $days]);
        self::assertRedirect($r, self::DESK . 'calendar/?from=' . $month);
        self::assertSame('success', self::lastFlash()['type']);
        $overrides = $this->settings()->get('date_overrides');
        self::assertSame('on', $overrides[$saturday]);
        self::assertSame('off', $overrides[$monday]);
        self::assertSame('off', $overrides[$later], 'a day outside the months shown keeps its change');
        self::assertCount(3, $overrides);
        $audit = $this->auditRows('desk.calendar');
        self::assertSame([$admin, ['on' => 1, 'off' => 2]], [(int) $audit[0]['user_id'], json_decode((string) $audit[0]['meta'], true)]);

        $page = $this->request('GET', self::DESK . 'calendar/?from=' . $month)->body;
        self::assertMatchesRegularExpression('#id="desk-day-' . $saturday . '" name="days\[\]" value="' . $saturday . '" checked#', $page);
        self::assertMatchesRegularExpression('#id="desk-day-' . $monday . '" name="days\[\]" value="' . $monday . '" aria-describedby#', $page, 'the Monday is not ticked');
        $label = (new \DateTimeImmutable($saturday))->format('l j F Y');
        self::assertStringContainsString('<span class="u-visually-hidden">' . $label . '</span>', $page, 'each day is labelled with its full date');
        self::assertSame(2, substr_count($page, 'Changed</span>'));
        self::assertStringContainsString('<caption id="desk-month-' . $month . '">' . $first->format('F Y') . '</caption>', $page);

        // Reset the month: back to the weekly pattern, whatever was ticked in it.
        $this->post(self::DESK . 'calendar/', ['from' => $month, 'months' => $months, 'days' => $days, 'reset' => $month]);
        self::assertStringContainsString($first->format('F Y') . ' is back to the weekly sessions.', self::lastFlash()['message']);
        self::assertSame([$later => 'off'], $this->settings()->get('date_overrides'));

        $this->post(self::DESK . 'calendar/', ['from' => $month, 'months' => $months, 'days' => $weekly]);
        self::assertSame(['info', 'Nothing changed, so nothing was saved.'], array_values(self::lastFlash()));
    }

    /** The three month keys the calendar draws from $first, as its form posts them. */
    private static function monthKeys(\DateTimeImmutable $first): array
    {
        return [$first->format('Y-m'), $first->modify('first day of +1 month')->format('Y-m'), $first->modify('first day of +2 months')->format('Y-m')];
    }

    /** Every date of the months given that runs sessions by its weekday: a calendar form ticked as it was drawn. */
    private function onDays(array $months): array
    {
        $days = [];
        foreach ($months as $key) {
            for ($d = new \DateTimeImmutable($key . '-01'); $d->format('Y-m') === $key; $d = $d->modify('+1 day')) {
                if (\Psc\News\Schedule::isOnByWeekday($d->format('Y-m-d'), $this->settings())) {
                    $days[] = $d->format('Y-m-d');
                }
            }
        }
        return $days;
    }

    /**
     * A tab drawn last month (last month, this one and the next) and saved now changes only the days it showed: the
     * month after next, which it never drew, keeps the weekly sessions.
     */
    public function testACalendarDrawnBeforeTheMonthTurnedChangesOnlyTheMonthsItShowed(): void
    {
        $this->signInAdmin();
        $last = new \DateTimeImmutable('first day of last month', new \DateTimeZone('Europe/London'));
        $drawn = self::monthKeys($last);
        self::assertSame((new \DateTimeImmutable('first day of next month', new \DateTimeZone('Europe/London')))->format('Y-m'), $drawn[2]);
        $form = ['from' => $drawn[0], 'months' => $drawn, 'days' => $this->onDays($drawn)];

        $this->post(self::DESK . 'calendar/', $form);
        self::assertSame(['info', 'Nothing changed, so nothing was saved.'], array_values(self::lastFlash()));
        self::assertSame([], $this->settings()->get('date_overrides'), 'no day of a month the form never drew is turned off');
        self::assertSame([], $this->auditRows('desk.calendar'));

        // A day it showed and the admin unticked is saved; the month it never drew is still untouched.
        $off = $drawn[2] . '-15';
        $r = $this->post(self::DESK . 'calendar/', ['days' => array_values(array_diff($form['days'], [$off]))] + $form);
        self::assertRedirect($r, self::DESK . 'calendar/');
        self::assertSame('success', self::lastFlash()['type']);
        self::assertSame([$off => 'off'], $this->settings()->get('date_overrides'));
        self::assertSame(['on' => 0, 'off' => 1], json_decode((string) $this->auditRows('desk.calendar')[0]['meta'], true));
    }

    /** A form that does not say which months it drew (one from before the months were posted) saves nothing. */
    public function testACalendarFormWithoutItsMonthsIsRefused(): void
    {
        $this->signInAdmin();
        $this->settings()->save(['date_overrides' => [(new \DateTimeImmutable('tomorrow', new \DateTimeZone('Europe/London')))->format('Y-m-d') => 'off']], null);
        $before = $this->settings()->get('date_overrides');
        foreach ([[], ['months' => ['nonsense', '2999-01']]] as $months) {
            $r = $this->post(self::DESK . 'calendar/', ['from' => date('Y-m'), 'days' => []] + $months);
            self::assertRedirect($r, self::DESK . 'calendar/');
            self::assertSame(['warning', 'The calendar has changed since this page was opened, so nothing was saved. Make your changes again below.'], array_values(self::lastFlash()));
            self::assertSame($before, $this->settings()->get('date_overrides'));
        }
        self::assertSame([], $this->auditRows('desk.calendar'));
        self::assertSame(3, substr_count($this->request('GET', self::DESK . 'calendar/')->body, '<input type="hidden" name="months[]" value="'), 'the form posts the three months it draws');
    }

    public function testTheCalendarShowsThreeMonthsFromThisMonthOnly(): void
    {
        $this->signInAdmin();
        $now = new \DateTimeImmutable('first day of this month', new \DateTimeZone('Europe/London'));
        foreach (['', '?from=1999-01', '?from=nonsense', '?from=' . $now->modify('+20 months')->format('Y-m')] as $query) {
            $page = $this->request('GET', self::DESK . 'calendar/' . $query)->body;
            self::assertStringContainsString('<caption id="desk-month-' . $now->format('Y-m') . '">', $page, $query);
            self::assertSame(3, substr_count($page, '<caption id="desk-month-'));
            self::assertSame(3, substr_count($page, 'name="reset"'));
        }
        $page = $this->request('GET', self::DESK . 'calendar/')->body;
        self::assertStringContainsString('?from=' . $now->modify('+3 months')->format('Y-m') . '">Later months', $page);
        self::assertStringNotContainsString('Earlier months', $page);
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('Y-m-d');
        if ($today !== $now->format('Y-m-d')) {
            self::assertStringNotContainsString('value="' . $now->format('Y-m-d') . '"', $page, 'a day that has passed cannot be ticked');
        }
    }

    // ------------------------------------------------------------------------------------------ the prompts

    public function testThePromptsSaveResetAndRestoreAsVersions(): void
    {
        $admin = $this->signInAdmin();
        $default = $this->settings()->defaultPrompt('brief');
        self::assertRedirect($this->post(self::DESK . 'prompt/', ['action' => 'save_brief', 'text' => "Find LED news only.\r\nNothing else."]), self::DESK . 'prompt/#brief');
        self::assertSame('success', self::lastFlash()['type']);
        self::assertSame("Find LED news only.\nNothing else.", $this->settings()->brief());
        $first = (int) $this->db->value("SELECT id FROM news_prompt_versions WHERE field = 'brief'");
        self::assertSame(['field' => 'brief', 'action' => 'saved'], json_decode((string) $this->auditRows('desk.prompt')[0]['meta'], true));
        self::assertSame($admin, (int) $this->auditRows('desk.prompt')[0]['user_id']);

        $this->post(self::DESK . 'prompt/', ['action' => 'save_brief', 'text' => 'Find LED news only.' . "\n" . 'Nothing else.']);
        self::assertSame(['info', 'Nothing changed, so nothing was saved.'], array_values(self::lastFlash()));

        $page = $this->request('GET', self::DESK . 'prompt/')->body;
        self::assertStringContainsString('Changed from the default.', $page);
        self::assertStringContainsString('<span class="pt-badge pt-badge--active">In use</span>', $page);
        self::assertStringContainsString('You research news for Industry Updates', $page, 'the fixed rules are shown');
        self::assertStringContainsString('by <bdi>Test Admin</bdi>', $page);

        $this->post(self::DESK . 'prompt/', ['action' => 'reset_brief']);
        self::assertSame($default, $this->settings()->brief());
        self::assertSame(2, (int) $this->db->value("SELECT COUNT(*) FROM news_prompt_versions WHERE field = 'brief'"));
        $this->post(self::DESK . 'prompt/', ['action' => 'reset_brief']);
        self::assertSame('info', self::lastFlash()['type']);

        $this->post(self::DESK . 'prompt/', ['action' => 'restore', 'version' => (string) $first]);
        self::assertSame("Find LED news only.\nNothing else.", $this->settings()->brief());
        self::assertSame(['field' => 'brief', 'action' => 'restored', 'version' => $first], json_decode((string) $this->auditRows('desk.prompt')[2]['meta'], true));
        self::assertStringContainsString('Use this version again', $this->request('GET', self::DESK . 'prompt/')->body);

        $this->post(self::DESK . 'prompt/', ['action' => 'restore', 'version' => '99999']);
        self::assertSame('danger', self::lastFlash()['type']);
        $this->post(self::DESK . 'prompt/', ['action' => 'save_voice', 'text' => 'Dry, warm and short.']);
        self::assertSame('Dry, warm and short.', $this->settings()->voice());
        $this->post(self::DESK . 'prompt/', ['action' => 'rewrite_everything']);
        self::assertSame('danger', self::lastFlash()['type']);
        self::assertCount(4, $this->auditRows('desk.prompt'));
    }

    public function testATooLongPromptIsRefusedAndKeptWhole(): void
    {
        $this->signInAdmin();
        $text = str_repeat('A long line about what the desk should look for. ', 450);
        self::assertGreaterThan(Settings::PROMPT_MAX, mb_strlen($text));
        $this->post(self::DESK . 'prompt/', ['action' => 'save_voice', 'text' => $text]);
        self::assertSame(['voice'], array_keys($_SESSION['_errors'] ?? []));
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM news_prompt_versions'));
        $page = $this->request('GET', self::DESK . 'prompt/')->body;
        self::assertStringContainsString(e(trim($text)), $page, 'the text comes back whole');
        self::assertMatchesRegularExpression('#id="f-voice" name="text"[^>]*aria-invalid="true"#', $page);
    }

    // --------------------------------------------------------------------------------------------- a run

    public function testARunsPageShowsItsStepsCandidatesReferencesAndUsage(): void
    {
        $this->signInAdmin();
        $run = $this->finishedRun();
        $article = (int) $this->db->value('SELECT id FROM news_articles WHERE run_id = ?', [$run]);
        $page = $this->mainOf($this->request('GET', self::DESK . 'runs/' . $run . '/'));
        self::assertStringContainsString('Run <span class="pt-mono">' . $run . '</span>: started by hand', $page);
        self::assertMatchesRegularExpression('#<span class="pt-badge pt-badge--done">Done</span>\s+Published 1 article\.#', $page);
        self::assertStringContainsString('href="/portal/admin/news/' . $article . '/"', $page);
        foreach (['Context', 'Research', 'Select', 'Compose', 'Publish'] as $step) {
            self::assertStringContainsString('<th scope="row">' . $step . '</th>', $page);
        }
        self::assertStringContainsString('Vendor ships LED processor firmware with frame-accurate genlock', $page);
        self::assertStringContainsString('href="https://vendor.example/news/genlock-firmware" rel="nofollow noopener noreferrer" target="_blank"', $page);
        self::assertStringContainsString('<th scope="row" class="pt-mono">S3</th>', $page);
        self::assertStringContainsString('claude-sonnet-5-5, claude-opus-5-5, claude-haiku-5-5', $page);
        self::assertStringContainsString('Show the brief the research wrote', $page);
        self::assertStringContainsString(e(FakeNewsModel::BRIEF), $page);
    }

    public function testARefusedArticlesRulesShowOnItsRun(): void
    {
        $this->signInAdmin();
        $id = (new Runs($this->app))->queueManual(null);
        $model = (new FakeNewsModel())->queue('compose', ['data' => FakeNewsModel::article(['sources' => [['ref' => 'S9', 'publisher' => 'X', 'kind' => 'press']], 'confirmed' => [], 'speculation' => []])]);
        (new Pipeline($this->app))->run((int) $id, $model, new \DateTimeImmutable('now'));
        $page = $this->mainOf($this->request('GET', self::DESK . 'runs/' . $id . '/'));
        self::assertStringContainsString('Dropped', $page);
        self::assertStringContainsString('<span class="pt-mono">refs</span>: No valid source remains.', $page);
    }
}
