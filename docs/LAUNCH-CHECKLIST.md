# Launch checklist

What the owner needs to supply or confirm before the site goes live. Everything else is built.

## Accounts and services

- [ ] Register `patternsshowcontrol.co.uk` (the plan's free domain) and create the website in hPanel (docs/DEPLOY.md §1).
- [ ] Anthropic API key for the support assistant, in a workspace with a monthly spend limit.
- [ ] `chat.daily_token_budget`: the budget now also counts the cached system prompt (writes at 1.25, reads at 0.1 of
      an input token) and every withdrawn or cut-off answer, so each answer counts several thousand tokens more than
      before. Choose the daily figure (the default is 1,500,000).
- [ ] Mailboxes `support@` and `no-reply@` and the SMTP password for `no-reply@`.
- [ ] Connect GitHub in hPanel → Git (branch `deploy`), or deploy the release zip by hand.
- [ ] Add the `HOSTINGER_API_TOKEN` repository secret so every deploy flushes the CDN (docs/DEPLOY.md §2).

## Industry Updates

- [ ] An Anthropic key for the AI desk in a workspace of its own, with a monthly spend limit, set as `news.api_key`
      in the server config (without it the desk uses `chat.api_key` and shares the assistant's limit).
- [ ] Allow web search and web fetch in the Anthropic Console (an organisation administrator, in the privacy
      settings); without them every research session fails.
- [ ] Add the second cron job, every 15 minutes: `tools/news.php tick` (docs/DEPLOY.md §1 step 9). Then check the desk
      page shows a recent tick and `/health` says `"news": "ok"`.
- [ ] Check `/health` says `"images": true` (GD with WebP), or article images cannot be uploaded.
- [ ] The desk's monthly cap: 100 USD by default (estimated, on the desk's settings page). Pick yours, and keep the
      Console's limit as the hard stop.
- [ ] AI articles go live without a person reading them first, as you asked; anything that names a private person,
      alleges wrongdoing or trips a check is held for review instead. To read everything first, untick *Publish
      articles automatically* in the desk's settings.
- [ ] Read the research brief and the voice on the desk's prompt page, and add any banned websites and topics.
- [ ] Read the eight launch articles: the first tick imports them and they go live 10 to 30 minutes apart.
- [ ] Check on the live site whether Hostinger's CDN keeps news pages for only the 2 minutes they ask for
      (`curl -I` an article twice and compare the `age` header). Until that is known, a takedown needs the Flush CDN
      workflow (docs/DEPLOY.md §5).

## Legal (drafts in `content/legal/`, need your review)

- [ ] Privacy policy, cookie notice, website terms, software licence terms.
- [ ] **Before StageWatch licences are sold to the public** (UK consumer rules for digital content): the trader's
      legal name and geographic address (and company number and registered office if trading through a company) in
      the licence terms and on the contact or about page; a refunds and cancellation section covering the 14-day
      right and its waiver once the licence file is delivered with the buyer's express consent, with that consent
      asked for at the request or payment step; and a VAT line on the StageWatch pricing ("Prices include VAT" or
      "We are not VAT registered"). Then replace the assistant's "not announced" line in `knowledge/stagewatch.md`.
- [ ] **Today's free downloads need terms that apply to them.** The licence terms say they will apply "when" each
      app is sold; reword the opening so it also covers the free early-access and evaluation builds of Patterns and
      PlayMap, and link the terms from `/downloads/` and the Patterns and PlayMap licensing pages.
- [ ] **Google Analytics runs on every visit without asking** (`analytics.consent` is off: the owner's choice). Under
      UK PECR analytics cookies have needed consent; the Data (Use and Access) Act 2025 relaxes this only for
      statistics-only cookies with clear information and a simple way to object. The consent banner is built and
      tested: set `'analytics' => ['consent' => true]` in the server config to switch it on, and restore the consent
      wording of the cookie notice and privacy policy (commit e6a3074). Either way, keep Google signals and data sharing
      off in Google Analytics unless the notice says otherwise.
- [ ] Privacy policy items: name the payment provider if payment links are switched on; say that a signed-in
      visitor's assistant conversations are linked to their account and may be read to improve answers; confirm the
      Anthropic processing, training and retention wording against the commercial terms in force.

## Licensing and prices

- [ ] StageWatch: when the £59 Production launch price ends; when lifetime-update Production licences stop; upgrade
      prices between tiers; 5-pack totals and which tiers come as 5-packs; whether the nonprofit discount applies to
      the launch price; whether reissuing a licence for a new PC is free.
- [ ] Should the StageWatch Production tier name the AI copilot explicitly (it is in the app's plan text)?
- [ ] Patterns: tiers, trial length, free mode, machines per licence, updates, nonprofit and rental offers, prices.
      The site shows the StageWatch structure with "Coming soon" until then. **Before any Patterns build is uploaded
      to the portal**, remove the MIT copyright notice from the Patterns repository (`Directory.Build.props`, which
      stamps it into the exe, `LICENSE` and the README line). The Companion module may keep its own licence file; the
      site never names it as Patterns' licence.
- [ ] The Patterns and PlayMap licensing pages say their licences will follow StageWatch's one-off, offline model,
      with named tiers. Confirm that, or soften it to "we plan to follow StageWatch's model" and drop the tier names
      (also `knowledge/ecosystem.md`).
- [ ] Search results: products without a published price (Patterns, AGMessage, PlayMap) carry SoftwareApplication
      markup with no offers, which search tools report as a warning. Keep it (offers appear by themselves once
      prices are in `content/licensing/`) or drop the markup until then.
- [ ] PlayMap: early-access terms (and whether early access costs anything), tiers, prices.
- [ ] AGMessage: per-AGM, agency and pilot prices stay "on application"; confirm the pilot terms repeated from
      agmessage.com, the self-run licence plan (shown as coming), and what the managed service's branding includes.
- [ ] Payment: invoices by email (default) or payment links per tier (`licensing.payment_links`).

## Product facts to confirm

- [ ] Versions shown: StageWatch 2.16, AGMessage 0.4.0 (agmessage.com says v1.0), PlayMap 0.2.0, Patterns none.
      Set a product's `version` to null in `content/products/` to hide it.
- [ ] Downloads: which builds are published in the portal (Patterns standard and full, PlayMap early access,
      AGMessage, the Companion modules for Patterns and PlayMap) and whether `/downloads/` lists any publicly.
- [ ] PlayMap's minimum Windows version for today's portable build.
- [ ] Roadmap items shown as "coming" on each product page (`roadmap` in `content/products/`): keep only what is
      really planned.
- [ ] Publish venue-IT network ports anywhere? (Not shown today; the assistant sends those questions to a ticket.)
- [ ] Windows SmartScreen guidance for unsigned builds (one neutral FAQ answer today).
- [ ] FFmpeg setup help for PlayMap (today the site states the requirement only).

- [ ] Patterns' audience play (polls, word clouds and quizzes on phones, results and a leaderboard on the wall) is
      not on the site. Say if it should be promoted as a feature.

## Imagery

- [ ] Fresh screenshots would help: Patterns (the Eye, lower-thirds designer, the phone remote, Stream Deck keys,
      the Machine page, PREP mode), PlayMap (the current show strip), StageWatch and AGMessage (none exist yet; the
      site draws illustrations instead). Capture at 1600 px or wider.
- [ ] Logos: there are no product logos in the repositories; the site uses its own wordmark and drawn marks.

## After launch

- [ ] Verify the domain in Google Search Console and Bing Webmaster Tools and submit `/sitemap.xml`.
- [ ] Check whether `REMOTE_ADDR` is the visitor's own address behind Hostinger's CDN, and set
      `app.trusted_proxy_header` and `app.trusted_proxies` only if it is not (docs/DEPLOY.md §4).
- [ ] `curl -I https://patternsshowcontrol.co.uk/.well-known/security.txt` answers 200 on LiteSpeed. security.txt
      names no disclosure policy; publish one (and add its `Policy:` line) only if you want to.
- [ ] List the Patterns, StageWatch and PlayMap Companion modules in Bitfocus's connections directory, linking back
      to each product page.
- [ ] Link agmessage.com and the new AGMessage page to each other, once agmessage.com matches the product (version,
      branding scope, no document highlighting) and you have decided whether it keeps competitor names.
