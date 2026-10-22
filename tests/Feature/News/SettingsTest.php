<?php

declare(strict_types=1);

namespace Psc\Tests\Feature\News;

use Psc\News\Settings;
use Psc\Tests\TestCase;

/** The desk's settings, prompts, lease, heartbeat and email stamps: stored rows first, then what reads them back. */
final class SettingsTest extends TestCase
{
    private function settings(): Settings
    {
        return new Settings($this->app);
    }

    public function testEverySettingHasItsDefaultUntilChanged(): void
    {
        $all = $this->settings()->all();
        self::assertSame(Settings::DEFAULTS, $all);
        self::assertSame([
            'enabled', 'max_per_session', 'min_importance', 'slots', 'date_overrides', 'auto_publish', 'research_model',
            'compose_model', 'max_searches', 'max_fetches', 'monthly_budget_usd', 'watch_urls', 'banned_domains',
            'banned_topics', 'avoid_phrases', 'ai_note', 'comments',
        ], array_keys($all), 'docs/NEWS.md §8, in its order');
        self::assertSame([['time' => '06:00', 'days' => [1, 2, 3, 4, 5, 6, 7]], ['time' => '14:30', 'days' => [1, 2, 3, 4, 5, 6, 7]]], $all['slots']);
        self::assertSame(['claude-sonnet-5-5', 'claude-opus-5-5', 12, 6, 100, 'open', true, true, 3, 3], [
            $all['research_model'], $all['compose_model'], $all['max_searches'], $all['max_fetches'], $all['monthly_budget_usd'],
            $all['comments'], $all['enabled'], $all['auto_publish'], $all['max_per_session'], $all['min_importance'],
        ]);
        self::assertCount(20, $all['avoid_phrases']);
        self::assertContains('delve', $all['avoid_phrases']);
        self::assertNotContains('seamless switcher', $all['avoid_phrases']);
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM news_settings'), 'reading stores nothing');
        $this->expectException(\InvalidArgumentException::class);
        $this->settings()->get('lease');
    }

    public function testSaveTakesAFormsStringsAndStoresTypedValues(): void
    {
        $admin = $this->makeUser('admin@example.test', 'correct horse battery', 'admin');
        $result = $this->settings()->save([
            'enabled' => '0',
            'max_per_session' => '5',
            'max_fetches' => '0',
            'research_model' => 'claude-opus-5-5',
            'banned_domains' => "https://www.Spam.example/page\n\nspam.example\nother.example\n",
            'banned_topics' => "Celebrity gossip\ncelebrity GOSSIP\n",
            'watch_urls' => "https://vendor.example/releases\n",
            'comments' => 'approval',
            '_csrf' => 'ignored',
        ], $admin);
        self::assertSame([], $result['errors']);
        self::assertSame(['enabled', 'max_per_session', 'max_fetches', 'research_model', 'banned_domains', 'banned_topics', 'watch_urls', 'comments'], $result['changed']);
        $row = $this->db->one("SELECT * FROM news_settings WHERE name = 'banned_domains'");
        self::assertSame('["spam.example","other.example"]', $row['value']);
        self::assertSame($admin, (int) $row['updated_by']);
        $s = $this->settings();
        self::assertFalse($s->get('enabled'));
        self::assertSame(5, $s->get('max_per_session'));
        self::assertSame(0, $s->get('max_fetches'));
        self::assertSame(['Celebrity gossip'], $s->get('banned_topics'));
        self::assertSame(['https://vendor.example/releases'], $s->get('watch_urls'));
        self::assertSame('approval', $s->get('comments'));
        self::assertSame(['changed' => [], 'errors' => []], $s->save(['max_per_session' => 5, 'enabled' => false], $admin), 'the same values change nothing');
    }

    public function testAValueOutOfBoundsSavesNothingAndSaysWhy(): void
    {
        $result = $this->settings()->save([
            'max_per_session' => '6',
            'min_importance' => '0',
            'max_searches' => 'lots',
            'max_fetches' => '21',
            'monthly_budget_usd' => '4',
            'research_model' => 'claude-haiku-5-5',
            'comments' => 'sometimes',
            'enabled' => 'perhaps',
            'ai_note' => '',
            'watch_urls' => "https://ok.example/\nnot a web address",
            'banned_domains' => "fine.example\n127.0.0.1",
            'banned_topics' => str_repeat('t', 81),
        ], null);
        self::assertSame([
            'max_per_session' => 'Enter a number of articles per session from 1 to 5.',
            'min_importance' => 'Choose how newsworthy a story must be, from 1 to 5.',
            'max_searches' => 'Enter a number of web searches per session from 1 to 30.',
            'max_fetches' => 'Enter a number of pages read per session from 0 to 20.',
            'monthly_budget_usd' => 'Enter a monthly cap from 5 to 2000 US dollars, in whole dollars.',
            'research_model' => 'Choose Opus 5.5 or Sonnet 5.5.',
            'comments' => 'Choose open, approval or closed.',
            'enabled' => 'Choose yes or no.',
            'ai_note' => 'Enter the note shown on AI-written articles, up to 300 characters.',
            'watch_urls' => 'Line 2 is not a web address. Enter full addresses starting https://, one per line.',
            'banned_domains' => 'Line 2 is not a website name. Enter names such as example.com, one per line.',
            'banned_topics' => 'Keep each line to 80 characters (line 1).',
        ], $result['errors']);
        self::assertSame([], $result['changed']);
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM news_settings'));
        self::assertSame('Keep the list to 30 lines.', $this->settings()->save(['watch_urls' => implode("\n", array_map(static fn (int $i): string => 'https://site' . $i . '.example/', range(1, 31)))], null)['errors']['watch_urls']);
    }

    public function testSessionsAreRowsOfATimeAndItsDays(): void
    {
        $result = $this->settings()->save(['slots' => [
            ['time' => '14:30', 'days' => ['1', '3', '5']],
            ['time' => '', 'days' => ['2']],
            ['time' => '06:00', 'days' => [7, 1, 1]],
            ['time' => '20:00', 'days' => ['2'], 'remove' => '1'],
        ]], null);
        self::assertSame([], $result['errors']);
        self::assertSame([['time' => '06:00', 'days' => [1, 7]], ['time' => '14:30', 'days' => [1, 3, 5]]], $this->settings()->get('slots'), 'sorted by time; blank and removed rows left out');
        $error = fn (array $slots): ?string => $this->settings()->save(['slots' => $slots], null)['errors']['slots'] ?? null;
        self::assertSame('Enter each session time as HH:MM, for example 06:00.', $error([['time' => '6am', 'days' => [1]]]));
        self::assertSame('Tick at least one day for the session at 09:00, or tick Remove.', $error([['time' => '09:00', 'days' => []]]));
        self::assertSame('Two sessions are at 09:00. Give each session its own time.', $error([['time' => '09:00', 'days' => [1]], ['time' => '09:00', 'days' => [2]]]));
        self::assertSame('Keep to 6 sessions.', $error(array_map(static fn (int $h): array => ['time' => sprintf('%02d:00', $h), 'days' => [1]], range(1, 7))));
        self::assertSame(['changed' => ['slots'], 'errors' => []], $this->settings()->save(['slots' => []], null), 'no sessions at all is allowed');
    }

    public function testCalendarOverridesDropPastDays(): void
    {
        $london = new \DateTimeZone('Europe/London');
        $today = (new \DateTimeImmutable('now', $london))->format('Y-m-d');
        $tomorrow = (new \DateTimeImmutable('tomorrow', $london))->format('Y-m-d');
        $result = $this->settings()->save(['date_overrides' => [$tomorrow => 'off', '2020-01-01' => 'on', $today => 'on']], null);
        self::assertSame([], $result['errors']);
        self::assertSame([$today => 'on', $tomorrow => 'off'], $this->settings()->get('date_overrides'));
        self::assertSame('Enter calendar dates as YYYY-MM-DD.', $this->settings()->save(['date_overrides' => ['2026-02-30' => 'on']], null)['errors']['date_overrides'] ?? null);
        self::assertSame('Mark each changed day as on or off.', $this->settings()->save(['date_overrides' => [$tomorrow => 'maybe']], null)['errors']['date_overrides'] ?? null);
    }

    public function testTheAiNoteIsOneLineWithoutDashes(): void
    {
        $this->settings()->save(['ai_note' => "Written by the desk \u{2014} tell us\nif it is wrong."], null);
        self::assertSame('Written by the desk, tell us if it is wrong.', $this->settings()->get('ai_note'));
        self::assertArrayHasKey('ai_note', $this->settings()->save(['ai_note' => str_repeat('n', 301)], null)['errors']);
    }

    public function testThePromptsKeepEveryVersion(): void
    {
        $s = $this->settings();
        self::assertSame(trim((string) file_get_contents(PSC_ROOT . '/content/news/brief.md')), $s->brief());
        self::assertSame(trim((string) file_get_contents(PSC_ROOT . '/content/news/voice.md')), $s->voice());
        self::assertSame([], $s->versions('brief'));

        self::assertSame(['changed' => true, 'error' => null], $s->savePrompt('brief', "Look for LED news \u{2014} only.\r\n", 4));
        self::assertSame('Look for LED news, only.', $s->brief());
        self::assertSame(['changed' => false, 'error' => null], $s->savePrompt('brief', 'Look for LED news, only.', 4), 'the same text is no change');
        $this->db->update('news_prompt_versions', ['created_at' => time() - 10], ['field' => 'brief']);
        self::assertTrue($s->savePrompt('brief', 'Look for audio news.', 4)['changed']);
        $versions = $s->versions('brief');
        self::assertSame(['Look for audio news.', 'Look for LED news, only.'], array_column($versions, 'body'), 'the newest first');
        self::assertSame(4, (int) $versions[0]['created_by']);

        self::assertTrue($s->restorePrompt((int) $versions[1]['id'], 4)['changed']);
        self::assertSame('Look for LED news, only.', $s->brief());
        self::assertSame(['changed' => false, 'error' => 'That version is no longer available.'], $s->restorePrompt(999, 4));
        self::assertTrue($s->resetPrompt('brief', 4));
        self::assertSame($s->defaultPrompt('brief'), $s->brief());
        self::assertCount(4, $s->versions('brief'), 'a reset is a version too');
        self::assertSame(trim((string) file_get_contents(PSC_ROOT . '/content/news/voice.md')), $s->voice(), 'the voice is untouched');

        self::assertSame('Write the voice in up to 20,000 characters, or reset it to the default.', $s->savePrompt('voice', '   ', 4)['error']);
        $this->expectException(\InvalidArgumentException::class);
        $s->savePrompt('rules', 'x', 4);
    }

    public function testOnlyOneRunHoldsTheLease(): void
    {
        $s = $this->settings();
        $until = time() + 600;
        self::assertNull($s->leaseHolder());
        self::assertTrue($s->lease(1, $until));
        self::assertFalse($s->lease(2, $until), 'held by run 1');
        self::assertTrue($s->lease(1, $until), 'run 1 renews it');
        self::assertSame(['run' => 1, 'until' => $until], $s->leaseHolder());
        $s->releaseLease(2);
        self::assertSame(1, $s->leaseHolder()['run'] ?? null, 'only the holder gives it up');
        $s->releaseLease(1);
        self::assertNull($s->leaseHolder());
        self::assertTrue($s->lease(2, $until));
        $this->db->exec("UPDATE news_settings SET updated_at = ? WHERE name = 'lease'", [time() - 1]);
        self::assertTrue($s->lease(3, $until), 'an expired lease is taken over');
        self::assertFalse($s->lease(4, time() - 5), 'a lease that has already ended is never granted');
        self::assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM news_settings WHERE name = 'lease'"));
        self::assertArrayNotHasKey('lease', $s->all());
    }

    public function testTheHeartbeatAndTheEmailStamps(): void
    {
        $s = $this->settings();
        self::assertNull($s->lastTick());
        $s->heartbeat(['ran' => 'import', 'imported' => 2]);
        $tick = $s->lastTick();
        self::assertSame(['ran' => 'import', 'imported' => 2], array_diff_key($tick ?? [], ['t' => 1]));
        self::assertEqualsWithDelta(time(), $tick['t'] ?? 0, 2);
        $s->heartbeat(['ran' => 'run']);
        self::assertSame('run', $s->lastTick()['ran'] ?? null);

        self::assertTrue($s->claimEmail('failed'));
        self::assertFalse($s->claimEmail('failed'), 'one a day');
        self::assertTrue($s->claimEmail('held'), 'each kind on its own');
        $this->db->exec("UPDATE news_settings SET updated_at = ? WHERE name = 'email_failed'", [time() - 86401]);
        self::assertTrue($s->claimEmail('failed'));
        self::assertSame(Settings::DEFAULTS, $s->all(), 'the internal rows are never settings');
    }

    public function testFingerprintsChangeOnlyWithTheirSetting(): void
    {
        $s = $this->settings();
        $before = $s->fingerprints(['banned_domains', 'max_per_session']);
        self::assertSame(['banned_domains', 'max_per_session'], array_keys($before));
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $before['banned_domains']);
        self::assertSame($before, $this->settings()->fingerprints(['banned_domains', 'max_per_session']), 'the same values, the same prints');
        $s->save(['banned_domains' => 'spam.example'], null);
        $after = $this->settings()->fingerprints(['banned_domains', 'max_per_session']);
        self::assertNotSame($before['banned_domains'], $after['banned_domains']);
        self::assertSame($before['max_per_session'], $after['max_per_session']);
    }

    public function testTheCronStateReadsTheHeartbeatAndARunStillInItsTime(): void
    {
        $s = $this->settings();
        $now = time();
        $running = ['status' => 'running', 'deadline_at' => $now + 600];
        self::assertSame('never', $s->cronState(null, $now));
        self::assertSame('never', $s->cronState($running, $now), 'a session started by hand is no tick');
        $s->heartbeat(['run' => null, 'status' => 'idle']);
        self::assertSame('ok', $s->cronState(null, $now));
        self::assertSame('ok', $s->cronState(null, $now + Settings::TICK_STALE));
        self::assertSame('stopped', $s->cronState(null, $now + Settings::TICK_STALE + 2));
        // A tick busy with a session writes nothing until it ends: the session in its time keeps the cron alive, until
        // the reaper would fail it.
        $late = $now + Settings::TICK_STALE + 600;
        self::assertSame('ok', $s->cronState(['deadline_at' => $late - 60], $late));
        self::assertSame('ok', $s->cronState(['deadline_at' => $late - \Psc\News\Runs::REAP_AFTER], $late));
        self::assertSame('stopped', $s->cronState(['deadline_at' => $late - \Psc\News\Runs::REAP_AFTER - 1], $late));
        self::assertSame(\Psc\Controllers\SeoController::NEWS_STALE_SECONDS, Settings::TICK_STALE, '/health and the desk agree');
    }

    public function testTheValidationContextAndTheKey(): void
    {
        $this->settings()->save(['banned_domains' => 'spam.example', 'banned_topics' => 'gossip', 'avoid_phrases' => 'delve'], null);
        $ctx = $this->settings()->context('ai', 'publish', 7);
        self::assertSame(['ai', 'publish', ['spam.example'], ['gossip'], ['delve'], 7, 'https://patternsshowcontrol.test'], [$ctx->origin, $ctx->mode, $ctx->bannedDomains, $ctx->bannedTopics, $ctx->avoidPhrases, $ctx->editingId, $ctx->baseUrl]);
        self::assertSame('', Settings::apiKey($this->app));
        self::rmrf($this->storage);
        $this->bootApp(['chat' => ['api_key' => 'sk-chat']]);
        self::assertSame('sk-chat', Settings::apiKey($this->app), 'the assistant\'s key when the desk has none');
        self::rmrf($this->storage);
        $this->bootApp(['chat' => ['api_key' => 'sk-chat'], 'news' => ['api_key' => 'sk-news']]);
        self::assertSame('sk-news', Settings::apiKey($this->app));
    }
}
