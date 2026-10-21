# Patterns Show Control — website

The marketing, support and client-portal website for the Patterns Show Control ecosystem — **Patterns**,
**StageWatch**, **AGMessage** and **PlayMap** — at [patternsshowcontrol.co.uk](https://patternsshowcontrol.co.uk).

- **Public site**: product, features, licensing and workflow pages, support guides and FAQ, contact — server
  rendered, cacheable, with full SEO (titles, canonical URLs, Open Graph, JSON-LD, sitemap, `robots.txt`,
  `llms.txt`).
- **Client portal**: accounts with email confirmation, downloads, licence requests and licence files, bug reports,
  questions, a feature-request board with votes, and administration.
- **Support assistant**: a chat on every page, answered by Claude and kept strictly to the Patterns Show Control
  software at a surface level (it refuses code, commands and internals, and anything off-topic).

## Stack

PHP 8.2+ with no framework, MySQL (SQLite for development and tests), plain modern CSS and small vanilla
JavaScript modules. The only Composer packages are the official Anthropic PHP SDK and Guzzle, used by the assistant.
Hosted on Hostinger (LiteSpeed): see [docs/DEPLOY.md](docs/DEPLOY.md).

```
app/            application code (Psc\ namespace), routes.php, helpers.php
config/         defaults.php (every setting), config.example.php (the server's file)
content/        the site's words and facts as data (docs/CONTENT.md)
database/       migrations for MySQL and SQLite
knowledge/      extra notes for the support assistant
public/         the web root: index.php, assets
templates/      layouts, pages, portal, admin, emails
tests/          PHPUnit (unit + feature)      tools/e2e/   Playwright browser checks
tools/          dev router, cron, release build, MySQL check
docs/           DEPLOY, CONTENT, DESIGN, PORTAL-UI
```

## Develop

```sh
composer install
composer install --working-dir=tools/phpunit
cp config/dev.example.php config/local.php
PSC_NO_CONFIG_MOVE=1 php -S 127.0.0.1:8080 tools/dev-router.php      # http://127.0.0.1:8080
tools/phpunit/vendor/bin/phpunit                                     # unit and feature tests
cd tools/e2e && npm ci && npm test                                   # browser checks (starts its own server)
```

Emails in development are written to `storage/mail/*.eml`. Register with `admin@example.test` (listed in
`config/dev.example.php`) to get an administrator account.

## Change the content

Prices, features, workflows and FAQ answers live in `content/`; the pages, the structured data and the
assistant's knowledge all read the same files. See [docs/CONTENT.md](docs/CONTENT.md).
