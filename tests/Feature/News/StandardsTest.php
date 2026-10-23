<?php

declare(strict_types=1);

namespace Psc\Tests\Feature\News;

use Psc\Content\Content;
use Psc\Controllers\PageController;
use Psc\Controllers\SeoController;
use Psc\Db\Database;
use Psc\News\ArticleStore;
use Psc\News\Paths;
use Psc\News\Settings;
use Psc\News\Validator;
use Psc\Tests\TestCase;

/**
 * What Industry Updates says about itself and how the site introduces itself to search engines (docs/NEWS.md §5,
 * §10, §11): the editorial standards page and every way to it, the dated corrections an administrator adds to a live
 * article (shown under its headline, followed by its dateModified and its sitemap's lastmod), the ownership tokens on
 * the home page, the organisation's profiles in its structured data, and robots.txt's two switches (training
 * crawlers, AI search and answer engines), as the owner ships them: training crawlers asked to stay away, AI search
 * and answer engines welcome.
 */
final class StandardsTest extends TestCase
{
    private const BASE = 'https://patternsshowcontrol.test';

    /** @return list<array<string, mixed>> */
    private static function graph(string $body): array
    {
        self::assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $body, $m));
        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR)['@graph'];
    }

    /** @return array<string, mixed> */
    private static function node(string $body, string $type): array
    {
        $nodes = array_values(array_filter(self::graph($body), static fn (array $n): bool => ($n['@type'] ?? '') === $type));
        self::assertNotSame([], $nodes, $type);
        return $nodes[0];
    }

    // ------------------------------------------------------------------------------------- the standards page

    public function testTheStandardsPageSaysHowTheSectionIsMadeAndEveryWayLeadsToIt(): void
    {
        $match = $this->app->router->match('GET', '/news/standards/');
        self::assertSame([PageController::class, 'show'], $match['route']['handler'] ?? null, 'its own route, before an article could take the address');
        self::assertSame(1, count(array_keys($this->app->router->staticGetPaths(), '/news/standards/', true)));
        self::assertTrue(Paths::isReserved('standards'));
        $id = $this->makeArticle(['slug' => 'standards']);
        self::assertSame('standards-2', (new ArticleStore($this->app))->find($id)['slug'], 'no article takes the word');

        $r = $this->request('GET', '/news/standards/');
        self::assertSame(200, $r->status);
        self::assertStringStartsWith('public', (string) $r->getHeader('Cache-Control'));
        self::assertSame([], $r->cookies());
        self::assertStringContainsString('<h1 class="c-hero-title" id="page-title">Editorial standards</h1>', $r->body);
        self::assertStringContainsString('<title>Editorial standards | Industry Updates</title>', $r->body);
        foreach (['how', 'editor', 'errors', 'corrections', 'publisher'] as $section) {
            self::assertStringContainsString('<section class="news-part" id="' . $section . '"', $r->body, $section);
            self::assertStringContainsString('<a href="#' . $section . '">', $r->body, 'the contents lead to ' . $section);
        }
        self::assertStringContainsString('Industry Updates is edited by Ben Rohleder, who answers for everything published here.', $r->body, 'a named person, the site\'s publisher');
        self::assertStringContainsString('<a href="/contact/?topic=other">tell us through the contact form</a>', $r->body);
        self::assertStringContainsString('Before an article from the desk goes live, a second, separate check reads all of it. Anything that names a private person or may allege wrongdoing is held back for the editor, and anything on a topic we do not cover is dropped.', $r->body, 'what the check holds and what it drops');
        self::assertStringContainsString('Articles that pass every check are published automatically.', $r->body, 'it says plainly that no person reads them first');
        self::assertStringContainsString('Patterns Show Control is run by Ben Rohleder in the United Kingdom.', $r->body);
        $crumbs = self::node($r->body, 'BreadcrumbList')['itemListElement'];
        self::assertSame(['Home', 'Industry Updates', 'Editorial standards'], array_column($crumbs, 'name'));
        self::assertSame('<li><a href="/news/standards/">Editorial standards</a></li>', trim((string) preg_replace('/\s+/', ' ', (string) (preg_match('#<li><a href="/news/standards/">Editorial standards</a></li>#', $this->request('GET', '/')->body, $f) === 1 ? $f[0] : ''))), 'the footer, on every page');

        self::assertStringContainsString('<loc>' . self::BASE . '/news/standards/</loc>', $this->request('GET', '/sitemap.xml')->body);
        self::assertStringContainsString('- [Editorial standards](' . self::BASE . '/news/standards/): ', $this->request('GET', '/llms.txt')->body);
        self::assertStringContainsString('<a href="/news/standards/">How it is made and corrected</a>', $this->request('GET', '/about/')->body);
        $ai = $this->makeArticle(['origin' => 'ai']);
        $note = $this->request('GET', Paths::article((array) (new ArticleStore($this->app))->find($ai)))->body;
        self::assertSame(1, preg_match('#<div class="c-callout news-ainote" role="note">.*?<a href="/news/standards/">How Industry Updates is made</a>#s', $note), 'the AI note leads to it');
        $manual = $this->request('GET', Paths::article((array) (new ArticleStore($this->app))->find($id)))->body;
        self::assertStringNotContainsString('news-ainote', $manual);
    }

    public function testTheStandardsPageSaysWhatHappensAsTheDeskIsSet(): void
    {
        // With "Publish articles automatically" unticked, the page never says the articles publish themselves.
        self::assertSame([], (new Settings($this->app))->save(['auto_publish' => false], null)['errors']);
        $off = $this->request('GET', '/news/standards/')->body;
        self::assertStringContainsString('Articles that pass every check then wait for the editor to publish them.', $off);
        self::assertStringNotContainsString('published automatically', $off);

        // With the settings unreadable the page still answers, with the words that are true either way.
        $this->db = Database::connect('sqlite::memory:');
        $r = $this->request('GET', '/news/standards/');
        self::assertSame(200, $r->status);
        self::assertStringContainsString('Articles that pass every check are published automatically, unless the editor has chosen to publish each one by hand.', $r->body);
    }

    // ------------------------------------------------------------------------------------------- corrections

    /** @return array<string, mixed> the editor's form for a live article, as an administrator sends it */
    private static function form(array $over = []): array
    {
        return $over + [
            'title' => 'LED processors gain a frame sync mode for mixed walls',
            'seo_title' => '',
            'summary' => '',
            'dek' => 'A firmware update locks processors to each other without genlock, which matters for anyone running mixed walls at corporate events.',
            'body' => str_repeat('The update changes how processors lock to each other on a mixed wall. ', 6),
            'confirmed' => [['text' => 'The firmware is out now.', 'url' => 'https://maker.example/news/firmware']],
            'impact' => 'Test it before your next mixed wall.',
            'verdict' => 'test',
            'verdict_note' => 'Try it on a spare processor first.',
            'categories' => ['video'],
            'tags' => 'led, processing',
            'sources' => [['url' => 'https://maker.example/news/firmware', 'title' => 'Firmware 9 notes', 'publisher' => 'Maker', 'kind' => 'primary']],
            'byline' => 'Ben Rohleder',
            'comments_open' => '1',
        ];
    }

    public function testAnAdministratorCorrectsALiveArticleAndItsDatesFollow(): void
    {
        $this->signInAdmin();
        self::assertSame(303, $this->post('/portal/admin/news/', self::form(['action' => 'publish']))->status);
        $id = (int) $this->db->value('SELECT MAX(id) FROM news_articles');
        $edit = $this->request('GET', '/portal/admin/news/' . $id . '/')->body;
        self::assertStringContainsString('<legend>Corrections</legend>', $edit, 'a live article takes corrections');
        $this->post('/portal/admin/news/', self::form(['action' => 'save', 'title' => 'A draft that has never been live at all']));
        self::assertStringNotContainsString('<legend>Corrections</legend>', $this->request('GET', '/portal/admin/news/' . ($id + 1) . '/')->body, 'a draft has nothing to correct');

        $this->db->exec('UPDATE news_articles SET published_at = ?, first_live_at = ?, created_at = ?, updated_at = ? WHERE id = ?', [time() - 7200, time() - 7200, time() - 7200, time() - 7200, $id]);
        $line = 'An earlier version gave the firmware as 4.2; it is 4.3.';
        $before = time();
        $r = $this->post('/portal/admin/news/' . $id . '/', self::form(['action' => 'save', 'correction_new' => $line]));
        self::assertSame(303, $r->status);
        $row = $this->db->one('SELECT corrections, corrected_at FROM news_articles WHERE id = ?', [$id]);
        $list = json_decode((string) $row['corrections'], true);
        self::assertCount(1, $list);
        self::assertSame($line, $list[0]['text']);
        self::assertGreaterThanOrEqual($before, $list[0]['at']);
        self::assertSame($list[0]['at'], (int) $row['corrected_at']);
        $audit = $this->db->value("SELECT meta FROM audit_log WHERE action = 'news.updated' ORDER BY id DESC LIMIT 1");
        self::assertSame(['corrections'], json_decode((string) $audit, true)['fields']);

        // The article says so under its headline, with the date and the way to the policy; its dates follow.
        $article = (new ArticleStore($this->app))->find($id);
        $page = $this->request('GET', Paths::article((array) $article))->body;
        self::assertSame(1, preg_match('#<div class="c-callout c-callout--info news-corrections" id="corrections" role="note">.*?<strong class="c-callout-title">Correction</strong>.*?<time datetime="([^"]+)">([^<]+)</time>: ' . preg_quote(e($line), '#') . '</li>.*?<a href="/news/standards/\#corrections">How we correct articles</a>#s', $page, $m));
        self::assertSame([date(DATE_ATOM, $list[0]['at']), date('j F Y', $list[0]['at'])], [$m[1], $m[2]]);
        self::assertLessThan(strpos($page, 'news-body'), strpos($page, 'news-corrections'), 'under the headline, before the story');
        self::assertSame(date(DATE_ATOM, $list[0]['at']), self::node($page, 'NewsArticle')['dateModified']);
        $month = (new \DateTimeImmutable('@' . (int) $article['published_at']))->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m');
        self::assertStringContainsString('<lastmod>' . gmdate('Y-m-d\TH:i:s\Z', $list[0]['at']) . '</lastmod>', $this->request('GET', '/news/sitemap-' . $month . '.xml')->body);
        self::assertSame($list[0]['at'], ArticleStore::lastChange((array) $article));

        // An earlier line keeps its date when its words are changed, and goes when it is emptied.
        $this->post('/portal/admin/news/' . $id . '/', self::form(['action' => 'save', 'corrections' => [['text' => 'An earlier version gave the firmware as 4.2; the release is 4.3.']]]));
        $kept = json_decode((string) $this->db->value('SELECT corrections FROM news_articles WHERE id = ?', [$id]), true);
        self::assertSame([['at' => $list[0]['at'], 'text' => 'An earlier version gave the firmware as 4.2; the release is 4.3.']], $kept);
        $this->post('/portal/admin/news/' . $id . '/', self::form(['action' => 'save', 'corrections' => [['text' => '']]]));
        self::assertSame([null, null], array_values((array) $this->db->one('SELECT corrections, corrected_at FROM news_articles WHERE id = ?', [$id])));
        self::assertStringNotContainsString('news-corrections', $this->request('GET', Paths::article((array) $article))->body);
    }

    public function testACorrectionSoonAfterGoingLiveShowsAsAnUpdate(): void
    {
        $this->signInAdmin();
        $this->post('/portal/admin/news/', self::form(['action' => 'publish']));
        $id = (int) $this->db->value('SELECT MAX(id) FROM news_articles');
        // Two minutes live: a save alone is not yet an update (ArticleStore::UPDATED_AFTER), a correction is.
        $live = time() - 120;
        $this->db->exec('UPDATE news_articles SET published_at = ?, first_live_at = ?, created_at = ?, updated_at = ? WHERE id = ?', [$live, $live, $live, $live, $id]);
        $page = $this->request('GET', Paths::article((array) (new ArticleStore($this->app))->find($id)))->body;
        self::assertStringNotContainsString('Updated <time', $page);
        $this->post('/portal/admin/news/' . $id . '/', self::form(['action' => 'save', 'correction_new' => 'An earlier version gave the firmware as 4.2; it is 4.3.']));
        $article = (array) (new ArticleStore($this->app))->find($id);
        self::assertNull(ArticleStore::updatedAfterLive($article));
        $at = (int) $article['corrected_at'];
        self::assertSame($at, ArticleStore::lastChange($article));
        $page = $this->request('GET', Paths::article($article))->body;
        self::assertStringContainsString('Updated <time datetime="' . date(DATE_ATOM, $at) . '">', $page, 'the meta line says what dateModified says');
        self::assertSame(date(DATE_ATOM, $at), self::node($page, 'NewsArticle')['dateModified']);
    }

    public function testACorrectionKeepsToOneLineAndNamesNoEmail(): void
    {
        $this->signInAdmin();
        $this->post('/portal/admin/news/', self::form(['action' => 'publish']));
        $id = (int) $this->db->value('SELECT MAX(id) FROM news_articles');
        $r = $this->post('/portal/admin/news/' . $id . '/', self::form(['action' => 'save', 'correction_new' => str_repeat('a', Validator::CORRECTION_MAX + 1)]));
        self::assertSame(303, $r->status);
        self::assertSame(Validator::CORRECTION, $_SESSION['_errors']['correction_new'] ?? null, 'the error names the field it was typed in');
        self::assertNull($this->db->value('SELECT corrections FROM news_articles WHERE id = ?', [$id]));
        $this->request('GET', '/portal/admin/news/' . $id . '/');
        $this->post('/portal/admin/news/' . $id . '/', self::form(['action' => 'save', 'correction_new' => 'Write to editor@example.com with corrections.']));
        self::assertSame(Validator::CONTACT, $_SESSION['_errors']['correction_new'] ?? null);
        self::assertNull($this->db->value('SELECT corrections FROM news_articles WHERE id = ?', [$id]));
    }

    // --------------------------------------------------------------------------- verification and profiles

    public function testOwnershipTokensAreOnTheHomePageOnly(): void
    {
        self::assertStringNotContainsString('site-verification', $this->request('GET', '/')->body, 'none until they are set');
        $this->bootApp(['seo' => [
            'google_site_verification' => 'rXOxyZounnZasA8Z7oaD3c14JdjS9aKSWvsR1EbUSIQ',
            // Pasted as the whole tag: its content is used.
            'bing_site_verification' => '<meta name="msvalidate.01" content="1A2B3C4D5E6F7A8B9C0D1E2F3A4B5C6D" />',
        ]]);
        $home = $this->request('GET', '/')->body;
        self::assertStringContainsString('<meta name="google-site-verification" content="rXOxyZounnZasA8Z7oaD3c14JdjS9aKSWvsR1EbUSIQ">', $home);
        self::assertStringContainsString('<meta name="msvalidate.01" content="1A2B3C4D5E6F7A8B9C0D1E2F3A4B5C6D">', $home);
        self::assertLessThan(strpos($home, '</head>'), strpos($home, 'msvalidate.01'));
        foreach (['/about/', '/news/', '/patterns/'] as $path) {
            self::assertStringNotContainsString('site-verification', $this->request('GET', $path)->body, $path);
        }
        $this->bootApp(['seo' => ['google_site_verification' => 'not a "token" <script>']]);
        self::assertStringNotContainsString('google-site-verification', $this->request('GET', '/')->body, 'anything but a token is left out');
    }

    public function testTheOrganisationNamesItsOwnProfiles(): void
    {
        $org = self::node($this->request('GET', '/')->body, 'Organization');
        self::assertArrayNotHasKey('sameAs', $org, 'none until the owner lists them');
        Content::override('site', array_replace(Content::get('site'), ['same_as' => [
            'https://www.linkedin.com/company/patterns-show-control',
            'http://insecure.example/profile',
            'javascript:alert(1)',
            'https://www.youtube.com/@patternsshowcontrol',
        ]]));
        foreach (['/', '/news/'] as $path) {
            $org = self::node($this->request('GET', $path)->body, 'Organization');
            self::assertSame(['https://www.linkedin.com/company/patterns-show-control', 'https://www.youtube.com/@patternsshowcontrol'], $org['sameAs'], $path . ': https profiles only');
        }
    }

    // ------------------------------------------------------------------------------------------------ robots

    /** @return list<array{agents: list<string>, rules: list<string>}> robots.txt's groups */
    private function robots(): array
    {
        $groups = [];
        foreach (preg_split('/\n\n+/', trim($this->request('GET', '/robots.txt')->body)) ?: [] as $block) {
            $lines = explode("\n", $block);
            $agents = array_values(array_map(static fn (string $l): string => substr($l, 12), array_filter($lines, static fn (string $l): bool => str_starts_with($l, 'User-agent: '))));
            if ($agents !== []) {
                $groups[] = ['agents' => $agents, 'rules' => array_values(array_filter($lines, static fn (string $l): bool => !str_starts_with($l, 'User-agent: ')))];
            }
        }
        return $groups;
    }

    public function testRobotsBlocksTrainingByDefaultAndSplitsItFromAiSearch(): void
    {
        // The owner's policy as shipped (content/site.php): every crawler reads the open group, everything but the API
        // and the tracked links; the training crawlers are asked to stay away; the answer engines are not.
        $site = Content::get('site');
        self::assertSame([true, true], [$site['block_training'] ?? null, $site['ai_search'] ?? null]);
        $groups = $this->robots();
        self::assertSame([['*'], SeoController::TRAINING_AGENTS], array_column($groups, 'agents'));
        self::assertSame(['Allow: /', 'Disallow: /api/', 'Disallow: /news/go/'], $groups[0]['rules']);
        self::assertSame(['Disallow: /'], $groups[1]['rules']);
        self::assertSame(['GPTBot', 'ClaudeBot', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'meta-externalagent', 'Bytespider'], SeoController::TRAINING_AGENTS);
        foreach (['Googlebot', 'Bingbot', 'Applebot', 'OAI-SearchBot', 'Claude-SearchBot', 'PerplexityBot'] as $welcome) {
            self::assertNotContains($welcome, $groups[1]['agents'], $welcome . ' still reads the open group');
        }
        self::assertStringEndsWith("\n\nSitemap: " . self::BASE . "/sitemap.xml\nSitemap: " . self::BASE . "/news/sitemap.xml\n", $this->request('GET', '/robots.txt')->body);

        // With both switches open, every crawler reads the same group.
        Content::override('site', array_replace($site, ['block_training' => false, 'ai_search' => true]));
        self::assertSame("User-agent: *\nAllow: /\nDisallow: /api/\nDisallow: /news/go/\n\nSitemap: " . self::BASE . "/sitemap.xml\nSitemap: " . self::BASE . "/news/sitemap.xml\n", $this->request('GET', '/robots.txt')->body);

        // The answer engines can be asked to stay away on their own.
        Content::override('site', array_replace(Content::get('site'), ['block_training' => false, 'ai_search' => false]));
        $groups = $this->robots();
        self::assertSame([['*'], SeoController::AI_SEARCH_AGENTS], array_column($groups, 'agents'));
        self::assertSame(['OAI-SearchBot', 'ChatGPT-User', 'Claude-SearchBot', 'Claude-User', 'PerplexityBot', 'Perplexity-User', 'meta-webindexer', 'meta-externalfetcher'], SeoController::AI_SEARCH_AGENTS);
        self::assertSame([], array_intersect(SeoController::TRAINING_AGENTS, SeoController::AI_SEARCH_AGENTS), 'two separate switches');

        Content::override('site', array_replace(Content::get('site'), ['block_training' => true, 'ai_search' => false]));
        self::assertSame([['*'], [...SeoController::TRAINING_AGENTS, ...SeoController::AI_SEARCH_AGENTS]], array_column($this->robots(), 'agents'));
        self::assertStringContainsString('Sitemap: ' . self::BASE . '/news/sitemap.xml', $this->request('GET', '/robots.txt')->body);
    }
}
