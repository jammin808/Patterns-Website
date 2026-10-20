# Launch checklist

What the owner needs to supply or confirm before the site goes live. Everything else is built.

## Accounts and services

- [ ] Register `patternsshowcontrol.co.uk` (the plan's free domain) and create the website in hPanel (docs/DEPLOY.md §1).
- [ ] Anthropic API key for the support assistant, in a workspace with a monthly spend limit.
- [ ] Mailboxes `support@` and `no-reply@` and the SMTP password for `no-reply@`.
- [ ] Connect GitHub in hPanel → Git (branch `deploy`), or deploy the release zip by hand.
- [ ] Decide whether `support@patternsshowcontrol.co.uk` is shown publicly (it is in the structured data and on the contact page) or only the contact form.

## Legal (drafts in `content/legal/`, need your review)

- [ ] Privacy policy, cookie notice, website terms, software licence terms.
- [ ] Refund policy and VAT position for licence sales.
- [ ] The publisher's legal name and address, if trading through a company.

## Licensing and prices

- [ ] StageWatch: when the £59 Production launch price ends; when lifetime-update Production licences stop; upgrade
      prices between tiers; 5-pack totals and which tiers come as 5-packs; whether the nonprofit discount applies to
      the launch price; whether reissuing a licence for a new PC is free.
- [ ] Should the StageWatch Production tier name the AI copilot explicitly (it is in the app's plan text)?
- [ ] Patterns: tiers, trial length, free mode, machines per licence, updates, nonprofit and rental offers, prices.
      The site shows the StageWatch structure with "Coming soon" until then. The Patterns repository still carries MIT
      notices in its README, `Directory.Build.props` and the Companion module's `package.json`/`manifest.json`.
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

## Imagery

- [ ] Fresh screenshots would help: Patterns (the Eye, lower-thirds designer, the phone remote, Stream Deck keys,
      the Machine page, PREP mode), PlayMap (the current show strip), StageWatch and AGMessage (none exist yet; the
      site draws illustrations instead). Capture at 1600 px or wider.
- [ ] Logos: there are no product logos in the repositories; the site uses its own wordmark and drawn marks.

## After launch

- [ ] Verify the domain in Google Search Console and Bing Webmaster Tools and submit `/sitemap.xml`.
- [ ] List the Patterns, StageWatch and PlayMap Companion modules in Bitfocus's connections directory, linking back
      to each product page.
- [ ] Link agmessage.com and the new AGMessage page to each other.
