# Industry Updates: the news section

This section of the site carries AV and live-event industry news. It has:

- category filters;
- an admin area for writing, editing and deleting articles, with images;
- tracked links out to the original sources;
- comments from registered users;
- an AI desk that researches and writes up to 3 articles per scheduled session. By default there are two sessions a day, around 06:00 and around 14:30.

This file is the design and the contract the build follows. `docs/DEPLOY.md` covers the operator's side: the cron line, the key and the Console settings. `content/news/` holds the default prompt texts.

## 1. What the owner asked for

These are the owner's words, kept because the defaults and fences come from them.

**The section**
- "Industry Updates", a news and blog section for the latest relevant AV and event industry news.
- It filters by industry: Video, Audio, Lighting, Staging, Production, Showcalling, Technology, Interactive and so on.

**Writing and publishing**
- Most articles are researched and written automatically. An admin area adds, edits and deletes articles by hand.
- Articles can include related images.
- Articles carry tracked links out to the original sources.

**The research sessions**
- Every morning around 6am and every afternoon around 2:30pm, research consequential developments in the AV industry, corporate live events and live streaming.
- That especially means, but is not limited to:
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
- No duplicates. A follow-up says so and links to the earlier article or articles.
- Verified product or standards changes are kept apart from speculation.
- Each development is explained, with how it applies, if it does, to freelance and corporate event delivery.
- Anything worth testing or adopting is highlighted.
- 1 to 3 articles in the morning and 1 to 3 in the afternoon. A slow news session can be skipped.
- Within a session, the articles go live 10 to 30 minutes apart, at random.

**Comments**
- Registered users can comment.

**The voice**
- Friendly and professional, not stuck up. It can be casual and witty, with dry UK banter and humour, but not over the top.
- An engaging read with personality.
- It breaks up polished AI rhythms, so it reads like a person who works in the industry, is still passionate about it, has lots of experience, and is still interested in new developments and whether they help.
- **Never use em dashes.**

**Admin controls.** An admin can:
- pause and continue research and writing;
- change the number of articles per session;
- change the number of sessions per day or week;
- enable or disable calendar days;
- edit the prompt;
- add banned websites and banned topics.

## 2. Decisions

1. **Where it lives.**
   - The index is `/news/`.
   - Articles are at `/news/{slug}/`, and categories at `/news/category/{key}/`.
   - The feed is `/news/feed.xml` (RSS 2.0).
   - Public images are at `/news/media/{file}`, and admin preview images at `/portal/admin/news/media/{file}`.
   - The section's name, "Industry Updates", is a proper name. It is the h1, the page titles, the footer link, the admin nav label and the byline.
   - The header nav label is **"News"**. "Industry Updates" overflows the header bar at 1152 to 1440px (measured), and DESIGN.md §10 asks for a clean bar at those widths.
2. **Who writes.**
   - The site's own AI desk writes the articles: `tools/news.php tick`, run by a Hostinger cron every 15 minutes, using the Anthropic API with the web search and web fetch server tools.
   - The desk uses `news.api_key`, falling back to `chat.api_key`. A separate key in its own workspace is recommended, so that a heavy news month cannot use up the support assistant's monthly limit.
   - Articles can also arrive from the repository as JSON files in `content/news/articles/`, imported once each by the tick and by `tools/news.php import`. This is how the launch articles, and any article written in a Claude Code session, get published.
   - Every route, including the admin editor, goes through the same `Validator` and the same `ArticleStore`.
3. **The database, not the content registry.**
   - Articles, comments, settings and runs live in the database (migration `0004_news`).
   - They are not in `content/*.php`, which `ContentRulesTest` fences, because news legitimately says "open-source".
   - They are not in the assistant's knowledge, which would re-bill its cache on every article.
   - `/news/` itself is a registry page (`content/pages.php`) with its own controller, like `/contact/`.
4. **Public pages stay cacheable and session-free.**
   - The HTML of an article is the same for every visitor.
   - News responses never start a session and never set a cookie. This is a fence: every `/news/*` GET, made with a signed-in admin's cookie jar, sets no cookies.
   - Comments are rendered into the page. `news.js` adds the comment box and the person's own recent or pending comments through `/api/news/{id}/state`.
   - Without scripting, the box links to the comment page `/news/{slug}/comment/` (`session`, `auth`, `nostore`).
   - News HTML sends `public, max-age=120, stale-while-revalidate=600`. Whether Hostinger's CDN honours `max-age` must be checked on the live site; until then, takedown actions in the admin say "Run Flush CDN to remove it now".
5. **A session is three kinds of model call.** Structured output cannot be combined with web-search citations, and forcing a tool call is refused on these models, so the steps are kept apart:
   1. **Research.** Web tools on, free text. The model writes a brief between marker lines. The server builds numbered references from every search result, fetched page and citation.
   2. **Select.** No tools, JSON schema. The model returns every candidate it considered, each with a decision. The server enforces the count, the importance threshold, the window and dedup itself.
   3. **Compose.** No tools, JSON schema. One call per selected story, so one bad article never sinks the others.

   Two extra calls only when needed:
   - a **check** call on Claude Haiku 5.5, for banned topics and for allegation risk;
   - a **repair** call, at most once per article, for the voice fence.
6. **Fences, not hopes.** Each of these is code with a test.
   - An AI article can cite only a numbered reference (`S1`, `S2` …) that the research actually returned. The server writes the URL, so a URL can never be mistyped or invented. Every confirmed item must cite a page the desk fetched or a search citation.
   - Banned domains are blocked in the tools and refused in sources.
   - Banned topics are refused.
   - Duplicates are refused unless they are declared follow-ups, and a follow-up must bring at least one new source.
   - Em dashes are removed from every stored article text, manual articles included.
   - Publishing needs at least one source.
   - An AI article that names a private individual, makes an allegation of wrongdoing, or trips the check in any other way is kept as a draft, and the admins are emailed. It is never auto-published.
7. **Honesty.**
   - AI-written articles (`ai_written`: every `ai` article, every import unless its file says otherwise, and manual articles ticked "Written with AI help") carry a short, plain note saying so (editable in the settings).
   - Their byline is "Industry Updates desk" and their JSON-LD `author` is the Organization.
   - A manual article's byline is its author's name, set in the editor. Its `author` is the Person node only when that name is the site's publisher (`app.publisher`); otherwise it is the Organization.
8. **Images.**
   - Admins upload images through `Uploads::store` (png, jpg, webp or gif, sniffed), into a temporary folder.
   - `getimagesize` must show 25 megapixels or fewer, and the image must fit the memory limit, before GD decodes it.
   - The image is then re-encoded (which strips metadata and anything hiding in the file) to WebP at 480, 960 and 1600 wide (never upscaled), plus a 1200×630 JPEG for share cards. The result is stored under `storage/uploads/news/`.
   - Public images go out with `public, max-age=86400, s-maxage=3600`, never `immutable`, so a takedown leaves the CDN within the hour.
   - Alt text is required. A caption, credit and credit link are optional.
   - The AI desk never copies images. An article without an image shows its category's monochrome artwork.
9. **Models and money.**
   - **Models.** Research defaults to `claude-sonnet-5-5`, because it is search-heavy and reads a lot of input. Select, compose and repair default to `claude-opus-5-5`, because the writing is the point. The admin can switch either step between Opus 5.5 and Sonnet 5.5. Check always uses `claude-haiku-5-5`.
   - **Call settings.** Thinking is adaptive at high effort. Prompt caching is on, via the top-level `cacheControl` ephemeral.
   - **Estimate per session.** Research is roughly 0.4 to 1M input tokens, mostly cached, plus search fees. Select and compose add 30 to 60k input and 20 to 40k output. That comes to about 1 to 2.50 USD a session, or about 60 to 150 USD a month for 61 sessions.
   - **Cap.** The default monthly cap is 100 USD, estimated from the reported usage at `Psc\News\Pricing` rates for the model that actually served each call. A session starts only if this month's spend plus the 90th-percentile cost of recent sessions (2.50 USD until there are 10 sessions) fits under the cap. A session also stops spending once it passes a per-session ceiling (5 USD).
   - **Search and fetch limits.** Default 12 searches and 6 fetches per session, counted across continuations. Fetched pages are capped at 8000 tokens.

## 3. Data (migration `0004_news`, MySQL and SQLite)

- All times are Unix seconds (INT).
- MySQL follows the 0001 conventions: InnoDB, utf8mb4_unicode_ci, `INT UNSIGNED` ids, `KEY t_x` and `CONSTRAINT fk_t_ref`.
- SQLite follows its own: `INTEGER PRIMARY KEY AUTOINCREMENT`, and separate `CREATE INDEX` statements with the same names.
- Write `CREATE TABLE name`, never `IF NOT EXISTS`. MigratorTest matches table names with `/CREATE TABLE (\w+)/`.

### `news_articles`

**Content**
- `id`
- `slug VARCHAR(190)`
- `title VARCHAR(190)`: the headline (h1, og:title)
- `seo_title VARCHAR(70) NULL`: the `<title>`, 60 characters or fewer
- `dek VARCHAR(320)`: the standfirst
- `summary VARCHAR(170) NULL`: the meta description, 50 to 155 characters
- `body TEXT`: Markdown
- `confirmed TEXT NULL`: JSON list of `{text, url}`
- `speculation TEXT NULL`: JSON list of `{text, url}`
- `impact TEXT NULL`: Markdown
- `verdict VARCHAR(16) NULL`: `adopt|test|watch|skip|note`
- `verdict_note VARCHAR(320) NULL`
- `tags VARCHAR(320) NULL`: comma-separated, lower case
- `byline VARCHAR(120) NULL`

**Publishing**
- `status VARCHAR(16) NOT NULL DEFAULT 'draft'`: `draft|published`
- `published_at INT NULL`: a future time means scheduled
- `origin VARCHAR(16) NOT NULL DEFAULT 'manual'`: `manual|ai|import`
- `ai_written TINYINT(1) NOT NULL DEFAULT 0`: 1 for `ai`; for `import`, the file's `ai_written` (default true); for `manual`, the editor's "Written with AI help" tick. It decides the AI note and the desk byline
- `review_reason VARCHAR(300) NULL`: why an AI article was held as a draft
- `story_key VARCHAR(190) NULL`

**Counters**
- `comments_open TINYINT(1) NOT NULL DEFAULT 1`
- `comment_count INT NOT NULL DEFAULT 0`
- `word_count INT NOT NULL DEFAULT 0`

**Ownership and times**
- `author_id INT UNSIGNED NULL` (no FK)
- `run_id INT UNSIGNED NULL` (no FK)
- `created_at`, `updated_at`

**Keys:** UNIQUE `news_articles_slug (slug)`, `news_articles_live (status, published_at)`, `news_articles_story (story_key)`.

### The other tables

| Table | Columns | Keys |
|---|---|---|
| `news_article_categories` | `article_id` (FK → articles, CASCADE), `category VARCHAR(32)`, `position INT NOT NULL DEFAULT 0` (0 = primary, used for the crumb) | PK `(article_id, category)`; `news_article_categories_cat (category, article_id)` |
| `news_links` | `id`, `article_id` (FK, CASCADE), `url VARCHAR(2000)`, `url_key CHAR(64)`, `host VARCHAR(190)`, `title VARCHAR(300) NULL`, `publisher VARCHAR(160) NULL`, `kind VARCHAR(16) NOT NULL DEFAULT 'other'`, `is_source TINYINT(1) NOT NULL DEFAULT 1`, `position INT NOT NULL DEFAULT 0`, `clicks INT NOT NULL DEFAULT 0`, `created_at` | `news_links_article (article_id, position)`; `news_links_url (url_key)` |
| `news_link_clicks` | `link_id` (FK → links, CASCADE), `day CHAR(10)`, `clicks INT NOT NULL DEFAULT 0` | PK `(link_id, day)` |
| `news_related` | `article_id` (FK, CASCADE; the later article), `related_id` (FK → articles, CASCADE; the earlier one) | PK `(article_id, related_id)`; `news_related_back (related_id)` |
| `news_images` | `id`, `article_id` (FK, CASCADE, NULL), `position INT NOT NULL DEFAULT 0` (0 = lead), `base CHAR(32)`, `widths VARCHAR(40)` (for example `480,960,1600`), `width INT`, `height INT` (of the largest variant), `alt VARCHAR(300)`, `caption VARCHAR(300) NULL`, `credit VARCHAR(160) NULL`, `credit_url VARCHAR(500) NULL`, `original_name VARCHAR(190)`, `bytes INT`, `created_by INT UNSIGNED NULL`, `created_at` | `news_images_article (article_id, position)` |
| `news_comments` | `id`, `article_id` (FK, CASCADE), `user_id` (FK → users, CASCADE), `body TEXT`, `status VARCHAR(16) NOT NULL DEFAULT 'visible'` (`visible\|pending\|hidden`), `reports INT NOT NULL DEFAULT 0`, `created_at`, `updated_at`. **No IP is stored.** | `news_comments_article (article_id, status, created_at)`; `news_comments_user (user_id)`; `news_comments_status (status, reports)` |
| `news_comment_reports` | `comment_id` (FK, CASCADE), `user_id` (FK → users, CASCADE), `created_at` | PK `(comment_id, user_id)`; `news_comment_reports_user (user_id)` |
| `news_runs` | See below | UNIQUE `news_runs_slot (slot)`; `news_runs_status (status, created_at)` |
| `news_settings` | `name VARCHAR(64)` PK, `value TEXT` (JSON), `updated_by INT UNSIGNED NULL`, `updated_at INT` | |
| `news_prompt_versions` | `id`, `field VARCHAR(16)` (`brief\|voice`), `body TEXT`, `created_by INT UNSIGNED NULL`, `created_at` | `news_prompt_versions_field (field, created_at)` |
| `news_imports` | `import_key VARCHAR(190)` PK, `article_id INT UNSIGNED NULL`, `imported_at INT` | |

In `news_links`:
- `url_key` is the sha256 of `Urls::canonical`.
- `host` is the canonical host, shown beside the link.
- `kind` is one of `primary|press|report|standard|other`.

`news_runs` columns:
- **Identity:** `id`; `slot VARCHAR(40)`, which is `Y-m-d HH:MM` in London time or `manual-<unix>-<6 hex>`; `kind VARCHAR(16)`, `scheduled|manual`.
- **State:** `status VARCHAR(16)`, one of `queued|running|done|skipped|failed`; `requested_by INT UNSIGNED NULL`; `started_at INT NULL`; `deadline_at INT NULL`; `finished_at INT NULL`.
- **Counts:** `candidates`, `articles` and `held`, each `INT NOT NULL DEFAULT 0`.
- **Usage:** `usage_in`, `usage_out`, `cache_write`, `cache_read`, `searches` and `fetches`, each `INT NOT NULL DEFAULT 0`.
- **Cost:** `cost_micros INT NOT NULL DEFAULT 0`.
- **Record:** `models VARCHAR(160) NULL`; `summary VARCHAR(500) NULL`; `brief TEXT NULL`, the research brief stored as soon as research ends; `refs TEXT NULL`, a JSON map from ref to `{url, title, via, page_age, cited}`; `considered TEXT NULL`, JSON; `log TEXT NULL`, a JSON list of steps, each `{t, step, ms, note, counts}` and never headers or exception context; `created_at`.

### Retention and deletion

- `news_imports` rows are never deleted. An imported article that is deleted in the admin is never brought back.
- **Account deletion** anonymises the account (an UPDATE, so the CASCADE keys never fire). It therefore explicitly hard-deletes the person's `news_comments` and `news_comment_reports`, then recounts `comment_count` and `reports`.
- **The export** lists the person's news comments in every status, and their reports.
- **Housekeeping additions:**
  - `news_link_clicks` older than 400 days;
  - `news_runs` older than 180 days;
  - orphaned `news_images` (no article) older than one day, with their files.
- Comments are kept for as long as their article exists and the account is not deleted. The privacy policy says so.

## 4. Code layout (`Psc\News\`)

### Text and URLs

**`Categories`**
- Reads `content/news.php` `categories`: key ⇒ `{name, title (≤ 60), description (50 to 155), intro, icon}`.
- Methods: `all()`, `has()`, `name()`.
- The keys are fixed: `video audio lighting staging production showcalling streaming automation interactive ai technology freelance legal tips`.

**`Prose`** (static text rules)
- `undash(string): string`: an em dash, or an en dash used as a dash, becomes a comma; an en dash between digits becomes a hyphen. It then tidies `, ,`, ` ,`, `,.` and doubled spaces.
- `countDashes()`.
- `tells(string $text, list<string> $phrases): list<string>`: case-insensitive, whole words. It ignores text inside quotation marks and Markdown link text.
- `words()`, `readMinutes()`.
- `slug(string): string`: ASCII through intl `Transliterator` or iconv, lower case, `[a-z0-9-]`, at most 80 characters, cut on a hyphen.
- `plain(string $md): string`.
- `cut(string, int $max): string`: on a word boundary, with no trailing punctuation.
- `emails(string): bool`: true if the text holds an email address or `mailto:`.

**`Urls`**
- `canonical(string): ?string`:
  - accepts http and https only;
  - rejects userinfo and IP-literal hosts;
  - takes the host through `idn_to_ascii`, then lower case, and strips the trailing dot and `www.`;
  - drops the fragment, the `utm_*` parameters, `fbclid`, `gclid`, `mc_cid`, `mc_eid` and the default port;
  - keeps the path and every other query parameter, sorted by name;
  - trims the trailing slash, except at the root.
- `key()`: the sha256 of the canonical form.
- `host()`.
- `isOwn(string $url, string $baseUrl): bool`: an exact host match.
- `onDomain(string $url, list<string> $domains): bool`.
- `normaliseDomain(string): ?string`.

**`Markdown`**
- `render(string $md, array $linkIds, string $baseUrl): string`:
  1. Normalises blank lines around heading and list lines so that `md()` recognises them.
  2. Refuses fenced code. It is shown as plain paragraphs.
  3. Runs `md()`.
  4. Shifts headings so a body starts at h2 and never skips a level.
  5. For each external link whose canonical key is in `$linkIds`, keeps the real `href` and adds `data-news-link="{id}"`.
- `inline()`: the same, for list items.
- `helpers.php` `md()` and `md_inline()` decide "external" by comparing parsed hosts (`Urls::isOwn`), not by a string prefix. This fixes `https://patternsshowcontrol.co.uk.evil.example/` counting as internal.

### Articles

**`Draft`**
- An article being created or changed, with typed fields: `title seoTitle dek summary body confirmed[] speculation[] impact verdict verdictNote categories[] tags[] sources[] followUps[] storyKey byline origin status publishAt commentsOpen`.
- `fromArray(array, string $origin, array $refs = [])`: reads the JSON in §6.4. For AI drafts, `$refs` maps `S1` to its URL and title.
- `toArray()`.

**`Validator`**
- `check(Draft, Context): Result` returns:
  - `errors`: field ⇒ message, in admin wording;
  - `warnings`;
  - `rejections`: a list of `{rule, detail}`;
  - `hold`: a list of reasons to keep the article as a draft.
- Context fields: `origin`, `mode` (`draft|publish`), `refs` (AI only), `bannedDomains`, `bannedTopics`, `avoidPhrases`, `recent` (for dedup), `editingId`, `baseUrl`.
- The rules are in §7.

**`ArticleStore`**
- The only writer of the article tables.
- **Writing:**
  - `create(Draft, ?int $userId, ?int $runId): int` and `update(int $id, Draft, ?int $userId): array $changed`. Both run in one transaction and retry a unique-slug clash.
  - `publish(int $id, ?int $at)`, `unpublish(int $id)`.
  - `delete(int $id): ?array`. It removes the image files and returns the row for the audit.
- **Reading:**
  - `find(int)`, `findBySlug(string, bool $liveOnly = true)`.
  - `live(?string $category, ?string $q, int $page, int $perPage): array{items, total, page, pages}`.
  - `recent(int $days, int $limit): list`.
  - `categoryCounts(): array`.
  - `links(int)`, `related(int): {earlier, later}`, `images(int)`.
  - `isLive(array $row)`.
- **On every save:**
  - applies `Prose::undash` to every text field;
  - lower-cases the tags;
  - registers the links (the sources, plus every external link in the body, impact, confirmed and speculation);
  - computes `word_count`;
  - makes the slug unique (`-2`, `-3` and so on).
- **"Live"** means `status = 'published' AND published_at <= now`.

**`Images`**
- `store(array $upload, ?int $articleId, array $meta, ?int $userId): array{ok, image?, error?}`.
- `srcset(array $image, bool $admin = false): string`.
- `delete(array $image)`.
- `file(string $name): ?string`: validates `^[a-f0-9]{32}-(480|960|1600)\.webp$|^[a-f0-9]{32}-og\.jpg$` and returns the path.
- `supported(): bool`: true when `imagewebp`, `imagejpeg` and `getimagesize` all exist. `/health` reports it.

**`Comments`**
- `post(int $articleId, array $user, string $body): array{ok, comment?, errors?, status?}`.
- `remove(int $commentId, array $actor): bool`.
- `report(int $commentId, array $user): string`, which returns `reported|already|own|staff`.
- `visible(int $articleId): list`.
- `mine(int $articleId, int $userId, int $since): list`.
- `forExport(int $userId): array`.
- `deleteForAccount(int $userId)`.
- `publicName(array $user): string`: the first name and the initial of the last name, through `TicketController`'s rules, moved to `Psc\Support\Names`. Words containing `@` are dropped, and bidi and zero-width characters are stripped.
- The rules are in §9.

### The desk

**`Settings`**
- DB-held settings with defaults (§8).
- `get(string)`, `all()`, `save(array $values, ?int $userId): array{changed, errors}`.
- `brief()`, `voice()`, `savePrompt(string $field, string $text, ?int $userId)`, `resetPrompt()`, `versions(string $field)`.
- `heartbeat(array $tick)` and `lastTick(): ?array`.
- `lease(int $runId, int $until): bool` and `releaseLease(int $runId)`. These use a conditional UPDATE on the `lease` row.

**`Schedule`**
- `due(\DateTimeImmutable $now, Settings, ?array $lastRun): ?string`. It returns the slot due now, or null. A slot is due when all of these hold:
  - the London time is at or after the slot's time and before it plus 2 hours;
  - the date is not overridden off;
  - the weekday is on for that slot, or the date is overridden on;
  - the desk is enabled;
  - no run of any kind started within the last 60 minutes.
- `upcoming(\DateTimeImmutable $from, Settings, int $count): list<{date, time, slot}>`.
- `perDay(Settings): array<weekday, int>`, `perWeek(Settings): int`.
- `isOn(string $date, Settings): bool`.

**`Runs`**
- `claim(string $slot, string $kind, ?int $userId): ?int`: an INSERT into the unique slot.
- `queueManual(?int $userId): ?int`: refused while a run is queued or running.
- `start(int $id, int $deadline): bool`: `UPDATE … SET status='running' WHERE id=? AND status='queued'`, which must change exactly 1 row.
- `record(int $id, array $fields)`: written after every model call.
- `finish(int $id, string $status, array $report): bool`: `WHERE status='running'`.
- `reapStale(int $now)`: a running run past `deadline_at` + 15 minutes becomes `failed`.
- `spentThisMonth(): float`: by the London month.
- `p90Cost(): float`.
- `recent(int)`.
- `consecutiveEmpty(): int`.

**`Pipeline`**
- `run(int $runId, NewsModel $model, \DateTimeImmutable $now): array $report`. It carries out §6.

**`Prompts`**
- `researchRules()`, `selectRules()`, `composeRules()`, `repairRules()`, `checkRules()`: the fixed texts, shown read-only in the admin.
- `selectSchema()`, `articleSchema()`, `checkSchema()`.

**`NewsModel`** (interface; the seam tests replace)
- `research(array $call): array{text, refs, usage, stop, model, container}`.
- `structured(array $call): array{data: ?array, usage, stop, model}`.
- A call is `{model, system, messages, tools?, schema?, maxTokens, effort}`.

**`AnthropicNewsModel`**
- Built on the official PHP SDK, using the beta path with `fallbacks: 'default'`. Check calls go to Haiku without fallbacks.
- **Transporter.**
  - A Guzzle client of its own on `GuzzleHttp\Handler\CurlHandler`, with:
    - `timeout` 600 and `connect_timeout` 15;
    - `curl` options `CURLOPT_LOW_SPEED_LIMIT => 1` and `CURLOPT_LOW_SPEED_TIME => 120`.
  - `maxRetries`: 0 for research, 1 for the others.
- **Streaming.**
  - The stream runs through `MessageAccumulator::forBetaMessages()`.
  - An incomplete stream, or a null stop reason, is a failure.
- **`pause_turn`.**
  - Resumes up to 4 times. Each time it resends the content verbatim and carries `container` forward (`$msg->container?->id`).
  - On each continuation, `max_uses` is set to what is left of the session's search and fetch allowance.
  - Once both are used up, it sends one last user message: "No more searches or fetches are available. Write the brief now from what you have."
- **References.** It builds them from `web_search_tool_result` (url, title, page_age), from `web_fetch_tool_result` (both the requested URL and the result URL), and from text-block citations (`cited`).
- **The brief** is the text between `=== BRIEF ===` and `=== END ===`, joined across continuations.
- **Usage** is summed per call, including `serverToolUse` counts.
- **The served model** comes from `$msg->model`.

**`Pricing`**
- Rates in USD per million tokens (input, cache write, cache read, output) and per search, for Opus 5.5, Sonnet 5.5 and Haiku 5.5.
- `cost(array $usage, string $model): float`.
- The admin labels these figures as estimates.

**`Importer`**
- `run(): array $report`.
- For every `content/news/articles/*.json`:
  1. Inserts the `news_imports` row and the article in one transaction. A primary-key conflict means the file was already imported, so it is skipped.
  2. Validates with origin `import` (no references rule).
  3. Sets `published_at` from the file's `published_at` if present; otherwise staggers 10 to 30 minutes apart from now (`news.import_stagger`, false in e2e).
- A failing file is logged and skipped; the others go on.

### Elsewhere

**Controllers**
- `Psc\Controllers\NewsController`: index, category, show, feed and go.
- `NewsMediaController`: public media and admin media.
- `NewsCommentController`: the API, the comment page and its forms.
- `Admin\AdminNewsController`: the articles, images and preview.
- `Admin\AdminNewsCommentController`: moderation.
- `Admin\AdminNewsDeskController`: the desk.

**CLI: `tools/news.php`**
- It exits with 404 outside the CLI and holds `flock(storage/cache/news.lock, LOCK_EX|LOCK_NB)` for the whole tick; a second tick exits straight away.
- `tick` runs these steps, each in its own try/catch:
  1. Reap stale runs.
  2. Import.
  3. Run the queued manual run, or else claim and run the due slot.
  4. Write the heartbeat.
  5. Print one JSON line, with keys named `usage_in` and `usage_out` and never `tokens`, so the Logger does not mask them.
- `run` runs a session now. `import` only imports.

**App.** `App::dbAlive(): Database` checks the connection with `SELECT 1` and reconnects on MySQL. SQLite is never reconnected, which keeps the tests' in-memory database. The pipeline calls it before every database step that follows a model call.

## 5. Routes (declared before the registry loop, which skips `/news/`)

```
GET  /news/                                   NewsController::index           ?q= ?page=
GET  /news/feed.xml                           NewsController::feed
GET  /news/category/{key:[a-z]+}/             NewsController::category        ?page=
GET  /news/go/{id:\d+}/                       NewsController::go              ['nostore']
GET  /news/media/{file:[a-f0-9]{32}-[a-z0-9]+\.(?:webp|jpg)}   NewsMediaController::public
GET  /news/{slug:[a-z0-9-]+}/comment/         NewsCommentController::page     ['session','auth','nostore']
POST /news/{slug:[a-z0-9-]+}/comment/         NewsCommentController::submit   ['session','csrf','auth','nostore']   (action: post|delete|report)
GET  /news/{slug:[a-z0-9-]+}/                 NewsController::show
GET  /api/news/{id:\d+}/state                 NewsCommentController::state    ['api','session','nostore']
POST /api/news/{id:\d+}/comments              NewsCommentController::create   ['api','session','auth','nostore']
POST /api/news/comments/{id:\d+}/delete       NewsCommentController::delete   ['api','session','auth','nostore']
POST /api/news/comments/{id:\d+}/report       NewsCommentController::report   ['api','session','auth','nostore']
POST /api/news/links/{id:\d+}/click           NewsController::click           ['api','nostore']

GET  /portal/admin/news/                      AdminNewsController::index      $ad  (tabs: all, live, scheduled, drafts, held; ?category ?origin ?q ?page)
GET  /portal/admin/news/new/                  AdminNewsController::create     $ad
POST /portal/admin/news/                      AdminNewsController::store      $adPost
GET  /portal/admin/news/{id:\d+}/             AdminNewsController::edit       $ad
POST /portal/admin/news/{id:\d+}/             AdminNewsController::update     $adPost  (action: save|publish|schedule|unpublish|delete)
POST /portal/admin/news/{id:\d+}/images/      AdminNewsController::images     $adPost  (action: add|update|delete|lead)
GET  /portal/admin/news/{id:\d+}/preview/     AdminNewsController::preview    $ad
GET  /portal/admin/news/media/{file:[a-f0-9]{32}-[a-z0-9]+\.(?:webp|jpg)}   NewsMediaController::admin   $ad
GET  /portal/admin/news/comments/             AdminNewsCommentController::index   $ad  (tabs: reported, pending, visible, hidden)
POST /portal/admin/news/comments/{id:\d+}/    AdminNewsCommentController::update  $adPost (action: approve|hide|show|delete|clear_reports)
GET  /portal/admin/news/desk/                 AdminNewsDeskController::index      $ad
POST /portal/admin/news/desk/                 AdminNewsDeskController::control    $adPost (action: pause|resume|run_now)
GET  /portal/admin/news/desk/settings/        AdminNewsDeskController::settings   $ad
POST /portal/admin/news/desk/settings/        AdminNewsDeskController::saveSettings $adPost
GET  /portal/admin/news/desk/calendar/        AdminNewsDeskController::calendar   $ad  (?from=YYYY-MM)
POST /portal/admin/news/desk/calendar/        AdminNewsDeskController::saveCalendar $adPost
GET  /portal/admin/news/desk/prompt/          AdminNewsDeskController::prompt     $ad
POST /portal/admin/news/desk/prompt/          AdminNewsDeskController::savePrompt $adPost (action: save_brief|save_voice|reset_brief|reset_voice|restore)
GET  /portal/admin/news/desk/runs/{id:\d+}/   AdminNewsDeskController::run        $ad
```

**Indexing and discovery**
- `robots.txt` adds `Disallow: /news/go/`. Articles use the real links; the go route serves the feed and no-JS readers.
- The sitemap already lists `/news/` from the registry. It adds:
  - each category page that has at least one live article;
  - each live article, with `lastmod` = `updated_at`.
- `llms.txt` adds an "Industry Updates" section listing the 10 newest articles.

**Admin**
- The admin nav adds `['/portal/admin/news/', 'Industry Updates']`.
- `AdminController::subjectUrl` adds cases for `article` and `comment`.
- The admin overview gains an Industry Updates card. It shows held drafts, reported comments and pending comments, plus the desk state and its last run. These are its own variables; `waitingCounts()` stays unchanged.
- `Analytics::forPage()` returns `''` for every `/portal/admin/` path, so draft headlines never reach Google.

**Audit actions**

| Kind | Actions |
|---|---|
| `news.` | `created`, `updated`, `published`, `scheduled`, `unpublished`, `deleted`, `image_added`, `image_updated`, `image_deleted` |
| `comment.` | `hidden`, `shown`, `approved`, `deleted`, `reports_cleared` |
| `desk.` | `paused`, `resumed`, `run_queued`, `settings`, `calendar`, `prompt` |

## 6. The AI desk

### 6.1 A tick (cron, every 15 minutes; times in Europe/London)

- **Pre-checks.** Nothing runs, and a due slot is recorded as `skipped` with a reason, when:
  - the desk is paused (`paused`);
  - no key is set (`no_key`);
  - the SDK is missing (`sdk_missing`);
  - this month's spend plus the 90th-percentile cost would pass the cap (`budget`).
- **Heartbeat.** It shows on the desk page and in `/health` as `news: ok|stale|off`. It is stale after 35 minutes with the desk on and a key set.
- **Admin emails** (one a day at most, through `notifyAdmins`):
  - a failed run;
  - 3 sessions in a row that published nothing;
  - an article held for review.

### 6.2 A session

The run takes the lease (`Settings::lease`) with a deadline of 40 minutes. Every model call checks the deadline and the per-session ceiling first.

1. **Context.**
   - Articles from the last 120 days (live, scheduled and drafts), newest first, capped at 150. Each as `id | date | story_key | title`.
   - The categories with their meanings.
   - N (`max_per_session`) and `min_importance`.
   - The banned topics.
   - The `watch_urls`, written into the user turn so web fetch may read them.
2. **Research** (`research_model`, effort high).
   - **System prompt:** `Prompts::researchRules()`, then the admin's brief.
   - **Tools:**
     - `web_search_20260209`: `max_uses` = the remaining searches, `blocked_domains` = the banned domains, `user_location` = approximate GB, Europe/London.
     - `web_fetch_20260209`: `max_uses` = the remaining fetches, the same `blocked_domains`, `max_content_tokens` 8000, citations off.
   - **User turn:**
     - today's London date and time, and the session's name;
     - the context;
     - the watch URLs;
     - the ask: find up to N + 3 candidate developments from the last 7 days (14 for legal, regulatory and standards news), then write the brief between `=== BRIEF ===` and `=== END ===`.
   - **The brief gives, for each candidate:**
     - a headline and a story key;
     - the existing article ids it duplicates or follows;
     - importance from 1 to 5, with the reason;
     - the date of the newest source;
     - verified facts and speculation, each followed by the URL it rests on;
     - who it affects.
   - `max_tokens` 32000.
   - **After research:** the brief and the refs go on the run row, with the URLs numbered `S1…Sn` in first-seen order.
3. **Select** (`compose_model`, effort medium, schema §6.4 `select`).
   - **Input:**
     - the brief;
     - the refs list (`[S3] title | host | page age | via`);
     - the context;
     - N and `min_importance`.
   - **Output:** `considered`, a list of `{headline, story_key, importance, newest_source_date, decision, duplicate_of, follow_up_of, refs, reason}`. The decision is one of `write|duplicate|low_importance|out_of_window|banned|not_relevant`.
   - **The server then:**
     - keeps the `write` candidates with importance ≥ `min_importance`;
     - drops any newest-source date outside the window;
     - runs the server dedup (§7) on headline + story key + refs;
     - takes the top N by importance.
   - **Nothing left:** the run ends `skipped` with the summary "Slow news: …", built from the decisions. `candidates` is the length of `considered`.
4. **Compose** (`compose_model`, effort high, schema §6.4 `article`, `max_tokens` 24000). One call per selected candidate, with the same cached system prompt.
   - **System prompt:** `Prompts::composeRules()`, then the admin's voice, then the phrases to avoid.
   - **User turn:**
     - the brief;
     - the refs list;
     - this candidate (headline, story key, refs, follow-ups with their slugs and titles);
     - the context.
   - **Failure:** a refusal, a `max_tokens` stop or invalid JSON drops that article and logs the step. The others carry on.
5. **Validate** each article with origin `ai` (§7).
   - **Rejected:** logged and dropped.
   - **Phrases to avoid only:** one repair call (`repairRules`, the article JSON and the offending phrases; it returns the same schema), then validate again. Still tripped: hold as a draft.
6. **Check** (Haiku 5.5, schema `check`). Input: the banned topics, plus the article's title, dek, body and impact. Output: `{banned_topics: [string], names_private_individual: bool, alleges_wrongdoing: bool, reason: string}`.
   - Any banned topic rejects the article.
   - Either flag holds it as a draft, with `review_reason`.
   - A refusal or empty data holds it (fail closed).
   - A banned-topic term match in plain text runs first, at no cost.
7. **Publish.** One transaction, still under the lease, with dedup checked again against the database.
   - With `auto_publish` on (the default), every article not held is `published`. The first goes live at the time of the transaction; each next one 10 to 30 minutes after the one before (`random_int(10, 30)` minutes).
   - Held articles and every article when `auto_publish` is off are drafts.
   - The run records `articles`, `held`, its usage, its cost, a one-line summary and the log.
8. **Failure.**
   - An exception, an API error, a lost lease or the deadline ends the run as `failed` with the reason. The run reconnects first (`dbAlive`). Nothing is half-published.
   - Failed slots are not retried, because a retry would bill the searches again. The admin can press "Run a session now".

### 6.3 Prompt texts

**Brief and voice**
- `content/news/brief.md` is the default research brief and `content/news/voice.md` the default voice.
- The admin edits both on the desk's prompt page, which has a version list, restore and reset to default.
- The fixed rules are shown read-only beside them, with a note that URLs written in the brief cannot be fetched; full URLs go in `watch_urls`.

**The fixed rules**
- Real sources only, and refs only.
- The verified/speculation split.
- No duplicates; follow-ups declared, with new sources.
- The categories.
- No email addresses or phone numbers.
- No invented quotes; quote only words seen in a source.
- The site's own products are named only where directly relevant: once, and plainly.
- UK English.
- Never an em dash or a spaced en dash.
- The JSON shape.

**Phrases to avoid**
- An editable list, checked in the standfirst, body, impact and verdict note only. Quotes and link text are skipped.
- The defaults:
  - delve
  - game-changer
  - game changer
  - in today's fast-paced
  - ever-evolving
  - navigate the complexities
  - seamlessly
  - unlock the potential
  - harness the power
  - in conclusion
  - it's worth noting
  - it is worth noting
  - a testament to
  - the landscape of
  - let's dive in
  - buckle up
  - moreover
  - furthermore
  - in the realm of
  - tapestry
- "Seamless switcher" is a product category and stays allowed.

### 6.4 Schemas and the article JSON

- Every object has `additionalProperties: false`. Every property is required; an empty value is `""` or `[]`.
- Enums are used where values are fixed. There are no numeric or string-length constraints; `Validator` checks lengths.
- **`select`:** `{considered: [{headline, story_key, importance (enum 1..5), newest_source_date, decision (enum), duplicate_of: [int], follow_up_of: [int], refs: [string], reason}]}`.
- **`check`:** `{banned_topics: [string], names_private_individual: bool, alleges_wrongdoing: bool, reason: string}`.
- **`article`** (compose and repair):

```json
{
  "title": "Right to work checks now reach freelancers, and the liability runs up the chain",
  "seo_title": "Right to work checks now cover freelance crew",
  "dek": "One or two sentences that say what happened and why a crew chief should care (50 to 320 characters).",
  "summary": "The meta description, 50 to 155 characters.",
  "body": "Markdown. The story, the context, what it means in practice. ## and ### headings. Links as [text](S3) or to /news/{slug}/.",
  "confirmed": [{"text": "A verified fact, one sentence.", "ref": "S1"}],
  "speculation": [{"text": "A claim not yet confirmed, and whose claim it is.", "ref": "S4"}],
  "impact": "Markdown. How it applies to freelance and corporate event delivery, or plainly that it does not.",
  "verdict": "adopt | test | watch | skip | note",
  "verdict_note": "What to test or adopt, or why to wait.",
  "categories": ["legal", "freelance"],
  "tags": ["right to work", "home office"],
  "sources": [{"ref": "S1", "publisher": "GOV.UK", "kind": "primary | press | report | standard | other"}],
  "follow_up_of": [12],
  "story_key": "uk-right-to-work-checks-extended-to-freelancers"
}
```

**Import files** use the same shape, with these differences:
- URLs go in place of refs: `confirmed[].url`, `speculation[].url`, `sources[]` as `{url, title, publisher, kind}`, and inline links as full URLs.
- `follow_up_of` holds slugs.
- A required `key`.
- An optional `published_at` (ISO 8601).
- An optional `byline`.
- An optional `ai_written` (default `true`).

**Verdict labels**

| Value | Label | Callout |
|---|---|---|
| `adopt` | Worth adopting | `c-callout--success` (icon, then the word) |
| `test` | Worth testing | `c-callout--info` |
| `watch` | One to watch | `c-callout--warning` |
| `skip` | Safe to skip for now | plain `c-callout` |
| `note` | Good to know | `c-callout--info` |

## 7. Validator rules

| Rule | Origin | Detail | Admin wording |
|---|---|---|---|
| `title` | all | 10 to 160 characters, single line | Enter a headline of 10 to 160 characters. |
| `seo_title` | all | Up to 60 characters. Empty: `Prose::cut(title, 60)` | |
| `dek` | all | 50 to 320 characters on publish | Enter a standfirst of 50 to 320 characters. |
| `summary` | all | 50 to 155 characters. Empty: `Prose::cut(plain(dek), 155)`, padded with the title if it is under 50 | |
| `body` | all | 300 to 20000 characters on publish (manual: 50 to 20000) | |
| `categories` | all | 1 to 3 on publish, each a known key; the first is the primary | Choose one to three categories. |
| `tags` | all | Up to 8, each 2 to 40 characters | |
| `sources` | all | At least 1 on publish, at most 12; http or https; title up to 300 characters | |
| `confirmed`, `speculation` | all | Up to 12 items each. Each item up to 400 characters, with a URL or ref that is also a source | |
| `verdict` | all | One of the five; may be empty only on manual articles | |
| `refs` | ai | Every `ref` exists. Unknown refs are dropped from sources and items, and unknown inline links are unlinked, keeping the text. Each confirmed ref must be `via=fetch` or `cited`, otherwise the item moves to speculation. Links to the site's own host, and the stored sources of the `follow_up_of` articles, are allowed. Rejected if no valid source remains. | |
| `banned_domain` | all | No source, link or `credit_url` on a banned domain. AI: dropped like an unknown ref | That site is on the banned list. |
| `banned_topic` | ai, import | No banned topic as a whole-word, case-insensitive match in the title, dek, body, impact or tags. Manual: a warning flash only | |
| `duplicate` | ai, import | Duplicate when either: the `story_key` equals one from the last 365 days; or any source URL key matches a source of an article from the last 120 days; or the Jaccard overlap of lower-case words across title + dek + tags is 0.5 or more against an article from the last 60 days. A match against an article in `follow_up_of` is allowed only if the new article brings at least one source URL none of them used. A match against an article that was not in the context, with new sources, becomes an automatic follow-up (logged). Otherwise rejected | |
| `follow_up` | all | Every id or slug exists | |
| `contact` | all | No email address and no `mailto:` in any text field | Remove the email address; link to a page instead. |
| `dashes` | all | Not a rejection: `undash` runs on save. A manual save that replaced any shows a flash | Em dashes were replaced with commas. |
| `tells` | ai | Phrases to avoid: repair, then hold (§6.2 step 5) | |
| Drafts | manual | A draft saved by an admin needs only a headline. The full rules apply on publish and schedule | |

## 8. Settings (`news_settings`, edited on the desk pages)

| Name | Default | Admin control |
|---|---|---|
| `enabled` | `true` | Pause and continue (desk page) |
| `max_per_session` | `3` | Articles per session, 1 to 5 |
| `min_importance` | `3` | How newsworthy a story must be, 1 to 5 |
| `slots` | `[{"time":"06:00","days":[1,2,3,4,5,6,7]},{"time":"14:30","days":[1,2,3,4,5,6,7]}]` | Sessions: 6 rows, each a time, 7 weekday ticks and a Remove tick (blank rows ignored, no JS needed). The page shows the count per day and per week |
| `date_overrides` | `{}` | Calendar: date ⇒ `off` or `on`. `on` runs every session that day. Past dates are pruned on save |
| `auto_publish` | `true` | Publish automatically, or keep every AI article as a draft for review |
| `research_model` | `claude-sonnet-5-5` | Opus 5.5 or Sonnet 5.5, with the estimated cost |
| `compose_model` | `claude-opus-5-5` | Opus 5.5 or Sonnet 5.5, with the estimated cost |
| `max_searches` | `12` | Web searches per session, 1 to 30 |
| `max_fetches` | `6` | Pages read per session, 0 to 20 |
| `monthly_budget_usd` | `100` | Monthly spend cap (estimated), 5 to 2000 |
| `watch_urls` | `[]` | Full URLs the desk should check every session, one per line, up to 30 |
| `banned_domains` | `[]` | One per line, normalised, up to 200 |
| `banned_topics` | `[]` | One per line, up to 100, each up to 80 characters |
| `avoid_phrases` | §6.3 | One per line, up to 200 |
| `ai_note` | "Researched and written by our AI desk from the sources listed below. Spotted something off? Tell us and we will put it right." | Up to 300 characters; "Tell us" links to `/contact/` |
| `comments` | `open` | `open`; `approval` (new comments wait for an admin); `closed` |

`lease`, `heartbeat` and the `email_*` timestamps are internal rows that the admin never sees.

## 9. Comments

- **Who.** Any signed-in, active account.
  - The comment shows `Comments::publicName()`, the first name and last initial. The comment box says which name will be shown.
  - Admin comments carry a "Patterns team" badge, a separate element, not part of the name. A person's name can never produce that badge.
- **Limits.**
  - 2 to 2000 characters, and at most 2 links.
  - An email address is refused, with a message that says why.
  - The same body from the same person on the same article within 24 hours is passed on once.
- **Rate limits.**
  - `news-comment:<uid>`: 5 per 10 minutes and 30 per day.
  - `news-comment-ip:<ip>`: 20 a day (the bucket is hashed; no IP is stored).
  - `news-report:<uid>`: 20 a day.
- **Pending comments.** A comment waits as `pending` when:
  - the setting is `approval`; or
  - the account is younger than 24 hours and the comment holds a link.
  The author sees it, marked "Only you can see this until it is approved".
- **Display.** Plain text (`nl2br(e())`), never Markdown, and no live links. Each comment shows its time.
- **Reports.**
  - Any signed-in person can report a comment once, but not their own.
  - At 3 reports a comment is hidden until an admin looks. Staff comments are never hidden automatically.
  - The first report emails the admins.
  - Admins can approve, hide, show, delete, or clear the reports.
- **Deleting.** A person can delete their own comment.
- **Closed.** When an article's `comments_open` is off, or the setting is `closed`, the box says comments are closed and the existing comments stay.
- **Only on live articles.** The API, the comment page and `state` all answer 404 for an article that is not live.
- **The cached page.**
  - The comments section carries `data-rendered-at`.
  - `state` returns `{signed_in, can_comment, closed_reason, name, mine: [the person's comments since that stamp, pending included], removed: [ids hidden or deleted since], own: [ids]}`.
  - `news.js` merges these in, shows delete on the person's own comments and report on everyone else's.
  - `signed_in: false` shows the sign-in link, so a stale `psc_li` hint does no harm.
- **Without JavaScript.**
  - The box links to `/news/{slug}/comment/`. That page has the form and the person's own comments, each with a delete form, plus report buttons on the others.
  - Posting returns 303 to that page, never to the cached article, with a link back.
  - The page says that sessions end after 2 hours without activity.
- **Signing in.**
  - The call to action follows `security.registration`: "Sign in or create an account to comment", or "Sign in to comment" when registration is by invitation.
  - The comment page is a GET behind `auth`, so `next=` brings the person back after signing in.
  - Registration carries `next` through register, check email and verify (kept in the session), and the confirmation page shows "Continue to the article".
  - `news.js` keeps an unsent comment in `sessionStorage`, keyed by article, and restores it.
- **Papers.** The privacy policy, the terms (comment rules), the cookie notice (`psc_li` is also used to offer the comment box), the account page text and the account-deleted email all say this.

## 10. Public pages

**Index `/news/`**
- A hero with the h1 "Industry Updates" and a lede.
- The category list:
  - `<nav aria-label="Categories">` with a wrapping list (`news-cats`, 44px targets), showing "All" and each category that has live articles, with counts;
  - below 40rem, a GET form with a `<select>` and a Show button instead.
- A search form (GET `?q=`, up to 100 characters).
- The newest article as a wide card, then a grid of 12 per page, then a public pager (`c-pager`).
- **Indexing:**
  - pages 2 and up are `noindex, follow` (`Seo::noindexFollow()`);
  - `?q=` pages are `noindex, follow` and `private, no-store`;
  - a page number past the end is a 404, and `?page=1` is a 301 to the clean URL.

**Category `/news/category/{key}/`**
- The same layout, with the category's title, description and intro.
- A known category with no live articles: 200, the empty state, `noindex, follow`.
- An unknown key: 404.

**Article `/news/{slug}/`**, in order:
1. Breadcrumbs: Home › Industry Updates › the primary category › `seo_title`.
2. The category tags, the h1 `title`, and the `dek`.
3. A meta line: the date and time in London, the read time, "Updated" if changed, and the byline.
4. A follow-up banner (a `c-callout`): "Follow-up to:", linking to the earlier articles.
5. The lead image (`<figure>` with `srcset`, width, height, alt, caption, credit), or the category artwork.
6. The body.
7. "What's confirmed": each item with a "Confirmed" done badge (green, with the word) and its source link.
8. "Still speculation", only if any: each item with an "Unconfirmed" badge (amber, with the word).
9. "What it means for freelancers and corporate events".
10. The verdict callout.
11. The other images.
12. "Sources": a numbered list of real links (`rel="nofollow noopener"`, `target="_blank"`, `data-news-link`), each with its publisher, host and kind.
13. "Later updates": the articles that follow up this one.
14. The AI note, on AI articles.
15. Comments.

**Article head and structured data**
- `<title>` from `seo_title`, the meta description from `summary`, and `og:title` from `title` (a new `Seo::$ogTitle`).
- `ogType = 'article'`. `og:image` is the lead image's `-og.jpg`, with `og:image:alt` from its alt; otherwise the news share card.
- `article:published_time`, `article:modified_time`, `article:section` and `article:tag` go in through `stack('head')`.
- **JSON-LD**, on top of `baseGraph()`:
  - `NewsArticle`, `@id` = `url#article`, with:
    - `headline` = the title, cut to 110;
    - `description`;
    - `datePublished`, `dateModified`;
    - `articleSection` = the primary category's name;
    - `keywords`;
    - `image`;
    - `author` = `{"@id": org}` (or the Person `@id`, §2.7);
    - `publisher` = `{"@id": org}`;
    - `isBasedOn` = the source URLs;
    - `mainEntityOfPage` = `{"@id": url#webpage}`.
  - `Schema::webPage(... 'WebPage')`.
  - The BreadcrumbList.

**Feed**
- RSS 2.0 with the 30 newest live articles.
- Item links go to the article. The description is the summary plus the source links through `/news/go/{id}/`.
- Every news page carries `<link rel="alternate" type="application/rss+xml">`.

**Click counting**
- `news.js` sends `fetch('/api/news/links/{id}/click', {method: 'POST', keepalive: true, headers: {'X-Requested-With': 'fetch'}})` on click and on middle click.
- The `api` middleware means same origin only.
- A click is not counted when:
  - the request is a HEAD;
  - the user agent looks like a bot (`bot|crawl|spider|slurp|preview|fetch|monitor|curl|wget|python|httpclient|headless`);
  - it is past the limit of one per link per IP per 10 minutes (the RateLimiter hashes it).
- `/news/go/{id}/` redirects to the stored URL only, never to a URL from the request, and counts by the same rules. A link whose article is not live gives 404.
- The admin calls the counts "approximate".

**Images**
- `/news/media/{file}` never starts a session. It serves only images of live articles, with `public, max-age=86400, s-maxage=3600`, `nosniff` and `Content-Security-Policy: default-src 'none'`. Anything else gets a 404 with no-store.
- The admin route serves any image with `private, no-store`.

**Cache and layout**
- News HTML is `public, max-age=120, stale-while-revalidate=600`.
- The `l-wrap` holds `overflow-wrap: anywhere` for long URLs.
- Code blocks are never rendered.

**Design**
- `public/assets/css/pages/news.css` uses the `news-` prefix and tokens only. Cards follow the stretched-link card (`support-wfcard`).
- **Category artwork:**
  - Monochrome `<symbol>`s in `public/assets/img/news-art.svg`, drawn in `currentColor` with `--text-3` and `--line` through classes, used with `<use>` and `aria-hidden`.
  - No SMPTE bars, no product colours, no inline styles.
  - Motifs: a monitor wall for Video, a waveform for Audio, beams for Lighting, a truss for Staging, a cue stack for Production, a clock for Showcalling, a signal for Streaming, nodes for Automation, a grid for Interactive, a spark for AI, a network for Technology, a person for Freelance, a shield for Legal and a lightbulb for Tips.

## 11. Admin pages

### Articles list
- Tabs: All, Live, Scheduled, Drafts and Held, each with its count.
- Filters: category, origin and search.
- A 5-column stacking table: Headline, Status, Origin, Published, Comments.
- A "Write an article" button.

### Editor (new and edit)

**Order of the page**
1. The error summary.
2. The headline, then the SEO title and the meta description (optional, with live counters).
3. The standfirst, the body, and "What's confirmed" and "Still speculation" as rows of text and URL. Each row has an id (`confirmed-3-url`) so the error summary can link to it. The form renders 6 rows plus the existing ones; JS adds more.
4. Impact, the verdict (radio buttons) and the verdict note.
5. The categories (checkboxes, 1 to 3, the first ticked is primary) and the tags.
6. The sources, as rows of URL, title, publisher and kind.
7. "Follow-up of": a multiple select of the 60 most recent articles.
8. The byline, and whether comments are open.
9. The buttons: save draft, publish now, schedule (a date and time in London) and unpublish.

**Keeping the work**
- A draft needs only a headline.
- After a failed save, the editor keeps its own copy in the session under `news_draft.{id|new}`. `keepOld` drops arrays and cuts values at 5000 characters, so it cannot be used here. The copy is cleared on success.

**Images**
- The images section comes after the article form, with the note "Save your changes first".
- `admin-news.js` marks the form dirty, disables the image buttons while it is dirty, and warns before leaving the page.
- Each image has its own form: upload (alt text required), edit the alt text, caption and credit, make it the lead, or delete it.
- Note: "Only upload images you have the right to use: your own, a press kit that allows editorial use, or licensed."

**Also on the edit page**
- Link clicks, approximate: per link, in total and for the last 30 days.
- For AI articles, the run that wrote it and the `review_reason`.
- A preview link.
- Delete, with a confirm box.

**Takedown actions** (unpublish, delete, image delete) flash: "It can take up to 2 minutes to leave the CDN. Run Flush CDN to remove it at once."

### Preview
- Renders the article template with a banner reading "Preview: not published".
- No analytics. Images come from the admin media route; links are the real ones.
- It is an exemption from the admin shell fences, named in the AdminTest exemption list.

### Comments
- Tabs: Reported, Pending, Visible and Hidden.
- Each comment shows its article, the author (full name and email, admin only) and its actions.

### Desk

**Main page**
- The state: running, or paused with the reason (no key, cap reached, paused, SDK missing).
- The last tick (stale after 35 minutes, with a warning to check the cron line).
- The next 6 sessions.
- This month's estimated spend against the cap.
- Buttons: "Pause the desk" or "Continue the desk", and "Run a session now" ("It starts within 15 minutes").
- The last 20 runs (time, kind, status, articles, held, cost, summary), each linking to its page. A run's page shows the log steps with their timings, the candidates with their decisions and reasons, the rejections with their rules, the references and the usage.

**Other desk pages**
- **Settings:** the fields in §8.
- **Calendar:**
  - One `<table>` per month for 3 months, with a caption and weekday column headers (`th scope="col"`).
  - Each day is a checkbox labelled with the full date ("Thursday 22 October 2026"). Ticked means sessions run.
  - A day that differs from its weekday default shows an icon and the word "Changed". Each month has a "Reset this month" button.
  - The per-day session count is shown.
  - Below 40rem the table stacks as a list.
- **Prompt:** the brief and the voice, each with save, reset to default and a version list with restore. The fixed rules are shown read-only.

### The fences every admin page passes
- Exactly one `h1.pt-title`.
- No `style=` and no `<script` in main.
- Stacking tables labelled.
- Every GET route in `adminPages()`.

## 12. Tests

**Unit**
- `Prose`, `Urls` (userinfo, IDN, trailing dot, sorting), `Markdown` (normalisation, headings, `data-news-link`, no code blocks), `Validator`, `Schedule` (BST and GMT dates, overrides, the 60-minute gap), `Pricing`, `Importer`.
- `AnthropicNewsModel` against a Guzzle `MockHandler`, checking:
  - the request JSON (tools, `blocked_domains`, `max_uses`, `user_location`, `output_config.format`, `fallbacks` and the beta header, `cache_control`);
  - a `pause_turn` round trip that carries the container id;
  - the remaining-uses budget;
  - the final "write now" message;
  - refs from search, fetch and citations;
  - usage summing.

**Feature: public**
- The pages, the feed, the sitemap, `robots` and `llms`.
- `go` and `click`: the redirect, the count, bots, the rate limit, not-live articles.
- `media`: live, draft and admin.
- No cookies on any `/news/*` GET, even with an admin's cookie jar.
- Search and pagination indexing.
- The SiteTest fences still pass.

**Feature: comments**
- The API, `state` merging, the no-JS page with post, delete and report.
- The limits and the pending rules.
- Reports, auto-hide and staff.
- Moderation and approval mode.
- Deleting one's own comment.
- Public names (including a name that is an email address).
- 404 on articles that are not live.
- Export and account deletion.
- `next` kept through registration.

**Feature: admin**
- Create, save a draft with a headline only, validate on publish, keep the draft, publish, schedule, unpublish, delete.
- Images: add, update, lead, delete; refusing oversized, non-image and polyglot files.
- Preview, the audit, no analytics on admin pages.

**Feature: desk**
- Settings validation, the calendar, prompt versions, pause and resume, run now (refused while a run is queued).

**Feature: pipeline**, with a fake `NewsModel`
- The happy path, with staggered times.
- Slow news skipped.
- Refs: an unknown ref dropped, no valid source rejected, an unfetched confirmed item moved to speculation.
- A banned domain and a banned topic.
- A duplicate refused, a follow-up with new sources allowed, a follow-up without new sources refused.
- The repair path and the hold path.
- The check flags hold the article; a check refusal holds it.
- A compose failure costs one article and not the others.
- Failure, cap, paused, no key and SDK missing paths.
- The lease and concurrency: a second run is refused.
- A slot is claimed exactly once.
- The 60-minute gap after a manual run.
- The database connection dropped between calls, with the cost still recorded.
- The log line has no `***`.

**Feature: importer**
- Once; the tombstone; an invalid file skipped while the others import.
- Every `content/news/articles/*.json` in the repository validates (a fence).

**Fences**
- No stored article text contains U+2014.
- No public template, content file, knowledge source, email template or JS string contains an em dash. The existing ones are removed in their own unit.
- `Knowledge::compile` no longer emits a spaced em dash (U+2014) between a name and its line.
- Every new admin GET route is in `adminPages()`.

**e2e**
- `config.e2e.php` sets `news.import_stagger = false`, and the webServer command runs `php tools/news.php import` first, so every launch article is live and `pages.spec` checks them all.
- A `news.spec.mjs` covers the category filter, an article, and a comment round trip, signed in.

## 13. What this does not do

- No email newsletter, and no social posting.
- The AI desk takes no images from sources. Press images can be added by hand, with their credit.
- No CDN purge from the server; the API token lives only in GitHub. The short news cache and the takedown flash are the mitigation, and the admin can run the "Flush CDN" workflow.
- No full-text search engine. Search is `LIKE` over the title and the standfirst.
- **AI articles go live without a human check by default.** This is what the owner asked for. Anything risky is held as a draft (§6.2, step 6). `auto_publish` off holds every AI article for review.
