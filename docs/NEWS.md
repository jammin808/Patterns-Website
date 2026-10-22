# Industry Updates: the news section

The owner asked for a news section of AV and live-event industry news. It has:
- category filters;
- an admin area for writing, editing and deleting articles, with images;
- tracked links out to the original sources;
- comments from registered users;
- an AI desk that researches and writes 1 to 3 articles in each scheduled session, by default every morning around 06:00 and every afternoon around 14:30.

This file is the design and the contract the build follows. `docs/DEPLOY.md` covers the operator's side (the cron line and the key) and `content/news/` holds the default prompt texts.

## 1. What the owner asked for

These are the owner's words, kept because the defaults and fences come from them.

- **The section.** "Industry Updates", a news/blog section for the latest relevant AV and event industry news. It filters by industry: Video, Audio, Lighting, Staging, Production, Showcalling, Technology, Interactive and so on.
- **Who writes.** Most articles are researched and written automatically. An admin area adds, edits and deletes articles by hand. Articles can include related images and carry tracked links out to the original sources.
- **The sessions.** Every morning around 6am and every afternoon around 2:30pm, research consequential developments in the AV industry, corporate live events and live streaming. That especially means, but is not limited to:
  - video switching
  - LED screens and processing
  - show and event automation
  - AI in events
  - freelancer information
  - live event workflows
  - immersive events
  - streaming
  - related legal aspects
  - advice, tips and tricks
- **No duplicates.** A follow-up says so and links to the earlier article or articles.
- **The article structure.**
  - Verified product or standards changes are kept apart from speculation.
  - Each development is explained, with how it applies, if it does, to freelance and corporate event delivery.
  - Anything worth testing or adopting is highlighted.
- **The amount.** 1 to 3 articles in the morning and 1 to 3 in the afternoon. A slow news session can be skipped. Within a session the articles go live randomly 10 to 30 minutes apart.
- **Comments.** Registered users can comment.
- **The voice.**
  - Friendly and professional, not stuck up. It can be casual and witty, with dry UK banter and humour, but not over the top.
  - An engaging read with personality.
  - It breaks up polished AI rhythms, so it reads like a person who works in the industry, is still passionate about it, has lots of experience, and is still interested in new developments and whether they help.
  - **Never use em dashes.**
- **The admin controls.** An admin can:
  - pause and continue research and writing;
  - change the number of articles per session;
  - change the number of sessions per day or week;
  - enable or disable calendar days;
  - edit the prompt;
  - add banned websites and banned topics.

## 2. Decisions

1. **Where it lives.**
   - The index is `/news/`. Its title is "Industry Updates", with the label "Industry Updates" in the header nav and the footer.
   - Articles are at `/news/{slug}/` and categories at `/news/category/{key}/`.
   - The feed is `/news/feed.xml` (RSS 2.0).
   - Outbound links go through `/news/go/{id}/`, and images are served from `/news/media/{file}`.
2. **Who writes.** The site's own AI desk writes the articles: `tools/news.php`, run by a Hostinger cron every 15 minutes, using the Anthropic API with the web search and web fetch server tools.
   - It uses `news.api_key`, falling back to `chat.api_key`, so one key serves both.
   - Articles can also arrive from the repository as JSON files in `content/news/articles/`. They are imported once each, which is how the launch articles and any article written in a Claude Code session get published.
   - Both routes, and the admin editor, go through the same validator and the same store.
3. **The database, not the content registry.** Articles, comments, settings and runs live in the database (migration `0004_news`).
   - They are not in `content/*.php`, which `ContentRulesTest` fences: news legitimately says "open-source".
   - They are not in the assistant's knowledge, which would re-bill its cache on every article.
4. **Public pages stay cacheable and session-free.** The article HTML is the same for every visitor.
   - Comments are rendered into it.
   - The comment box is added by `news.js` for signed-in visitors (it reads the `psc_li` hint, then asks `/api/news/…/state`). Without scripting, the box links to a no-JS comment page `/news/{slug}/comment/` (`session`, `auth`, `nostore`).
   - News pages send `public, max-age=120, stale-while-revalidate=600`, not the site's 300 s and one day, so an unpublish or a hidden comment leaves the CDN within minutes.
5. **Two model calls per session.**
   - **Research** has the web tools and writes a plain-text brief, and the server collects every URL the tools returned.
   - **Compose** has no tools and a JSON-schema output, and writes the articles from that brief.
   - Structured output cannot be combined with citations, and forcing a tool call is refused on these models, so two calls is the documented, reliable shape.
   - An optional third call, on Claude Haiku 5.5, checks banned topics.
   - A repair call rewrites an article that trips the voice fence, at most once.
6. **Fences, not hopes.** Each of these is code with a test:
   - every source and every link in an AI article must be a URL the research tools actually returned;
   - banned domains are blocked in the tools and refused in sources;
   - banned topics are refused;
   - duplicates are refused unless declared follow-ups;
   - em dashes are removed from every stored article text, manual articles included;
   - an article cannot be published without at least one source.
7. **Honesty.** AI-written articles carry a short, plain note saying so, editable in the settings. Their byline is the organisation ("Industry Updates desk"), never a person. The JSON-LD `author` is the Organization.
8. **Images.**
   - Admins upload images. Each is re-encoded by GD (which strips metadata and anything hiding in the file) to WebP at widths of 480, 960 and 1600, plus a 1200×630 JPEG for sharing.
   - Images are stored under `storage/uploads/news/` and served by PHP with year-long immutable caching.
   - Alt text is required. A caption, a credit and a credit link are optional.
   - The AI desk never copies images: an article without one shows its category's own pattern artwork (an inline SVG, our own work).
9. **The model.**
   - Research and compose use `claude-opus-5-5` by default. The admin can switch either step to `claude-sonnet-5-5`. Thinking is adaptive, effort is high, and the stream is accumulated, with Guzzle timeouts on a transporter of our own.
   - A monthly spend cap (default 60 USD, estimated from usage and the price table in `Psc\News\Pricing`) stops new sessions once it is reached.
   - Each session caps its web searches (default 12) and fetches (default 10).

## 3. Data (migration `0004_news`, MySQL and SQLite)

All times are Unix seconds (INT). The MySQL tables follow the 0001 conventions (InnoDB, utf8mb4_unicode_ci, `INT UNSIGNED` ids, `KEY t_x`, `CONSTRAINT fk_t_ref`). The SQLite tables follow their own (`INTEGER PRIMARY KEY AUTOINCREMENT`, separate `CREATE INDEX` with the same names). Write `CREATE TABLE name`, never `IF NOT EXISTS`, because MigratorTest compares table names.

| Table | Columns | Keys |
|---|---|---|
| `news_articles` | `id`, `slug VARCHAR(190)`, `title VARCHAR(190)`, `dek VARCHAR(320)`, `body TEXT`, `confirmed TEXT NULL` (JSON list of `{text, url}`), `speculation TEXT NULL` (JSON list of `{text, url}`), `impact TEXT NULL` (Markdown), `verdict VARCHAR(16) NULL` (`adopt\|test\|watch\|skip\|note`), `verdict_note VARCHAR(320) NULL`, `tags VARCHAR(320) NULL` (comma-separated, lower case), `status VARCHAR(16) NOT NULL DEFAULT 'draft'` (`draft\|published`), `published_at INT NULL` (a future time means scheduled), `origin VARCHAR(16) NOT NULL DEFAULT 'manual'` (`manual\|ai\|import`), `story_key VARCHAR(190) NULL`, `comments_open TINYINT(1) NOT NULL DEFAULT 1`, `comment_count INT NOT NULL DEFAULT 0`, `word_count INT NOT NULL DEFAULT 0`, `author_id INT UNSIGNED NULL` (no FK), `run_id INT UNSIGNED NULL` (no FK), `created_at`, `updated_at` | UNIQUE `news_articles_slug (slug)`, `news_articles_live (status, published_at)`, `news_articles_story (story_key)` |
| `news_article_categories` | `article_id` FK→articles CASCADE, `category VARCHAR(32)` | PK `(article_id, category)`, `news_article_categories_cat (category, article_id)` |
| `news_links` | `id`, `article_id` FK CASCADE, `url VARCHAR(2000)`, `url_key CHAR(64)` (sha256 of the canonical URL), `title VARCHAR(300) NULL`, `publisher VARCHAR(160) NULL`, `kind VARCHAR(16) NOT NULL DEFAULT 'other'` (`primary\|press\|report\|standard\|other`), `is_source TINYINT(1) NOT NULL DEFAULT 1`, `position INT NOT NULL DEFAULT 0`, `clicks INT NOT NULL DEFAULT 0`, `created_at` | `news_links_article (article_id, position)`, `news_links_url (url_key)` |
| `news_link_clicks` | `link_id` FK→links CASCADE, `day CHAR(10)` (`Y-m-d`, London), `clicks INT NOT NULL DEFAULT 0` | PK `(link_id, day)` |
| `news_related` | `article_id` FK CASCADE (the later article), `related_id` FK→articles CASCADE (the earlier one) | PK `(article_id, related_id)`, `news_related_back (related_id)` |
| `news_images` | `id`, `article_id` FK CASCADE NULL, `position INT NOT NULL DEFAULT 0` (0 is the lead image), `base VARCHAR(40)` (32 hex), `width INT`, `height INT` (of the largest variant), `alt VARCHAR(300)`, `caption VARCHAR(300) NULL`, `credit VARCHAR(160) NULL`, `credit_url VARCHAR(500) NULL`, `original_name VARCHAR(190)`, `bytes INT`, `created_by INT UNSIGNED NULL`, `created_at` | `news_images_article (article_id, position)` |
| `news_comments` | `id`, `article_id` FK CASCADE, `user_id` FK→users CASCADE, `body TEXT`, `status VARCHAR(16) NOT NULL DEFAULT 'visible'` (`visible\|pending\|hidden`), `reports INT NOT NULL DEFAULT 0`, `ip_hash CHAR(64)`, `created_at`, `updated_at` | `news_comments_article (article_id, status, created_at)`, `news_comments_user (user_id)`, `news_comments_status (status, reports)` |
| `news_comment_reports` | `comment_id` FK CASCADE, `user_id` FK→users CASCADE, `created_at` | PK `(comment_id, user_id)` |
| `news_runs` | `id`, `slot VARCHAR(40)` (`2026-10-22 06:00`, or `manual-<unix>-<rand>`), `kind VARCHAR(16)` (`scheduled\|manual`), `status VARCHAR(16)` (`queued\|running\|done\|skipped\|failed`), `requested_by INT UNSIGNED NULL`, `started_at INT NULL`, `finished_at INT NULL`, `candidates INT NOT NULL DEFAULT 0`, `articles INT NOT NULL DEFAULT 0`, `usage_in`, `usage_out`, `cache_write`, `cache_read`, `searches`, `fetches` (INT NOT NULL DEFAULT 0 each), `cost_micros INT NOT NULL DEFAULT 0` (estimated USD × 10⁶), `models VARCHAR(160) NULL`, `summary VARCHAR(500) NULL`, `log TEXT NULL` (JSON list of steps), `created_at` | UNIQUE `news_runs_slot (slot)`, `news_runs_status (status, created_at)` |
| `news_settings` | `name VARCHAR(64)` PK, `value TEXT` (JSON), `updated_by INT UNSIGNED NULL`, `updated_at INT` | |
| `news_prompt_versions` | `id`, `field VARCHAR(16)` (`brief\|voice`), `body TEXT`, `created_by INT UNSIGNED NULL`, `created_at` | `news_prompt_versions_field (field, created_at)` |
| `news_imports` | `import_key VARCHAR(190)` PK, `article_id INT UNSIGNED NULL`, `imported_at INT` | |

**Notes**
- `news_imports` rows are never deleted. An imported article deleted in the admin is never brought back.
- Comments are deleted with their article (CASCADE) and with the account. `AccountController::delete` hard-deletes the person's news comments before anonymising the account, and `export` includes them.
- **Housekeeping additions:**
  - `news_link_clicks` older than 400 days;
  - `news_runs` older than 180 days (their `log`);
  - `news_comment_reports` for comments that are already hidden and older than 400 days;
  - `news_images` rows with `article_id` NULL (orphans) older than one day, with their files.

## 4. Code layout (`Psc\News\`)

| Class | Responsibility |
|---|---|
| `Categories` | Reads `content/news.php` `categories`. `all(): array<key, {name, short, description, icon}>`, `has(string)`, `name(string)`. Fixed keys: `video audio lighting staging production showcalling streaming automation interactive ai technology freelance legal tips` |
| `Prose` | Static text rules. `undash(string): string` (em dash U+2014 and en dash U+2013 used as a dash become a comma; an en dash between digits becomes a hyphen; tidies `, ,`, ` ,`, `,.`). `countDashes()`. `tells(string, list<string> $phrases): list<string>` (case-insensitive whole-phrase matches). `words(string): int`. `readMinutes(int)`. `slug(string $title): string` (ASCII via intl Transliterator or iconv, lower case, `[a-z0-9-]`, at most 80 characters, cut on a hyphen). `plain(string $md): string` (Markdown to plain text for feeds and descriptions) |
| `Urls` | `canonical(string): ?string` (http/https only; lower-case host; drops `www.`, the fragment, `utm_*`, `fbclid`, `gclid`, `mc_*`, `ref` and the default port; keeps the path and the other query parameters; trims the trailing slash except at the root). `key(string): string` (sha256 of the canonical form). `host(string): string`. `onDomain(string $url, list<string> $domains): bool` (the host equals a domain or ends with `.` plus the domain). `normaliseDomain(string): ?string` (accepts `https://www.x.com/path` or `x.com` and returns `x.com`) |
| `Draft` | An article being created or changed, with typed fields: `title dek body confirmed[] speculation[] impact verdict verdictNote categories[] tags[] sources[] followUps[] storyKey origin status publishAt commentsOpen`. `fromArray(array $data, string $origin): self` reads the JSON shape in §6. `toArray()` |
| `Validator` | `check(Draft, Context): Verdict` with `errors: array<field, message>` (admin wording) and `rejections: list<{rule, detail}>` (for runs). The context says who is writing (`manual\|ai\|import`), gives the URLs the research saw (AI only), the banned domains and topics, the recent articles for dedup, and the article id being edited. The rules are in §7 |
| `ArticleStore` | The only writer of the article tables.<br>Writing: `create(Draft, ?int $userId, ?int $runId): int`, `update(int $id, Draft, ?int $userId): array $changedFields`, `publish(int $id, ?int $at)`, `unpublish(int $id)`, `delete(int $id): ?array` (returns the row for the audit, removes the image files).<br>Reading: `find(int)`, `findBySlug(string, bool $live = true)`, `live(?string $category, ?string $q, int $page, int $perPage): Paginator`-like result, `recent(int $days): list` (for dedup and prompts), `categoryCounts(): array`, `links(int $articleId): list`, `related(int $id): {earlier: list, later: list}`, `images(int $id): list`.<br>On every save it applies `Prose::undash` to every text field, registers links (sources, plus every external link in body, impact, confirmed and speculation), computes `word_count`, and makes the slug unique (`-2`, `-3` and so on). "Live" means `status = 'published' AND published_at <= now` |
| `Markdown` | `render(string $md, array $linkMap): string`. It runs `md()`, rewrites each external `href` present in `$linkMap` (canonical key to link id) to `/news/go/{id}/`, and shifts headings so the article body starts at h2 and never jumps a level. `inline(string, array $linkMap)` does the same for list items |
| `Images` | `store(array $upload, ?int $articleId, array $meta, ?int $userId): array` (GD decode; refuse above 40 megapixels or 10 MB; write `-480.webp`, `-960.webp`, `-1600.webp` with no upscaling, so smaller images get fewer variants, and `-og.jpg` at 1200×630 cover-cropped). `srcset(array $image): string`. `delete(array $image)`. `path(string $file): ?string` (validates `^[a-f0-9]{32}-(480\|960\|1600)\.webp$` or `^[a-f0-9]{32}-og\.jpg$`) |
| `Comments` | `post(int $articleId, array $user, string $body): array{ok, comment?, errors?, status?}` (rules in §9). `remove(int $commentId, array $actor): bool`. `report(int $commentId, array $user): bool`. `visible(int $articleId): list`. `forUser(int $userId): list` (for the export) |
| `Settings` | DB-held settings with defaults (§8). `get(string)`, `all()`, `save(array $values, ?int $userId): array $changed` (validated), `brief()` and `voice()` (DB value or the default from `content/news/brief.md` and `voice.md`), `savePrompt(field, text, userId)` (adds a version), `resetPrompt(field, userId)` |
| `Schedule` | `due(\DateTimeImmutable $now, Settings): ?string` returns the slot key due now: London time at or after the slot time and before slot time plus 2 hours, the weekday on or the date overridden on, not overridden off, settings enabled. `upcoming(\DateTimeImmutable $from, Settings, int $days): list<{date, time, slot, on, why}>`. `perWeek(Settings): int` |
| `Runs` | `claim(string $slot, string $kind, ?int $userId): ?int` (INSERT into the unique slot, so only one process wins). `queueManual(?int $userId): int`. `nextQueued(): ?array`. `start`, `finish(int $id, string $status, array $report)`. `reapStale(int $now)` (a run still `running` after 2 hours becomes `failed`). `spentThisMonth(): float` (USD, London month). `recent(int)` |
| `Pipeline` | `run(int $runId, NewsModel $model, \DateTimeImmutable $now): array $report`. The research, compose, validate, repair and publish steps of §6, with every step logged to the run |
| `NewsModel` (interface) | The seam tests replace (like `Chat\ModelGateway`).<br>`research(string $system, string $user, array $tools, string $model): array{text, seen: array<url, {title, via}>, usage, stop}`.<br>`compose(string $system, string $user, array $schema, string $model): array{data: ?array, usage, stop}`.<br>`check(string $system, string $user, array $schema): array{data: ?array, usage}` (Haiku, for banned topics). |
| `AnthropicNewsModel` | `NewsModel` on the official PHP SDK, using the beta path with `fallbacks: 'default'`.<br>Its own Guzzle transporter has timeout 900, connect 15, read 300, and `maxRetries` 1. It streams through `MessageAccumulator::forBetaMessages()` and resumes `pause_turn` up to 6 times. It collects URLs from `web_search_tool_result` and `web_fetch_tool_result` blocks by `->type`, and sums usage across continuations, including `serverToolUse->webSearchRequests` and `webFetchRequests` |
| `Pricing` | USD per million tokens and per search for the models in use. `cost(array $usage, string $model): float`. The figures are estimates for the cap, labelled as such in the admin |
| `Importer` | `ensure()`: if the hash of `content/news/articles/*.json` names differs from `storage/cache/news-import`, it imports every file whose `key` is not in `news_imports`, through `Validator` (origin `import`, no seen-URL rule) and `ArticleStore`, staggering their `published_at` 10 to 30 minutes apart from now. A file that fails validation is logged and skipped, and retried on the next change. It is called from `App::db()` after the migrator when `news.import` is true (false in TestCase, true in production and e2e) |

**Other code**
- **Controllers.**
  - `Psc\Controllers\NewsController` handles the public pages, the feed, `go` and `media`.
  - `Psc\Controllers\NewsCommentController` handles the comment API and the no-JS page.
  - `Psc\Controllers\Admin\AdminNewsController` handles articles and images.
  - `AdminNewsCommentController` handles moderation.
  - `AdminNewsDeskController` handles the AI desk: status, settings, calendar, prompt and runs.
- **CLI.** `tools/news.php`:
  - `tick` runs from cron. It reaps stale runs, imports, runs a queued manual run if there is one, or else claims and runs the due slot, then prints a JSON line.
  - `run` runs a session now, from the shell.
  - `import` imports only.
  - It exits with 404 outside the CLI, as `cron.php` does.

## 5. Routes

These are declared before the registry loop, which skips `/news/`. They are listed in the order declared.

```
GET  /news/                                   NewsController::index           (no middleware; ?q= ?page=)
GET  /news/feed.xml                           NewsController::feed
GET  /news/category/{key:[a-z]+}/             NewsController::category        (?page=)
GET  /news/go/{id:\d+}/                       NewsController::go              ['nostore'] 302, counts the click
GET  /news/media/{file:[a-f0-9]{32}-[a-z0-9]+\.(?:webp|jpg)}   NewsController::media
GET  /news/{slug:[a-z0-9-]+}/comment/         NewsCommentController::page     ['session','auth','nostore']
POST /news/{slug:[a-z0-9-]+}/comment/         NewsCommentController::submit   ['session','csrf','auth','nostore']
GET  /news/{slug:[a-z0-9-]+}/                 NewsController::show
GET  /api/news/{id:\d+}/state                 NewsCommentController::state    ['api','session','nostore']
POST /api/news/{id:\d+}/comments              NewsCommentController::create   ['api','session','auth','nostore']
POST /api/news/comments/{id:\d+}/delete       NewsCommentController::delete   ['api','session','auth','nostore']
POST /api/news/comments/{id:\d+}/report       NewsCommentController::report   ['api','session','auth','nostore']

GET  /portal/admin/news/                      AdminNewsController::index      $ad   (tabs: all, live, scheduled, drafts; ?category ?origin ?q ?page)
GET  /portal/admin/news/new/                  AdminNewsController::create     $ad
POST /portal/admin/news/                      AdminNewsController::store      $adPost
GET  /portal/admin/news/{id:\d+}/             AdminNewsController::edit       $ad
POST /portal/admin/news/{id:\d+}/             AdminNewsController::update     $adPost (action: save|publish|schedule|unpublish|delete|image_add|image_update|image_delete|image_lead)
GET  /portal/admin/news/{id:\d+}/preview/     AdminNewsController::preview    $ad   (the public template, noindex)
GET  /portal/admin/news/comments/             AdminNewsCommentController::index  $ad (tabs: reported, pending, visible, hidden)
POST /portal/admin/news/comments/{id:\d+}/    AdminNewsCommentController::update $adPost (action: approve|hide|show|delete|clear_reports)
GET  /portal/admin/news/desk/                 AdminNewsDeskController::index     $ad (status, upcoming sessions, spend, recent runs)
POST /portal/admin/news/desk/                 AdminNewsDeskController::control   $adPost (action: pause|resume|run_now)
GET  /portal/admin/news/desk/settings/        AdminNewsDeskController::settings  $ad
POST /portal/admin/news/desk/settings/        AdminNewsDeskController::saveSettings $adPost
GET  /portal/admin/news/desk/calendar/        AdminNewsDeskController::calendar  $ad (?from=YYYY-MM)
POST /portal/admin/news/desk/calendar/        AdminNewsDeskController::saveCalendar $adPost
GET  /portal/admin/news/desk/prompt/          AdminNewsDeskController::prompt    $ad
POST /portal/admin/news/desk/prompt/          AdminNewsDeskController::savePrompt $adPost (action: save_brief|save_voice|reset_brief|reset_voice|restore)
GET  /portal/admin/news/desk/runs/{id:\d+}/   AdminNewsDeskController::run       $ad
```

- `robots.txt` adds `Disallow: /news/go/`.
- The sitemap adds `/news/`, each category page with at least one live article, and each live article (`lastmod` = `updated_at`).
- `llms.txt` adds an "Industry Updates" section with the 10 newest.
- The admin nav adds `['/portal/admin/news/', 'Industry updates']`. The desk and moderation pages sit under that prefix, so the item stays current.
- `AdminController::subjectUrl` adds `article` and `comment` cases.
- Audit actions are `news.created|updated|published|scheduled|unpublished|deleted|image_added|image_deleted`, `comment.hidden|shown|approved|deleted|reports_cleared` and `desk.paused|resumed|run_queued|settings|calendar|prompt`.

## 6. The AI desk

### 6.1 A session

1. **Starting.** `tools/news.php tick` runs every 15 minutes from cron (UTC; the schedule is evaluated in Europe/London).
   - It reaps stale runs and runs the importer.
   - If a manual run is queued, it runs that.
   - Otherwise, if `Schedule::due()` names a slot and `Runs::claim()` wins it, it runs that slot.
   - Nothing runs while the desk is paused, while no API key is set, or once the month's estimated spend has reached the cap. A due slot in any of those states is recorded as `skipped` with the reason, so the admin sees why.
2. **Context.**
   - Articles from the last 120 days (live, scheduled and drafts), each with id, slug, title, date, dek, categories, `story_key` and source URLs, newest first, capped at 60. This is the dedup list and the follow-up list.
   - The banned topics and domains.
   - The categories.
   - `max_per_session` (N) and `min_importance`.
3. **Research** (`research()`, model `research_model`).
   - **System prompt:** the fixed research rules (`Psc\News\Prompts::researchRules()`, not editable), then the admin's brief.
   - **Tools:**
     - `web_search_20260209`, with `max_uses` = `max_searches`, `blocked_domains` = the banned domains, and `user_location` GB/London.
     - `web_fetch_20260209`, with `max_uses` = `max_fetches`, the same `blocked_domains` (domains only), and `max_content_tokens` 12000.
   - **User turn:** today's date and time in London, which session it is, the context list, and the ask: find up to N + 2 candidate developments from the last 7 days (14 for legal and standards), each in the fixed plain-text shape below.
   - **Candidate shape:**
     - headline
     - story key
     - is it a duplicate of an existing article (id), or a follow-up to which ids
     - importance from 1 to 5, and why
     - verified facts, each with its URL
     - speculation, each with its URL
     - who it affects
     - sources
   - **The server collects** every URL in search and fetch results (`seen`), together with the search and fetch counts.
4. **Selection.** The compose call is told to write at most N articles, only from candidates that are not duplicates and score at least `min_importance`. If nothing qualifies, it returns zero articles and a `skipped_reason`, and the run ends as `skipped` ("Slow news" plus the reason). A run with no candidates at all ends the same way.
5. **Compose** (`compose()`, model `compose_model`, no tools).
   - **System prompt:** the fixed compose rules (`Prompts::composeRules()`, with the article structure, the field rules, the categories with their meanings, and the fences in §7 stated as rules), then the admin's voice, then the phrases to avoid.
   - **User turn:** the research brief text, the allowed URL list (`seen`), the context list with slugs (so follow-ups can link `/news/{slug}/`), and N.
   - **Output:** the JSON schema in §6.3.
6. **Validate.** Each article goes through `Validator` with origin `ai` (§7).
   - A rejected article is logged with its rule and dropped.
   - An article whose only problem is phrases to avoid gets one repair call (`compose()` with the repair rules, the article JSON and the offending phrases, which returns the same schema for one article) and is checked again.
   - If those phrases are still there, it is stored as a draft for review, never published.
7. **Banned topics.** If any are set, each surviving article also passes `check()` (Haiku 5.5, schema `{matches: [string]}`, given the topics and the article's title, dek and body). Any match rejects the article. The plain term match in §7 runs first and is free.
8. **Publish.**
   - With `auto_publish` (default on), the articles are stored as `published`. The first gets `published_at` = now; each next one is 10 to 30 minutes after the previous (`random_int(10, 30)` minutes).
   - Otherwise they are stored as drafts for review.
   - The run records its articles, its candidate count, its usage, its estimated cost, a one-line summary and the log.
9. **Failure.**
   - An exception, an API error, a refusal, or a stop on `max_tokens` with unusable JSON ends the run as `failed` with the reason. Nothing is half-published, because articles are written in one transaction at the end.
   - The next slot runs normally. Failed slots are not retried, since a retry would bill the searches again; the admin can press "Run a session now".

### 6.2 The prompt texts

- `content/news/brief.md` holds the default research brief: the owner's topic list and priorities, in the owner's words, tidied.
- `content/news/voice.md` holds the default voice: the owner's voice description plus concrete craft notes. Those cover:
  - sentence length that varies on purpose;
  - no tidy triplets;
  - no summary ending, so it stops when it is done;
  - opinions held by a person who has done the shows;
  - specifics over adjectives;
  - UK spelling;
  - "we" for the desk;
  - humour from observation, never from puns stacked three deep;
  - no exclamation marks in headlines;
  - no emoji;
  - never an em dash or a spaced en dash.
- The admin edits both on the desk's prompt page, with a version list, restore and reset.
- The fixed rules are shown read-only beside them, so the admin sees what always applies. They are:
  - real sources only, and links only from the research;
  - the verified/speculation split;
  - no duplicates, declared follow-ups;
  - the categories;
  - no email addresses or phone numbers in the text;
  - no invented quotes;
  - name the site's own products only where directly relevant, once, and plainly;
  - the JSON shape.
- **The phrases to avoid** are a setting (a list, editable, with defaults such as "delve", "game-changer", "in today's fast-paced", "ever-evolving", "navigate the", "seamless", "unlock", "harness the power", "in conclusion", "it's worth noting", "a testament to", "landscape of", "let's dive", "buckle up", "moreover", "furthermore").

### 6.3 The article JSON (compose output, import files, `Draft::fromArray`)

```json
{
  "key": "2026-10-22-right-to-work-checks-freelancers",
  "title": "Right to work checks now reach freelancers, and the liability runs up the chain",
  "dek": "One or two sentences that say what happened and why a crew chief should care.",
  "body": "Markdown. The story: what happened, the context, what it means in practice. ## and ### headings allowed.",
  "confirmed": [{"text": "A verified fact, one sentence.", "url": "https://source"}],
  "speculation": [{"text": "A claim not yet confirmed, and whose claim it is.", "url": "https://source"}],
  "impact": "Markdown. How it applies to freelance and corporate event delivery, or plainly that it does not.",
  "verdict": "adopt | test | watch | skip | note",
  "verdict_note": "One or two sentences: what to test or adopt, or why to wait.",
  "categories": ["legal", "freelance"],
  "tags": ["right to work", "home office"],
  "sources": [{"url": "https://...", "title": "Page title", "publisher": "GOV.UK", "kind": "primary | press | report | standard | other"}],
  "follow_up_of": [12],
  "story_key": "uk-right-to-work-checks-extended-to-freelancers"
}
```

- `key` is used by import files only.
- `follow_up_of` holds article ids in the compose output and slugs in import files.
- The compose schema wraps the articles as `{articles: [...], skipped_reason: string}`.
- Every object has `additionalProperties: false` and no numeric or string-length constraints (lengths are checked by `Validator`).
- `verdict` and `categories` items are enums. Fields that may be empty are required but can be `""` or `[]`, which keeps the optional-parameter count at zero.

**Verdict labels on the page:**

| Value | Label |
|---|---|
| adopt | Worth adopting |
| test | Worth testing |
| watch | One to watch |
| skip | Safe to skip for now |
| note | Good to know |

## 7. Validator rules (all origins unless marked)

| Rule | Detail | Admin wording |
|---|---|---|
| `title` | 10 to 160 characters, a single line | Enter a headline of 10 to 160 characters. |
| `dek` | 30 to 320 characters | Enter a standfirst of 30 to 320 characters. |
| `body` | 300 to 20000 characters (manual: 50 to 20000) | |
| `categories` | 1 to 3, every one a known key | Choose one to three categories. |
| `tags` | Up to 8, each up to 40 characters, lower case | |
| `sources` | Publishing needs at least 1; at most 12; http or https only; titles up to 300 characters | |
| `confirmed`, `speculation` | Up to 12 items each, each item up to 400 characters, each URL also a source | |
| `verdict` | One of the 5, or empty for manual articles | |
| `seen` (AI) | Every source URL and every external link in any text is in the research's `seen` set (compared by `Urls::key`) | |
| `banned_domain` | No source or link on a banned domain | That site is on the banned list. |
| `banned_topic` | No banned topic as a whole-word, case-insensitive match in the title, dek, body, impact or tags (AI and import; manual gets a warning flash, not a block) | |
| `duplicate` (AI and import) | The `story_key` equals an existing article's from the last 365 days; or a primary source's URL key equals a source of an existing article from the last 120 days; or the title's word overlap (Jaccard over lower-case words with stopwords removed) is at least 0.6 against an article from the last 60 days. Each check is ignored when the existing article is in `follow_up_of` | |
| `follow_up` | Every id or slug in `follow_up_of` exists | |
| `contact` | No email address and no `mailto:`, because the public pages are fenced on it | Remove the email address; link to a page instead. |
| `dashes` | Not a rejection: `undash` runs on save, and a manual save that changed something says "Em dashes were replaced with commas." | |
| `tells` (AI) | Phrases to avoid lead to the repair call (§6.1, step 6) | |

## 8. Settings (`news_settings`, edited on the desk pages)

| Name | Default | Admin control |
|---|---|---|
| `enabled` | `true` | Pause and continue (desk page buttons) |
| `max_per_session` | `3` | Articles per session, 1 to 5 |
| `min_importance` | `3` | How newsworthy a story must be, 1 to 5 |
| `slots` | `[{"time":"06:00","days":[1,2,3,4,5,6,7]},{"time":"14:30","days":[1,2,3,4,5,6,7]}]` | Sessions: rows of a time and weekday ticks, plus "Add a session", up to 6. The page shows the totals per day and per week |
| `date_overrides` | `{}` | Calendar: each date is `off` or `on`, set on a month grid of tick boxes; past dates are pruned on save |
| `auto_publish` | `true` | Publish automatically, or keep as drafts for review |
| `research_model`, `compose_model` | `claude-opus-5-5` | Opus 5.5 or Sonnet 5.5, with the estimated cost per session shown |
| `max_searches`, `max_fetches` | `12`, `10` | Web searches and page reads per session |
| `monthly_budget_usd` | `60` | Monthly spend cap (estimated) |
| `banned_domains` | `[]` | One per line; normalised; up to 200 |
| `banned_topics` | `[]` | One per line, up to 100, each up to 80 characters |
| `avoid_phrases` | The defaults in §6.2 | One per line, up to 200 |
| `ai_note` | "Researched and written by our AI desk from the sources listed below. Spotted something off? Tell us and we will put it right." | Text, up to 300 characters |
| `comments` | `open` | `open`, `approval` (new comments wait for an admin) or `closed` |

The brief and the voice are prompt settings, stored with versions in `news_prompt_versions`.

## 9. Comments

- **Who.** Any signed-in, active account. Admins are labelled "Patterns team".
- **Limits.**
  - The body is 2 to 2000 characters.
  - At most 2 links.
  - No email addresses: one is refused with a message saying so.
  - The same body from the same person on the same article within 24 hours is passed on once.
- **Rate limits.**
  - `news-comment:<uid>`: 5 per 10 minutes and 30 per day.
  - `news-report:<uid>`: 20 per day.
- **How a comment is shown.** As plain text (`nl2br(e())`), never Markdown, with no live links. It carries the author's name (`Auth::cleanLine($user['name'])`), "Former account" when the account has been anonymised, and the time.
- **Moderation.** In `approval` mode a new comment is `pending` and its author is told so. Any signed-in person can report a comment once. At 3 reports a comment is hidden until an admin looks, and admins are emailed on the first report (`notifyAdmins`). Admins approve, hide, show, delete or clear reports.
- **Deleting.** A person can delete their own comment.
- **When comments close.** When an article's `comments_open` is off, or the setting is `closed`, the comment box says comments are closed, and existing comments stay.
- **Where the person's comments go.** The account export lists them, and the account delete removes them. The privacy policy and terms say both.

## 10. Public pages

- **Index `/news/`.**
  - A hero, then the category row.
  - `.c-tabs` holds server-rendered links ("All" plus each category with a count).
  - A search box (GET `?q=`).
  - Then the newest article as a wide card, then a grid of cards with 12 per page, and the pager.
  - Page 2 onwards is `noindex, follow`.
- **Category `/news/category/{key}/`.** The same page with the category's own heading and description. A category without articles is a 200 with the empty state, `noindex`.
- **Article `/news/{slug}/`.** In order:
  1. Breadcrumbs (Industry Updates › Category › Title).
  2. The category tags, the title (h1) and the dek.
  3. The byline line: date and time in London, read time, updated time if changed, and the byline text.
  4. A follow-up banner ("Follow-up to: …" linking to the earlier articles).
  5. The lead image with its caption and credit, or the category artwork.
  6. The body.
  7. "What's confirmed" (each item with its source link).
  8. "Still speculation" (shown only if there is any).
  9. "What it means for freelancers and corporate events".
  10. The verdict callout.
  11. The other images.
  12. "Sources" (a numbered list of tracked links, each with its publisher and kind).
  13. "Later updates" (articles that follow up this one).
  14. The AI note (AI articles).
  15. Comments.
- **Article metadata.** JSON-LD `NewsArticle` with headline, description, `datePublished`, `dateModified`, `articleSection`, `keywords`, `image` (the lead OG image or the news card), `author` and `publisher` (the Organization), `isBasedOn` (source URLs), `mainEntityOfPage`, plus the BreadcrumbList. The head also gets `article:published_time`, `modified_time`, `section` and `tag` meta.
- **Feed.** RSS 2.0, the 30 newest live articles, with `<link rel="alternate">` on every news page.
- **Tracked link `/news/go/{id}/`.**
  - Looks up the link and answers a 302 to the stored URL. It never redirects to a URL taken from the request.
  - Adds 1 to `clicks` and to today's `news_link_clicks` row, except for HEAD requests and user agents that look like bots (`bot|crawl|spider|slurp|preview|fetch|monitor|curl|wget|python|httpclient`).
  - Sends `X-Robots-Tag: noindex` and `private, no-store`.
  - A link whose article is not live gives 404.
- **Images `/news/media/{file}`.** `public, max-age=31536000, immutable`, `nosniff` and `Content-Security-Policy: default-src 'none'`. The image's article must be live, or the request must come from an admin preview.
- **Cache.** News HTML sends `public, max-age=120, stale-while-revalidate=600` when no session is started.
- **Design.** `public/assets/css/pages/news.css` uses the `news-` prefix and tokens only.
  - Cards follow the stretched-link card (`support-wfcard`).
  - Category artwork is inline SVG drawn from test-pattern motifs: colour bars for Video, a waveform for Audio, beams for Lighting, a truss for Staging, a cue stack for Production, a clock for Showcalling, a signal for Streaming, nodes for Automation, a grid for Interactive, a spark for AI, a network for Technology, a person for Freelance, a shield for Legal and a lightbulb for Tips.
  - "Confirmed" uses the done badge (green, with the word); "Speculation" uses the progress badge (amber, with the word). Colour is never the only cue.
  - No product colours.

## 11. Admin pages

- **Articles list.** Tabs All, Live, Scheduled, Drafts with counts; filters for category, origin and search; a 5-column stacking table (headline, status, origin, published, comments); a "Write an article" button.
- **Editor (new and edit).**
  - **Main fields:** the error summary, then headline, standfirst, body, "What's confirmed" and "Still speculation".
  - **Confirmed and speculation rows:** text plus a source URL. The editor shows 6 rows plus the existing ones; JS adds more.
  - **Analysis:** impact, verdict (radio) and the verdict note.
  - **Classification:** categories (checkboxes, 1 to 3) and tags.
  - **Sources:** rows of URL, title, publisher and kind, the same way.
  - **Follow-up of:** a select of the 60 most recent articles, multiple.
  - **Comments:** open or closed.
  - **Buttons:** save draft, publish now, schedule (a date and time in London), and unpublish.
  - **Edit page extras:**
    - images, each with its own form to upload, edit alt/caption/credit, make lead or delete;
    - link clicks (per link, totals and the last 30 days);
    - the run that wrote it, for AI articles;
    - a preview link;
    - delete, with a confirm box.
  - **Keeping input after a failed save.** `keepOld` drops arrays and cuts at 5000 characters, so the editor keeps its own draft in the session (`news_draft`) after a failed save.
- **Comments.** Tabs Reported, Pending, Visible, Hidden; each comment with its article, its author and its actions.
- **Desk.**
  - The status (running or paused, with the reason when a session cannot run: no key, cap reached, paused), the next 6 sessions, and this month's estimated spend against the cap.
  - Buttons: "Pause the desk" or "Continue the desk", and "Run a session now" ("It starts within 15 minutes").
  - The last 20 runs (time, kind, status, articles, cost, summary), each linking to its detail page (log steps, rejected candidates with their rule, usage).
- **Settings.** The fields in §8.
- **Calendar.** A month grid from the current month for 3 months. Each day is a tick box (on or off) with its default shown, a legend, and the per-day session count. No-JS friendly.
- **Prompt.** The brief and voice textareas, each with save, reset to default and a version list with restore; the fixed rules read-only.
- **Rules that apply to every admin page.** All pages pass the AdminTest fences:
  - exactly one `h1.pt-title`;
  - no `style=` and no `<script` in main;
  - stacking tables labelled;
  - every GET in `adminPages()`.

## 12. Tests

- **Unit:** `Prose`, `Urls`, `Validator`, `Schedule` (with BST and GMT dates), `Pricing`, `Markdown` link rewriting, and `AnthropicNewsModel` against a Guzzle `MockHandler`. The model test checks the request JSON (tools, `blocked_domains`, `output_config.format`, `fallbacks`, the beta header), a `pause_turn` round trip, URL collection and usage summing.
- **Feature:**
  - the public pages, the feed, the sitemap, `robots`, `llms`, `go` (redirect, count, bots, not-live) and `media`;
  - comments (API and no-JS page, limits, reports, moderation, approval mode, delete own, export and account delete);
  - the admin editor (create, validate, keep the draft, publish, schedule, unpublish, delete, images, audit);
  - the desk (settings validation, calendar, prompt versions, pause and resume, run now);
  - the pipeline with a fake `NewsModel`: a happy path with staggered times; slow news skipped; the seen-URL fence; banned domain and topic; a duplicate refused and a follow-up allowed; the repair path; failure, cap and paused paths; slot claiming exactly once;
  - the importer (once, tombstone, invalid file).
- **Fences:**
  - no stored article text contains U+2014;
  - no public template, content file or knowledge file contains an em dash (ContentRulesTest);
  - every new admin GET route is in `adminPages()`.
- **e2e:** the sitemap now includes the launch articles, so `pages.spec` checks them all. The `forms` spec gains a comment round trip.

## 13. What this does not do

- **No email newsletter, and no social posting.**
- **No images taken from sources by the AI desk.** Press images can be added by hand with their credit.
- **No CDN purge from the server.** The API token lives only in GitHub. The short news cache is the mitigation, and the admin can run the "Flush CDN" workflow.
- **No full-text search engine.** Search is `LIKE` over the title and standfirst.
- **No human check before AI articles go live by default.** `auto_publish` off turns every AI article into a draft for review, and the owner decides which way to run it.
