# Patterns Show Control website

The marketing, support and client-portal website for the Patterns Show Control ecosystem (**Patterns**,
**StageWatch**, **AGMessage** and **PlayMap**) at [patternsshowcontrol.co.uk](https://patternsshowcontrol.co.uk).

- **Public site**: product, features, licensing and workflow pages, support guides and FAQ, contact; server
  rendered, cacheable, with full SEO (titles, canonical URLs, Open Graph, JSON-LD with the organisation's `sameAs`,
  sitemap, Search Console and Bing verification, `robots.txt`, which asks model-training crawlers to stay away and
  welcomes search and AI answer engines, `llms.txt`).
- **Client portal**: accounts with email confirmation (its link opens a page whose button confirms, so a mail
  scanner opening it changes nothing), downloads, licence requests and licence files, bug reports,
  questions, a feature-request board with votes, and administration.
- **Support assistant**: a chat on every page, answered by Claude and kept strictly to the Patterns Show Control
  software at a surface level (it refuses code, commands and internals, and anything off-topic).
- **Industry Updates** (`/news/`): AV and live event industry news in fourteen categories, each with its own hub,
  feed and share card, and the articles in sitemaps of their own. An AI desk researches and writes up to three
  articles a session (by default twice a day, around 06:00 and 14:30 London time), each split into what is
  confirmed and what is still speculation, with tracked links to its sources; anything risky waits for a person.
  Administrators write, edit, schedule and take down articles with images, run and pause the desk, and moderate
  the comments signed-in clients leave. Each tick tells IndexNow and a WebSub hub what went live, changed or went;
  the feeds carry each article's picture; the hubs carry follow links and the articles share links (plain links, no
  third-party script); the home and product pages show the newest articles; an editorial standards page
  (`/news/standards/`) says how the section is made and corrected, with dated corrections on the articles; and a
  weekly email digest (`/digest/`, or a tick on the account page or at registration: double opt-in by a button on the
  page its confirmation email opens, a link scanner never confirms or unsubscribes anyone, RFC 8058 one-click
  unsubscribe in mail apps and a one-button page from every digest, sent by the news tick in batches within the
  mailbox's limits) carries the week's new articles to those who ask for it. See [docs/NEWS.md](docs/NEWS.md).

## Stack

PHP 8.4+ with no framework, MySQL (SQLite for development and tests), plain modern CSS and small vanilla
JavaScript modules. The only Composer packages are the official Anthropic PHP SDK and Guzzle, used by the assistant
and the Industry Updates desk. Hosted on Hostinger (LiteSpeed): see [docs/DEPLOY.md](docs/DEPLOY.md).

```
app/            application code (Psc\ namespace), routes.php, helpers.php
config/         defaults.php (every setting), config.example.php (the server's file)
content/        the site's words and facts as data (docs/CONTENT.md)
database/       migrations for MySQL and SQLite
knowledge/      extra notes for the support assistant
public/         the web root: index.php, assets
templates/      layouts, pages, portal, admin, emails
tests/          PHPUnit (unit + feature)      tools/e2e/   Playwright browser checks
tools/          dev router, cron, the news desk (news.php), release build, MySQL check
docs/           DEPLOY, CONTENT, DESIGN, PORTAL-UI, NEWS
```

## Develop

```sh
composer install
composer install --working-dir=tools/phpunit
cp config/dev.example.php config/local.php
PSC_NO_CONFIG_MOVE=1 php tools/news.php import                       # the news launch articles, 10 to 30 min apart
PSC_NO_CONFIG_MOVE=1 php -S 127.0.0.1:8080 tools/dev-router.php      # http://127.0.0.1:8080
tools/phpunit/vendor/bin/phpunit                                     # unit and feature tests
cd tools/e2e && npm ci && npm test                                   # browser checks (starts its own server)
```

Emails in development are written to `storage/mail/*.eml`. Register with `admin@example.test` (listed in
`config/dev.example.php`) to get an administrator account.

## Change the content

Prices, features, workflows and FAQ answers live in `content/`; the pages, the structured data and the
assistant's knowledge all read the same files. See [docs/CONTENT.md](docs/CONTENT.md).
