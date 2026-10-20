# Deploying to Hostinger

The site is plain PHP 8.2+ with MySQL. On Hostinger Business (LiteSpeed) the whole built site sits in
`public_html`; the root `.htaccess` lets only `public/` be reached and sends every other request to
`public/index.php`. Secrets and runtime data live **outside** `public_html`, in `../patterns-data/`.

```
/home/<user>/domains/patternsshowcontrol.co.uk/
├── public_html/              ← the built site (deploy branch or release zip)
│   ├── .htaccess  public/  app/  templates/  content/  vendor/  …
│   └── downloads-vault/      ← release files (served only through the portal)
└── patterns-data/            ← created on first request; never deployed over
    ├── config.php            ← the server configuration (secrets)
    └── storage/              ← sessions, logs, cache, uploads, licence files
```

## 1. One-time setup

1. **Domain.** Register `patternsshowcontrol.co.uk` (the plan's free domain) and keep it on Hostinger's
   nameservers.
2. **Website.** hPanel → Websites → Add website → *Empty PHP/HTML website* on the Business plan, domain
   `patternsshowcontrol.co.uk`. Wait for the free SSL to show *Active*; turn on **Force HTTPS**.
   Delete the placeholder files in `public_html` (the first Git deploy needs an empty folder).
3. **PHP.** hPanel → Advanced → PHP Configuration: 8.3 (the version is shared by every site on the plan, including
   agmessage.com). Keep `pdo_mysql`, `curl`, `intl`, `mbstring`, `fileinfo` on; set `expose_php` Off.
4. **Database.** hPanel → Databases → MySQL: create a database and user (long random password). The site connects to
   `127.0.0.1:3306` and creates its tables itself on the first request (migrations run under a lock).
5. **Email.** hPanel → Emails: create `support@` and `no-reply@` mailboxes, click *Connect automatically* (MX, SPF,
   DKIM) and add a DMARC TXT record (`_dmarc`, `v=DMARC1; p=none; rua=mailto:support@…`, tighten later). PHP's
   `mail()` is capped at 10 a minute, so the site sends through SMTP (`smtp.hostinger.com:465`, SSL).
6. **Anthropic API key** for the support assistant: create a key in the Anthropic Console for a dedicated
   workspace with a monthly spend limit. The assistant uses Claude Sonnet 5.5 for answers and Claude Haiku 5.5 for
   its scope check; the site also stops by itself at `chat.daily_token_budget` tokens a day.
7. **Configuration.** Copy `config/config.example.php`, fill it in (database, SMTP, Anthropic key,
   `app.secret` = 64 random hex characters, `app.admin_emails` = your address) and put it at
   `/home/<user>/domains/patternsshowcontrol.co.uk/patterns-data/config.php` with File Manager (create the
   folder), or upload it as `public_html/config/local.php`: on the first request the site moves it outside
   `public_html` by itself. It is never committed.
8. **Git deployment.** In GitHub, CI builds the site on every push to `main` and commits the result to the
   `deploy` branch (production dependencies included, no tests, no composer files, so the server never runs
   Composer). In hPanel → Advanced → Git: *Connect with GitHub*, repository `jammin808/Patterns-Website`, branch
   `deploy`, directory `public_html`, auto-deploy on. Untracked files on the server (uploads in
   `downloads-vault/`) survive every deploy.
   Without Git: download the `patterns-site` artifact from a CI run (or run `tools/build-release.sh`), upload the
   zip to `public_html` with File Manager and extract it there. The data folder outside `public_html` is never
   touched by either route.
9. **Cron.** hPanel → Advanced → Cron Jobs, daily (UTC):
   `/opt/alt/php83/usr/bin/php /home/<user>/domains/patternsshowcontrol.co.uk/public_html/tools/cron.php`
10. **Your admin account.** Register at `/portal/register/` with an address listed in `app.admin_emails` and
    confirm the email: that account is an administrator.

## 2. After each deploy

- Hostinger's CDN caches public pages for a few minutes (they send `Cache-Control: public, max-age=300`); flush it
  in hPanel → Performance → CDN after a content change you need live at once. Portal pages, downloads and the
  assistant always send `private, no-store`.
- Smoke test: `/health` answers `{"status":"ok"}`; a signed-out request to `/portal/downloads/1/` redirects to the
  login page; `curl -N -X POST https://…/api/chat` from another origin is refused with 403.
- If the assistant's answers arrive all at once instead of streaming, the CDN is buffering the stream: turn the CDN
  off for the domain, or exclude `/api/chat`.

## 3. Everyday tasks

| Task | Where |
|---|---|
| Publish a release | Portal → Admin → Releases → New. Upload the installer in the form, or upload it first to `public_html/downloads-vault/incoming/` with File Manager or FTP (large files) and pick it from the list. Access: *Registered* (any signed-in client) or *Licensed* (clients with a licence for that product). |
| Issue a licence | Portal → Admin → Licence requests → open the request → set *Awaiting payment* (the client is emailed), then upload the `.dat` file(s) from KeyGen and set *Issued*: the client is emailed and downloads the file from the portal. |
| Answer a bug or feature request | Portal → Admin → All tickets → open it → reply (or add an internal note) and set the status. |
| Read contact messages | Portal → Admin → Messages (also emailed to `mail.admin_to`). |
| Review the assistant | Portal → Admin → Assistant chats: every conversation, its scope verdicts and thumbs up/down. |
| Change prices, features, FAQ | Edit the files in `content/` (see `docs/CONTENT.md`), push to `main`. The assistant's knowledge updates with them. |

## 4. Settings worth knowing (`config/defaults.php`)

- `security.registration`: `open` (default), `approval` (you approve each account) or `invite` (no public sign-up).
- `licensing.payment_mode`: `invoice` (default: you email payment details) or `links` with
  `licensing.payment_links` such as `'stagewatch.pro' => 'https://…'`.
- `chat.*`: models, limits, the daily token budget and how long conversations are kept (30 days).
- `security.turnstile_site_key` / `turnstile_secret`: add Cloudflare Turnstile to the contact form if spam appears.

## 5. Backups

Hostinger backs up files and databases daily. The data that matters is the MySQL database, `patterns-data/`
(configuration, uploads, licence files) and `public_html/downloads-vault/`.
