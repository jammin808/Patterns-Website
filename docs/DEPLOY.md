# Deploying to Hostinger

The site is plain PHP 8.4+ with MySQL (CI tests 8.4, the live version, and 8.5). On Hostinger Business (LiteSpeed)
the whole built site sits in `public_html`; the root `.htaccess` lets only `public/` be reached and sends every
other request to `public/index.php`. Secrets and runtime data live **outside** `public_html`, in
`../patterns-data/`.

```
/home/<user>/domains/patternsshowcontrol.co.uk/
├── public_html/              ← the built site (deploy branch or release zip)
│   ├── .htaccess  public/  app/  templates/  content/  vendor/  …
│   └── downloads-vault/      ← release files (served only through the portal)
└── patterns-data/            ← created on first request; never deployed over
    ├── config.php            ← the server configuration (secrets)
    └── storage/              ← sessions, logs, cache, uploads (news images too), licence files
```

## 1. One-time setup

1. **Domain.** Register `patternsshowcontrol.co.uk` (the plan's free domain) and keep it on Hostinger's
   nameservers.
2. **Website.** hPanel → Websites → Add website → *Empty PHP/HTML website* on the Business plan, domain
   `patternsshowcontrol.co.uk`. Wait for the free SSL to show *Active*; turn on **Force HTTPS**.
   Delete the placeholder files in `public_html` (the first Git deploy needs an empty folder).
3. **PHP.** hPanel → Websites → this site → Advanced → PHP Configuration: **8.4** (each website on the plan has its
   own version; agmessage.com's is set apart). Keep `pdo_mysql`, `curl`, `intl`, `mbstring`, `fileinfo` and `gd` on;
   set `expose_php` Off. GD must be built with WebP and JPEG support: Industry Updates re-encodes every uploaded
   image to WebP, with a JPEG share card. `/health` reports `"images": true` when it can.
   - The PHP options are kept per version: after changing the version, set `expose_php` Off again and check the rest.
     The root `.htaccess` turns `session.upload_progress.enabled` off for every request (LiteSpeed applies it before
     PHP reads the request; the site never uses upload progress), and the site itself hides arguments in error traces.
     Portal → Admin → Overview → **Server** shows what PHP actually runs with: the version, and each of these settings
     with whether it is as intended.
   - Hostinger's PHP is CloudLinux's alt-php. CloudLinux back-ports security fixes into builds that keep the older
     version number (for php.net's advisory of 21 October 2026, for example, alt-php85 8.5.11-5, before the renumbered
     8.5.12-1 and 8.4.27-1 builds), so a version below php.net's newest is not by itself unpatched, and the version is
     all a website or cron job can read. Only Hostinger support can read the package changelog: ask them when in doubt.
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
   **Industry Updates' AI desk** (docs/NEWS.md §6) uses `news.api_key`, or `chat.api_key` when that is empty. Create
   a separate key in a workspace of its own, with its own monthly spend limit, so a busy news month can never use
   up the assistant's limit (and the assistant cannot starve the desk). The desk uses the server-side web search and
   web fetch tools: in the Anthropic Console, an organisation administrator must allow web search and web fetch
   (the organisation's privacy settings), or every research call is refused. Research runs on Claude Sonnet 5.5,
   choosing and writing on Claude Opus 5.5, and the check before publishing on Claude Haiku 5.5. The desk's own cap
   (`monthly_budget_usd`, 100 USD by default, on the desk's settings page) is an estimate from the usage the API
   reports; the Console's limit is the hard stop.
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
9. **Cron.** hPanel → Advanced → Cron Jobs. Two jobs, each naming the PHP binary of the site's version
   (`/usr/bin/php` follows the account's main website, agmessage.com, not this one; after changing this site's PHP
   version, change `php84` in both lines too):
   - Housekeeping, daily (UTC):
     `/opt/alt/php84/usr/bin/php /home/<user>/domains/patternsshowcontrol.co.uk/public_html/tools/cron.php`
   - Industry Updates, every 15 minutes (`*/15 * * * *`):
     `/opt/alt/php84/usr/bin/php /home/<user>/domains/patternsshowcontrol.co.uk/public_html/tools/news.php tick`
     Each tick imports any new article files, runs the session that is due (the sessions are London times set on
     the desk's settings page, so the clock change needs no new cron line) and records a heartbeat. A tick that
     finds another still running exits at once. The desk page (Portal → Admin → Industry Updates → Open the AI
     desk) and the admin overview's Industry Updates card say when the cron job has not ticked yet or its last tick
     is more than 35 minutes old (a session still running inside its time counts as ticking), and `/health`
     reports `"news": "stale"`.
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
  (only the live LiteSpeed server can confirm the `.htaccess` lets it through); `/health` reports `"news": "ok"`
  once the news cron is ticking and a key is set (`"off"` while the desk is paused or has no key).
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
| Write or correct a news article | Portal → Admin → Industry Updates → open the article (or *Write an article*). AI articles held for review wait under *Held*. A correction that changes what a live article says goes in *Add a correction* (one line saying what changed): the article shows it, dated, under its headline, as the editorial standards page (`/news/standards/`) promises. |
| Run the AI desk | Portal → Admin → Industry Updates → Open the AI desk: pause or continue it, *Run a session now* (it starts within 15 minutes), change its sessions, calendar, models, cap, banned sites and topics, and its brief and voice. Each run has a page with its steps, candidates, references and estimated cost. |
| Moderate comments | Portal → Admin → Industry Updates → Moderate comments. The first report on a comment, and the report that hides it, are emailed; so is a comment waiting for approval, at most once a day. |
| Run the weekly digest | Portal → Admin → Industry Updates → Open the AI desk → *Weekly digest*: pause or continue it, see who gets it, open the next one's preview, send yourself a test, unsubscribe someone who asked by email, and see how each digest went. Its day, time and sending limits are on the desk's *Settings* tab (§8). |
| Publish an article written in a Claude Code session | Add its JSON file to `content/news/articles/` (the shape is in docs/NEWS.md §6.4) and push to `main`: the next tick imports it once. |

## 4. Settings worth knowing (`config/defaults.php`)

- `analytics.ga_id`: the Google Analytics 4 measurement ID tagged on every page right after `<head>` (default
  `G-0C7QKZTNF7`; `''` turns it off). The CSP allows the tag's inline part by its hash and Google's hosts only. Pages
  whose address carries a one-time token (password reset, email confirmation, invitations) never load it.
- `analytics.consent`: `false` (default) tracks every visit with Google's own snippet and shows no cookie banner;
  `true` makes the tag wait for consent (Google Consent Mode, basic) behind the cookie banner (DESIGN.md 8.35). The
  cookie notice and privacy policy describe the default; switching consent on means restoring their consent wording.
- `security.registration`: `open` (default), `approval` (you approve each account) or `invite` (no public sign-up).
- `licensing.payment_mode`: `invoice` (default: you email payment details) or `links` with
  `licensing.payment_links` such as `'stagewatch.pro' => 'https://…'`.
- `chat.*`: models, limits, the daily token budget and how long conversations are kept (30 days).
- `news.*`: the desk's key (`api_key`; empty falls back to `chat.api_key`), how long one model call may take
  (`timeout_seconds`, 600) and to connect (`connect_timeout_seconds`, 15), the check model (`check_model`), a
  session's deadline (`session_minutes`, 40) and its spending ceiling (`session_ceiling_usd`, 5 USD, estimated), and
  whether imported articles without a time go live 10 to 30 minutes apart (`import_stagger`). Everything else about
  the desk (sessions, models, cap, lists, prompts) is set on its admin pages and kept in the database. Each tick also
  tells IndexNow (`indexnow`, on; `indexnow_endpoint`) and the feeds' WebSub hub (`websub_hub`, Google's hub; `''`
  turns it off) what went live, changed or went (§7), from the live site only.
- `seo.google_site_verification` / `seo.bing_site_verification`: the ownership tokens Search Console and Bing
  Webmaster Tools give you (the `content="…"` part of their meta tag; pasting the whole tag works too). The home
  page's `<head>` carries them. They are not secrets, but they live in the server config like the rest (§7).
- `mail.unsubscribe_to`: the mailbox every weekly digest names for unsubscribing by email (default
  `unsubscribe@patternsshowcontrol.co.uk`, an alias of `support@`, §8). It must be a plain address: empty, or
  anything else (such as a name with the address in angle brackets), no digest is sent and the desk says why.
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

## 5. Taking an Industry Updates article down

News pages are cached for 2 minutes, and may be served stale for 1 more while the CDN fetches a new copy (`public,
max-age=120, stale-while-revalidate=60`: up to about 3 minutes), and news images for an hour on the CDN
(`s-maxage=3600`). Whether Hostinger's CDN honours those times is still to be confirmed on the live site,
so to remove something at once:

1. **Unpublish it** (Portal → Admin → Industry Updates → the article → *Unpublish*), or delete it, or delete just the
   image. The site stops serving it straight away; an imported article that is deleted is never imported again.
2. **Flush the CDN**: GitHub → Actions → *Flush CDN* → *Run workflow* (or hPanel → Performance → CDN → Flush cache).
   Without the flush a cached copy can stay at the edge for up to about 3 minutes, an image for up to an hour. The
   home and product pages drop the article from their latest-articles strips at once, but those pages are cached for
   5 minutes; the flush clears them too.
3. If the request came from outside (a correction, a complaint), note what was changed and when; the audit log
   (Portal → Admin → Audit log, `news.`) has the record.

To stop the desk while you look into it, pause it on the desk page (Portal → Admin → Industry Updates → Open the AI
desk). Pausing stops new sessions; a session already running finishes. Leave *When pausing, also hold the articles
the desk has already scheduled* ticked, as it is by default, and every AI article a session has scheduled but not
yet published becomes a draft under *Held for review* (its review note says "Held when the desk was paused"), for you
to publish again or delete. Unticked, those articles still go live at their times; unpublish any of them from the
*Scheduled* tab. Unticking *Publish articles automatically* in the desk's settings makes every article from the next
session on wait as a draft; it does not touch articles already scheduled.

## 6. Backups

Hostinger backs up files and databases daily. The data that matters is the MySQL database (news articles, comments
and the desk's settings and prompts included), `patterns-data/` (configuration, uploads and news images, licence
files) and `public_html/downloads-vault/`.

## 7. Search engines, feed readers and answer engines

What the site does by itself, and what only you can do.

**By itself**
- Every 15-minute tick tells **IndexNow** (Bing, Yandex, Seznam, Naver, Yep and the others that share it) about
  every article that went live, changed, was corrected, was taken down or was deleted, with the hubs and feeds it
  touched, each address once. The key it proves itself with is made on the first ping and served at
  `https://patternsshowcontrol.co.uk/<key>.txt`; nothing to set up. Google does not take part.
- The same tick pings the feeds' **WebSub hub** (`https://pubsubhubbub.appspot.com/`), so feed readers that use it
  (Inoreader among them) see a new article within minutes instead of at their next poll.
- A failure is logged (`news announce failed` in `storage/logs/`) and tried again later: at the next tick, then twice
  as long each time, a day at most. The desk page (Portal → Admin → Industry Updates → Open the AI desk) shows what
  each was last told, and says "Failing" with the status while one is.
- `robots.txt` names both sitemaps; the feeds, the home and product pages' latest-articles strips and the follow and
  share links keep the section linked from everywhere.

**Your steps, once**
1. **Google Search Console** (search.google.com/search-console): add the property. A *Domain* property is verified
   only by a DNS record: hPanel → Domains → DNS / Nameservers, add the TXT record the wizard shows (usually
   `google-site-verification=…`; take it exactly from the wizard). A *URL prefix* property
   (`https://patternsshowcontrol.co.uk/`) can instead use the HTML tag: put its `content` value in
   `seo.google_site_verification` in `patterns-data/config.php`, wait up to 5 minutes for the CDN (or run Flush CDN),
   then press Verify. Leave the token in place for good: removing it loses the verification.
2. In Search Console → Sitemaps, submit `https://patternsshowcontrol.co.uk/sitemap.xml` and
   `https://patternsshowcontrol.co.uk/news/sitemap.xml` (the section's index: one sitemap a month and the news
   sitemap of the last two days).
3. **Bing Webmaster Tools** (bing.com/webmasters): choose *Import from Google Search Console*. It brings the site and
   its sitemaps across and verifies it (data can take 48 hours). If you would rather verify on its own, use the meta
   tag: put its `content` value in `seo.bing_site_verification`. Bing's *AI Performance* report then shows when
   Copilot cites the site.
4. Optional: in Search Console, the UK pilot of the *Search generative AI* control (AI Overviews and AI Mode) appears
   for some sites; it is yours to decide. Robots meta tags already allow large image previews.
5. Add the organisation's own profiles (LinkedIn, YouTube, GitHub, Companies House …) to `content/site.php`
   'same_as' as full https addresses, and push: they become the Organization's `sameAs`.
6. Crawlers, decided: allow AI search, block training (the owner's choice, 23 October 2026). `content/site.php`
   'block_training' is true, which asks the model-training crawlers to stay away, and 'ai_search' is true, which keeps
   ChatGPT search, Claude, Perplexity and Meta AI welcome. Each is its own switch; crawlers take about a day to read a
   change. What it costs: blocking Google-Extended stops Gemini's training on the site and its grounding in Gemini
   Apps and Vertex AI, so the site drops out of Gemini's answers. It does not touch Google Search, AI Overviews or AI
   Mode, which come from Googlebot and follow `nosnippet` and Search Console's own control (step 4).
7. On the live site, check: `curl -I https://patternsshowcontrol.co.uk/news/feed.xml` shows the `Link` header with
   the hub; the desk page shows *Last told* for both channels after the first tick; and the key file answers.

## 8. The weekly digest

Industry Updates has a weekly email digest (docs/NEWS.md §14): people sign up at `/digest/` or tick the box on their
account page or when they create an account (either way a confirmation email follows, and nothing is sent until they
press the button it leads to), and the news cron's tick sends each week's new articles at the day and time on the
desk's *Settings* tab (Friday 09:00 London time by default). It goes through the same SMTP mailbox as the site's
other email, `no-reply@`. Before the first digest goes out:

1. **The unsubscribe address.** In hPanel → Emails, add `unsubscribe@patternsshowcontrol.co.uk` as an alias of
   `support@` (the mailbox §1 step 5 makes; a forwarder to it works too), so what arrives there is read. Every digest
   names it, in its `List-Unsubscribe` header and in its footer; `mail.unsubscribe_to` holds it. If you point it at
   another mailbox, the desk's help names no mailbox, so nothing else needs changing.
2. **Requests by email.** Act on each one within 2 days: Portal → Admin → Industry Updates → Open the AI desk →
   *Weekly digest* → *Unsubscribe someone*. Paste the email's subject (it holds the token, which finds the
   subscription even when the request was forwarded) or the address, choose *By email to unsubscribe@…*, and press
   *Unsubscribe*. Nobody needs to reply: the digest simply stops.
3. **DMARC reports.** Check that the domain's DMARC record has a report address (`rua=mailto:…`, §1 step 5), so you
   hear when mail claiming to be from the domain fails its checks.
4. **Google Postmaster Tools** (postmaster.google.com): add `patternsshowcontrol.co.uk` and verify it with the DNS TXT
   record it shows (hPanel → Domains → DNS / Nameservers). It reports the domain's spam rate and reputation at Gmail,
   which matter once the list grows.
5. **The first test send.** The desk's *Send me a test* sends the next digest, marked as a test, to the signed-in
   administrator's own address. Send it to a Gmail address first: if yours is elsewhere, add a Gmail address to
   `app.admin_emails`, register an account with it and send the test from that account. In Gmail, open the test, then
   ⋮ → *Show original*:
   - SPF, DKIM and DMARC should each say `PASS`, and DKIM's domain should be `patternsshowcontrol.co.uk`;
   - in the `DKIM-Signature` header, the list after `h=` should include `list-unsubscribe` and
     `list-unsubscribe-post`. RFC 8058 asks receivers to offer one-click unsubscribe only when the signature covers
     both. If it does not, the digest still works (the link in its footer and the mailto), but Gmail and Yahoo may not
     show their own *Unsubscribe* button; note it, as signing in the site itself is a later change (NEWS.md §14.16).
   - Gmail should show *Unsubscribe* beside the sender's name once the domain has some sending history.
6. **The limits.** Hostinger's Starter Business Email lets one mailbox send 1,000 emails in any 24 hours, at most 100
   recipients a message. The digest sends one recipient a message, 25 a tick, at most 100 an hour and 500 in any 24
   hours by default (the desk's *Settings* tab), which leaves the other half for confirmations, password resets and
   ticket emails. 500 subscribers take about 5 hours; a digest still sending 48 hours after its time stops, and the
   desk says so. `mail.driver` must be `smtp`: with `mail` (PHP `mail()`, capped at 100 a day) the digest does not
   send at all, and the desk says why.
7. **When the list grows.** At 400 subscribers the desk warns that the list is nearing what one mailbox can send
   beside the site's other email. The way on is a mailbox of the digest's own (such as `updates@`) or Hostinger Reach;
   either needs a small change to the site (the digest's own sending settings), which is not built yet, so ask for it
   when the warning appears.
8. **Never change `app.secret` while the digest runs.** It signs every confirmation and unsubscribe link and keys
   every subscriber's record (the coded copy of the address that finds it, and keeps an unsubscribed address off the
   list): a new secret breaks every link in every digest already sent, and the site can no longer find anyone's
   record by their address. If it ever must change (a leak), pause the digest first: the records need re-keying
   before it goes again, which is a change to the site to ask for.
9. **A development or staging copy** must have `mail.driver` set to `log` (emails are written to
   `storage/mail/` as files and reach nobody), most of all when it runs on a copy of the live database, which holds
   real subscribers. The desk shows "This server writes emails to files" while it is.
