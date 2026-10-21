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
   its scope check; the site also stops by itself at `chat.daily_token_budget` tokens a day. The budget counts every
   answer, including one withdrawn or cut off, and the long cached system prompt at its billed weight: prompt tokens
   written to the cache at 1.25 and tokens read from it at 0.1 of an input token.
7. **Configuration.** Copy `config/config.example.php`, fill it in (database, SMTP, Anthropic key,
   `app.secret` = 64 random hex characters, `app.admin_emails` = your address) and create it with File Manager at
   `/home/<user>/domains/patternsshowcontrol.co.uk/patterns-data/config.php` (create the folder; `public_html` stays
   empty for the first Git deploy in step 8). It is never committed. Once the site is deployed you may instead
   upload it once as `public_html/config/local.php`: on the next request the site moves it outside `public_html` by
   itself. That happens only while `patterns-data/config.php` does not exist yet: **every later change goes into
   `patterns-data/config.php`**. A `config/local.php` uploaded after that is never read or moved (a partial file
   would otherwise replace the live settings); the admin overview says so, `/health` reports
   `"config_shadowed": true`, and the stray file should be deleted.
8. **Git deployment.** In GitHub, CI builds the site on every push to `main` and commits the result to the
   `deploy` branch (production dependencies in `vendor/` included, no tests, no composer files, so the server never
   runs Composer). Only a CI run for a push to this repository's own `main` deploys, never a pull request; deploys
   run one at a time, and only while their commit is still the tip of `main`. In hPanel → Advanced → Git:
   *Connect with GitHub*, repository `jammin808/Patterns-Website`, branch `deploy`, directory `public_html`,
   auto-deploy on. Untracked files on the server (uploads in `downloads-vault/`) survive every deploy.
   Without Git: download the `patterns-site` artifact from a CI run (GitHub wraps it in a zip of its own: unzip
   that on your computer first), or run `tools/build-release.sh`. Upload the `patterns-site-<version>.zip` inside
   it to `public_html` with File Manager and extract it there. The data folder outside `public_html` is never
   touched by either route.
9. **Cron.** hPanel → Advanced → Cron Jobs, daily (UTC):
   `/usr/bin/php /home/<user>/domains/patternsshowcontrol.co.uk/public_html/tools/cron.php` (`/usr/bin/php` is the
   account's PHP CLI, 8.3 today)
10. **Your admin account.** Register at `/portal/register/` with an address listed in `app.admin_emails` and
    confirm the email: that account is an administrator.

## 2. After each deploy

- **The CDN is flushed after every deploy, automatically.** Hostinger's CDN caches public pages (they send
  `Cache-Control: public, max-age=300`). Once the deploy branch is pushed, the deploy workflow waits until `/health`
  reports the new build (its `build` field, the commit the bundle was built from), then flushes the CDN through the
  Hostinger API (`tools/flush-cdn.sh`), so no visitor is served the old site. Portal pages, downloads and the
  assistant always send `private, no-store`. Setup, once:
  1. hPanel → Profile → API: create an API token.
  2. GitHub → the repository → Settings → Secrets and variables → Actions → New repository secret:
     `HOSTINGER_API_TOKEN` = the token. The token reaches only the flush step, and only for a push to this
     repository's own `main`.
  3. Optional repository variables `HOSTINGER_USERNAME` (default `u162541462`) and `SITE_DOMAIN` (default
     `patternsshowcontrol.co.uk`).
  Without the secret the deploy still runs and warns that the CDN was not flushed. To flush at any time: Actions →
  Flush CDN → Run workflow, or hPanel → Performance → CDN → Flush cache.
- Smoke test: `/health` answers `{"status":"ok"}` with every check `true` and `config_shadowed` `false` (with the
  assistant switched on and a key set, a missing `vendor/` makes it `degraded`); a signed-out request to
  `/portal/downloads/1/` redirects to the login page; `curl -N -X POST https://…/api/chat` from another origin is
  refused with 403; `curl -I https://patternsshowcontrol.co.uk/.well-known/security.txt` answers 200 `text/plain`
  (only the live LiteSpeed server can confirm the `.htaccess` lets it through).
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

- `analytics.ga_id`: the Google Analytics 4 measurement ID tagged on every page right after `<head>` (default
  `G-0C7QKZTNF7`; `''` turns it off, banner and all). The CSP allows the tag's inline part by its hash and Google's
  hosts only. gtag.js loads only after a visitor accepts statistics cookies in the cookie banner. Pages whose address
  carries a one-time token (password reset, email confirmation, invitations) never load it.
- `security.registration`: `open` (default), `approval` (you approve each account) or `invite` (no public sign-up).
- `licensing.payment_mode`: `invoice` (default: you email payment details) or `links` with
  `licensing.payment_links` such as `'stagewatch.pro' => 'https://…'`.
- `chat.*`: models, limits, the daily token budget and how long conversations are kept (30 days).
- `security.turnstile_site_key` / `turnstile_secret`: add Cloudflare Turnstile to the contact form if spam appears.
- `app.trusted_proxy_header` / `app.trusted_proxies`: how the site learns a visitor's address behind a CDN, which
  every per-address limit (sign-in, contact, register, the assistant) counts by. Leave the header `null` (the
  default: `REMOTE_ADDR`) unless those limits are seen to act on the CDN's address instead of each visitor's, for
  example one visitor's rate limit refusing everyone. Whether Hostinger's CDN already passes the visitor's address
  in `REMOTE_ADDR` is not confirmed yet: check it on the live site before changing anything. If it does not, set
  `trusted_proxy_header` to `'HTTP_X_FORWARDED_FOR'` and `trusted_proxies` to the CDN's published address ranges:
  the site then reads the address the CDN added (the rightmost entry that is not one of its proxies), never one a
  visitor wrote, and ignores the header on a request that did not come through those ranges. Any other header
  (such as `HTTP_CF_CONNECTING_IP`) is read only from a request whose `REMOTE_ADDR` is in `trusted_proxies`.

## 5. Backups

Hostinger backs up files and databases daily. The data that matters is the MySQL database, `patterns-data/`
(configuration, uploads, licence files) and `public_html/downloads-vault/`.
