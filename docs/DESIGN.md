# Design system: "Signal"

The look of patternsshowcontrol.co.uk. The site is a broadcast control room: the page is the room, and every
picture in it is a monitor. This file is the contract the PHP templates are written against. The live reference is
`design/kitchen-sink.html`, which shows every component with these class names in both themes. To view it, run
`php -S 127.0.0.1:8080` from the repository root and open `/design/kitchen-sink.html`. Opened straight from disk,
the browser blocks the fonts and the module scripts.

| File | What it is |
|---|---|
| `public/assets/css/site.css` | The whole system in one file, in the cascade layers `reset, tokens, base, layout, components, utilities` |
| `public/assets/js/site.js` | On every page: theme, drawer, mega-menu, header shadow, reveal, comparison-table affordance, copy, the account link, dismissibles, the launcher tuck, the cue ladder, tabs, form helpers |
| `public/assets/js/hero.js` | Home page only: the vision-mixer hero |
| `public/assets/js/countdown.js` | Pages with a StageWatch confidence monitor (`[data-countdown]`) |
| `public/assets/js/chat.js` | The support assistant (it already exists; its class names are styled here) |
| `public/assets/img/icons.svg` | The icon sprite (96 symbols, 8.30) |
| `public/favicon.svg`, `public/favicon.ico`, `public/assets/img/brand/` | The brand mark as favicons, touch, app and logo icons (8.33) |
| `public/assets/img/og/` | The Open Graph cards, one per `og` image in `content/pages.php` (8.34) |
| `tools/og/` | The generators for both: `brand.py` and `build.mjs` (see `tools/og/README.md`) |
| `public/assets/fonts/` | Archivo and JetBrains Mono, self-hosted WOFF2 subsets, with `LICENSES.txt` (SIL OFL 1.1) |

---

## 1. Principles

1. **The SMPTE bar is the brand.** The seven 75% bars, in their order, are the palette. Four bars belong to the
   four apps: yellow is StageWatch, cyan is Patterns, magenta is AGMessage and blue is PlayMap. Grey is the
   ecosystem and Show Bus. Green (PVW, GO, done) and red (PGM, over time, errors) keep their broadcast meaning and
   never stand for a product. **Amber always means standing by**: the STANDBY cue, the AI approval gate, warnings
   and the keyboard focus ring. The legend under the home hero teaches this once (`.c-legend`).
2. **Colour is a signal, not decoration.** Every coloured pixel answers "which app?" or "what state?". Status
   never uses a product colour. Colour is never the only cue: tallies carry PGM/PVW, lamps sit beside a word, badges
   have a dot and a word, and table cells pair an icon with hidden text.
3. **Screens stay dark.** Anything drawn as a monitor (`.c-screen`, `.c-monitor`, `.c-cm`, `.c-shot-frame`, the
   UMD strip) is dark in the light theme too. The theme changes the room, not the picture.
4. **One bold thing per page.** On the home page that is the live mixer. On a product page it is the product
   visual with a faint followspot in the product's colour behind it (`.c-hero--product`). Everything around it is
   quiet: hairlines, a precise grid, registration crosses where section rules meet the outer columns.
5. **Engineered, calm, true.** Small radii, hairlines rather than shadows, sentence case, no all-caps eyebrows.
   The only capitals are real broadcast words inside keys and tallies (PGM, PVW, TAKE, GO, STANDBY, ON AIR).

---

## 2. Using it on a page

```html
<!doctype html>
<html lang="en-GB" data-theme="dark">  <!-- set by theme-init.js from localStorage, never by the server -->
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark light">
  <meta name="theme-color" content="#090C10">
  <link rel="preload" href="/assets/fonts/archivo-latin-var.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="/assets/fonts/jetbrains-mono-latin-var.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/assets/css/site.css">
  <script type="module" src="/assets/js/site.js"></script>
  <!-- home only: --> <script type="module" src="/assets/js/hero.js"></script>
  <!-- StageWatch sections: --> <script type="module" src="/assets/js/countdown.js"></script>
  <script type="module" src="/assets/js/chat.js"></script>
</head>
<body class="l-page">
  <?php readfile(PUBLIC_DIR . '/assets/img/icons.svg') ?>  <!-- the sprite, once, first in <body> (it is already hidden) -->
  <a class="c-skip" href="#main">Skip to content</a>
  …announcement, header…
  <main id="main" data-product="stagewatch">…</main>   <!-- data-product only on that product's pages -->
  …footer, the floating assistant…
</body>
```

- **Theme before first paint, with no inline script.** `site.js` stores the visitor's choice in `localStorage`
  (`psc-theme`, exactly `light` or `dark`; none means "follow the system"). `theme-init.js`, a classic script in
  `<head>`, copies it to `<html data-theme="…">` before the body is parsed. The server never renders the attribute:
  public pages are the same for every visitor and are cached. With no attribute, the CSS follows
  `prefers-color-scheme` and falls back to dark. When storage is blocked the page follows the system.
- **Signed-in state on cached pages.** The portal sets a non-HttpOnly cookie `psc_li=1` while someone is signed in.
  It carries no identity; the session cookie stays HttpOnly. `site.js` then turns `[data-account-link]` ("Client
  login") into "My account".
- **Dismissed announcements.** The ids are kept in `localStorage` (`psc-dismissed`, a comma list); `theme-init.js`
  copies them to `<html data-dismissed>` before first paint and the CSS hides those strips, so nothing shifts on
  load.
- **Product scope.** Put `data-product="patterns|stagewatch|agmessage|playmap"` on `<main>` (not `<body>`) on that
  product's pages. The header, footer and assistant stay neutral. Inside the scope, primary buttons, eyebrows,
  tabs, rules, recommended tiers and swatches take the product's colour. `ecosystem` and `companion` are grey.
- **Fonts.** Two files are preloaded (69 KB together). The Latin Extended files load only when a page uses those
  characters. The metric-matched fallbacks (`Archivo Fallback`, `Archivo Display Fallback`,
  `JetBrains Mono Fallback`) keep the swap from moving text.
- **No inline styles.** Templates use classes only (docs/PORTAL-UI.md says the same for the portal).

---

## 3. Naming

| Prefix | Meaning | Examples |
|---|---|---|
| `l-` | Layout primitives | `l-wrap`, `l-section`, `l-grid`, `l-split`, `l-stack`, `l-cluster`, `l-page` |
| `c-` | Components. A part is `c-block-part` (one hyphen), a variant is `c-block--variant` | `c-btn`, `c-btn--primary`, `c-chat-msg-body`, `c-hero--product` |
| `pt-` | The portal and admin contract (docs/PORTAL-UI.md), the same look aliased onto the portal's names | `pt-btn`, `pt-badge--in_progress`, `pt-table` |
| `u-` | Utilities | `u-visually-hidden`, `u-mono`, `u-muted`, `u-mt-6`, `u-theme-light` |
| `is-` | States set by markup or scripts | `is-open`, `is-rec`, `is-invalid`, `is-unread`, `is-tucked`, `is-scrolled` |
| `data-*` | Behaviour hooks for the scripts, and two style scopes: `data-product`, `data-state` | `data-theme-toggle`, `data-mega`, `data-countdown` |

Scripts never select by class, and classes never trigger behaviour. The one exception: `site.js` makes the
portal's `.pt-table-wrap` (and `.c-table-wrap`, `.c-shots`) keyboard-reachable when they scroll, because those
templates carry no data hook. The `c-` and `pt-` names share their rules
(`:is(.c-btn, .pt-btn)`), so a portal page and a marketing page look the same.

---

## 4. Colour

All theme colours are written once as `light-dark(LIGHT, DARK)` on `:root`, and the theme is the `color-scheme`.
Because custom properties resolve where they are used, `color-scheme: dark` on `.c-screen` (and on the classes in
principle 3) gives everything inside a monitor its dark value. `.u-theme-light` and `.u-theme-dark` do the same for
any container. Browsers without `light-dark()` get the dark values from an `@supports` block.

### 4.1 Bars and lamps (fills, the same in both themes)

| Token | Hex | Belongs to | Text on it (`--on-lamp #090C10`) |
|---|---|---|---|
| `--bar-grey` / `--c-ecosystem` | `#BFC3C8` | the ecosystem, Show Bus, Companion | 11.06 |
| `--bar-yellow` / `--c-stagewatch` | `#E8DC2C` | **StageWatch**, the time | 13.72 |
| `--bar-cyan` / `--c-patterns` | `#18C8D8` | **Patterns**, the picture | 9.60 |
| `--bar-green` / `--lamp-pvw` | `#22D163` | preview, GO, done, success (never a product) | 9.67 |
| `--bar-magenta` / `--c-agmessage` | `#E052D0` | **AGMessage**, the people | 5.87 |
| `--bar-red` / `--lamp-pgm` | `#FF4040` | programme, on air, over time, errors (never a product) | 5.65 |
| `--bar-blue` / `--c-playmap` | `#5B7BFF` | **PlayMap**, mapping and playback | 5.34 |
| `--lamp-standby` | `#FFA11A` | standing by: STANDBY, approvals, warnings, focus | 9.67 |

Product colours on a monitor's black (the UMD role label): cyan 10.29, yellow 14.70, magenta 6.29, blue 5.72.

### 4.2 Surfaces, lines and text

| Token | Dark ("studio") | Light ("lightbox") | Use |
|---|---|---|---|
| `--bg` | `#090C10` | `#F3F4F6` | Page |
| `--s1` | `#0F1318` | `#FFFFFF` | Cards, tables, panels |
| `--s2` | `#151A21` | `#EBEDF1` | Table heads, chat header, control rows |
| `--s3` | `#1C222B` | `#E2E5EA` | Selected segment, chips |
| `--line` | `#262D37` | `#D3D8DF` | Hairlines (decorative) |
| `--line-2` | `#3A4450` | `#B3BBC6` | Strong hairlines, registration crosses (decorative) |
| `--field` | `#646E7C` | `#737D8B` | **Borders that identify a control**: inputs, selects, segmented controls, radio cards, votes, secondary buttons (at least 3:1) |
| `--field-bg` | `#0B0E13` | `#FFFFFF` | Inside inputs |
| `--text` | `#E8ECF1` | `#0D1117` | Body and headings |
| `--text-2` | `#A7B0BC` | `#47515E` | Secondary copy |
| `--text-3` | `#8A94A3` | `#5B6573` | Meta, captions, placeholders |
| `--focus` | `#FFA11A` | `#B86A00` | The focus ring |

### 4.3 Inks (colours that carry text)

| Token | Dark | Light | Note |
|---|---|---|---|
| `--c-patterns-ink` | `#18C8D8` | `#00707E` | |
| `--c-stagewatch-ink` | `#E8DC2C` | `#0D1117` | Yellow cannot carry text on white without turning olive, so in the light theme StageWatch is always a fill and its text is the page's |
| `--c-agmessage-ink` | `#E36BD6` | `#A3168F` | |
| `--c-playmap-ink` | `#7F98FF` | `#2F4BDB` | |
| `--ink-pvw` / `--ink-pgm` / `--ink-standby` | `#22D163` / `#FF6B6B` / `#FFA11A` | `#0C6E30` / `#C01C1C` / `#8F5500` | State words |
| `--c-*-line` | the bar | `#0A97A5`, `#BFAE00`, `#C43BB4`, `#4A68F0` | Rules and underlines only, never text |

**Rule: a bar is a fill, an ink carries text, a line draws a rule.** Product identity comes through fills:
swatches, the 3px top bar, role chips (`.c-role`), the UMD bar and the primary button.

### 4.4 Contrast (WCAG 2.2, computed from the tokens)

AA needs 4.5:1 for text, and 3:1 for large text, control boundaries and focus indicators.

| Token | Dark | on bg | on s1 | on s2 | on s3 | Light | on bg | on s1 | on s2 | on s3 |
|---|---|---|---|---|---|---|---|---|---|---|
| `--text` | `#E8ECF1` | 16.52 | 15.71 | 14.73 | 13.48 | `#0D1117` | 17.20 | 18.92 | 16.15 | 14.99 |
| `--text-2` | `#A7B0BC` | 8.94 | 8.50 | 7.97 | 7.30 | `#47515E` | 7.32 | 8.06 | 6.87 | 6.38 |
| `--text-3` | `#8A94A3` | 6.39 | 6.08 | 5.70 | 5.21 | `#5B6573` | 5.37 | 5.91 | 5.04 | 4.68 |
| `--c-patterns-ink` | `#18C8D8` | 9.60 | 9.14 | 8.57 | 7.84 | `#00707E` | 5.27 | 5.80 | 4.95 | 4.60 |
| `--c-stagewatch-ink` | `#E8DC2C` | 13.72 | 13.05 | 12.24 | 11.20 | `#0D1117` | 17.20 | 18.92 | 16.15 | 14.99 |
| `--c-agmessage-ink` | `#E36BD6` | 6.85 | 6.52 | 6.11 | 5.59 | `#A3168F` | 6.22 | 6.84 | 5.84 | 5.42 |
| `--c-playmap-ink` | `#7F98FF` | 7.31 | 6.96 | 6.52 | 5.97 | `#2F4BDB` | 6.06 | 6.67 | 5.69 | 5.28 |
| `--ink-pvw` | `#22D163` | 9.67 | 9.20 | 8.63 | 7.90 | `#0C6E30` | 5.80 | 6.39 | 5.45 | 5.06 |
| `--ink-pgm` | `#FF6B6B` | 7.06 | 6.72 | 6.30 | 5.76 | `#C01C1C` | 5.57 | 6.13 | 5.23 | 4.86 |
| `--ink-standby` | `#FFA11A` | 9.67 | 9.20 | 8.63 | 7.89 | `#8F5500` | 5.50 | 6.06 | 5.17 | 4.80 |
| `--field` (3:1) | `#646E7C` | 3.79 | 3.61 | 3.38 | 3.09 | `#737D8B` | 3.79 | 4.17 | 3.56 | 3.30 |
| `--focus` (3:1) | `#FFA11A` | 9.67 | 9.20 | 8.63 | 7.89 | `#B86A00` | 3.74 | 4.12 | 3.51 | 3.26 |

| Pair | Dark | Light |
|---|---|---|
| Badge ink on its 12% tint: done / in progress / declined | 7.53 / 7.55 / 6.03 | 5.80 / 5.57 / 5.25 |
| Neutral badges (open, triaged, planned) / muted | 12.5+ / 6.08 | 15.7+ / 5.91 |
| `--text-2` on the STANDBY row (amber 8%) / standby ink on it | 7.56 / 8.18 | 7.59 / 5.70 |
| Pressed vote (green ink on green 14%) | 7.20 | 5.75 |
| `--text-2` / `--text-3` in the recommended column (yellow 7%) | 7.44 / 5.32 | 7.86 / 5.76 |
| `--text-2` in callouts (amber 9% / red 9%) | 7.38 / 7.86 | 7.58 / 7.18 |
| Neutral primary button | 16.52 | 17.20 |
| Monitor text `#E8ECF1` / muted `#A7B0BC` on `#000`; TAKE key | 17.70 / 9.58; 18.55 | same |

The focus ring is two-toned on buttons: a 2px ring in the page colour, then 2px of `--focus`. It stays visible on
yellow and amber fills. Inside amber areas (the STANDBY row, the approval gate, warning callouts, the standby
department and internal notes) `--focus` switches to `--text`.

### 4.5 Product colour rules

- The ecosystem's primary button is neutral (text colour on page colour). A product page's primary takes the
  product bar with dark text. No product's colour is used for UI emphasis on another product's page.
- The recommended tier, active tabs, eyebrows, sub-nav underline, card top bars and feature rules follow
  `--accent`, so all four licence pages work with the same markup.
- Product swatches always sit beside the product's name. In the light theme they carry a faint edge
  (`--swatch-edge`) so yellow and cyan read on white.
- **Status never uses a product colour.** Open, triaged and planned are neutral, with a hollow, half and dashed
  marker. In progress is amber, done is green, and declined, revoked and disabled are red. Info callouts are
  the ecosystem's grey.

---

## 5. Type

Two OFL families, both variable and self-hosted: **Archivo** (wght 400–800, wdth 100–118) and **JetBrains Mono**
(wght 400–800, without its programming ligatures).

- Headings run Archivo **wide** (`font-stretch: 116%`, `--wd-display`), like silkscreened equipment labels.
  Weight 680, tracking −0.022em (−0.03em for h1 and hero titles), `text-wrap: balance`. Body text is Archivo at
  normal width. UI text (buttons, nav, labels, table heads) is 106% (`--wd-ui`).
- JetBrains Mono is only for numbers that change or line up: timecode, countdowns, cue numbers, IDs, versions,
  table values, tally labels and `kbd`/`code`. Never for prose or general labels. Prices use Archivo with
  `tabular-nums` (no dotted zeros in money).
- Sentence case everywhere. Paragraph measure: body under 70ch, ledes 42–56ch.

| Token | Size | Line height | Use |
|---|---|---|---|
| `--fs-0` | 13px | 1.3–1.4 | Meta, captions, badges |
| `--fs-1` | 14px | 1.45 | UI text, table cells, nav |
| `--fs-2` | 16px | 1.5 | Card copy, inputs, buttons |
| `--fs-3` | 17px | 1.55 | Body |
| `--fs-lede` | 18–21px | 1.45 | Section ledes (`.c-lede`, `.c-hero-lede`) |
| `--fs-4` | 21px | 1.2 | h3, FAQ questions, tier names |
| `--fs-5` | 24–28px | 1.1 | Prose h2, sign-in title |
| `--fs-6` | 30–44px | 1.06 | h2, portal `pt-title` |
| `--fs-7` | 40–76px | 1.0 | h1, page heroes |
| `--fs-hero` | 42–80px | 1.0 | Home and product hero titles (`.c-hero-title`) |
| confidence monitor | `23cqi` | 0.9 | `.c-cm-time` scales with its monitor |

---

## 6. Space, layout, radii, elevation, breakpoints

- **Space** (4px base): `--sp-1` 4 · `--sp-2` 8 · `--sp-3` 12 · `--sp-4` 16 · `--sp-5` 24 · `--sp-6` 32 · `--sp-7` 48
  · `--sp-8` 64 · `--sp-9` 96. Fluid: `--gutter-x` 16–56px, `--gap` 16–24px, `--section-y` 56–104px.
- **Container**: `.l-wrap` is `min(100% − 2 × gutter, 1320px)` (`--narrow` 860px, `--prose` 760px). Content never
  touches the viewport edge; the minimum gutter is 16px.
- **Radii** (equipment, not app icons): `--r-1` 2px for tallies, tags and checkboxes · `--r-2` 4px for buttons,
  inputs and monitors · `--r-3` 8px for cards, panels, tables and the chat · `--r-pill` for badges, the launcher and
  chat chips only.
- **Elevation**: depth is surface steps (`bg → s1 → s2 → s3`) and hairlines. Shadows (`--shadow-1/2/3`, a fixed
  geometry with `--shadow-ink` per theme) are for things that float: menus, the launcher, the chat and toasts.
- **Breakpoints** (mobile first): `40rem` (640px: two-column forms and grids, the chat stops being a bottom
  sheet), `64rem` (1024px: heroes and splits go side by side, the footer goes to five columns, the portal rail
  appears), `72rem` (1152px: the header bar replaces the drawer; it is compact up to 86rem and hides the
  Downloads button below 80rem). Components respond to their own width with named container queries:
  `multiview`, `flow`, `ladder`, `compare`, `table`, `bento`.
- **Reflow**: no horizontal page scroll from 320px to 2560px. The only sideways scrollers are inside components:
  the comparison table, data tables, the screenshot strip on phones, tabs, the sub-nav and the portal nav.

---

## 7. Motion

- Durations are tokens that are **zero unless** `prefers-reduced-motion: no-preference`: `--d-1` 120ms (hover,
  press), `--d-2` 200ms (toggles, rows), `--d-3` 320ms (panels, timer colour). Easing: `--e-cut`
  `cubic-bezier(.2,0,0,1)` (settles like a fader) and `--e-std`. Every keyframe animation sits behind the same media
  query, so reduced motion stops everything.
- Progressive enhancements: the SMPTE scroll-progress line under the header (`animation-timeline: scroll()`),
  the Show Bus stripe drawing in as it enters (`view()`), FAQ answers opening with `interpolate-size`, and
  reveal-on-scroll (`data-reveal`).
- No view transitions, for a theme change or between pages. A theme change sets `data-theme` at once, and a
  page restored from the back/forward cache (or open in another tab) re-reads the stored choice. Snapshots could
  show one theme while the live page painted the other.
- Anything that updates by itself has a visible pause control (WCAG 2.2.2): the mixer's **Auto** key and the
  countdown's **Pause**. Both stop when off screen or when the tab is hidden. Under reduced motion the mixer never
  auto-takes, TAKE is a cut, and the timecode shows whole seconds. The countdown keeps ticking because the numbers
  are information.
- Nothing loops for ever. The typing dots run only while an answer is coming.

---

## 8. Components

Every snippet below is real markup with the real class names. Icons are written `<svg class="c-icon"
aria-hidden="true"><use href="#i-NAME"/></svg>` (see 8.30).

### 8.1 Layout

```html
<section class="l-section" aria-labelledby="x-title">          <!-- top rule + registration crosses -->
  <div class="l-wrap"> … </div>
</section>
<!-- variants: l-section--tint (s1 band), --tight (60% padding), --flush (no rule) -->
<div class="l-grid l-grid--3"> … </div>                          <!-- 1 → 2 (40rem) → 3 (64rem); also --2, --4 -->
<div class="l-split"> <div>…</div> <div>…</div> </div>           <!-- 5/7 from 64rem; --even, --aside (4/8), --main (7/5) -->
<div class="l-stack">…</div>  <div class="l-cluster">…</div>
```

### 8.2 Skip link, announcement strip

```html
<a class="c-skip" href="#main">Skip to content</a>
<div class="c-announce" data-dismissible="launch-2026" role="region" aria-label="Announcement">
  <div class="l-wrap c-announce-bar">
    <p class="c-announce-text"><i class="c-lamp c-lamp--standby" aria-hidden="true"></i>
      <span>StageWatch Production: <b>£59</b> <s><span class="u-visually-hidden">was </span>£79</s> launch price, one-off.</span>
      <a href="/stagewatch/licensing/">See pricing</a></p>
    <button class="c-announce-close" type="button" data-dismiss aria-label="Dismiss the announcement">…i-close…</button>
  </div>
</div>
```
One line, 40px tall, ellipsis when narrow, so put the key fact first.

### 8.3 Site header, Products mega-menu, mobile drawer, theme toggle

```html
<header class="c-header" data-header>
  <div class="l-wrap c-header-bar">
    <a class="c-brand" href="/" aria-label="Patterns Show Control, home">
      <span class="c-brand-mark" aria-hidden="true"></span>
      <span class="c-brand-name">Patterns <span>Show Control</span></span>
    </a>
    <nav class="c-nav" id="site-nav" aria-label="Main">
      <ul class="c-nav-list" role="list">
        <li><a class="c-nav-link" href="/ecosystem/">Ecosystem</a></li>
        <li class="c-mega" data-mega>
          <button class="c-nav-link" type="button" aria-expanded="false" aria-controls="mega-products">
            Products <span class="c-mega-mark" aria-hidden="true"></span>…i-chevron (c-icon--sm)…</button>
          <div class="c-mega-panel" id="mega-products">
            <ul class="c-mega-grid" role="list">
              <li class="c-mega-product" data-product="stagewatch">
                <a class="c-mega-title" href="/stagewatch/"><span class="c-swatch"></span>StageWatch <span class="c-role">Time</span></a>
                <p class="c-mega-line">Stage timer and show calling</p>
                <ul class="c-mega-links" role="list"><li><a href="/stagewatch/">Overview</a></li><li><a href="/stagewatch/features/">Features</a></li>
                  <li><a href="/stagewatch/licensing/">Licensing</a></li><li><a href="/support/stagewatch/">Help</a></li></ul>
              </li>
              <!-- one li per product, from content/site.php nav.children -->
            </ul>
            <ul class="c-mega-foot" role="list"><li><a href="/ecosystem/">How the apps work together</a></li>…</ul>
          </div>
        </li>
        <li><a class="c-nav-link" href="/workflows/" aria-current="page">Workflows</a></li>   <!-- aria-current="true" for a section -->
        <li><a class="c-nav-link" href="/licensing/">Licensing</a></li>
        <li><a class="c-nav-link" href="/support/">Support</a></li>
      </ul>
      <div class="c-nav-actions">
        <a class="c-btn c-btn--secondary" href="/downloads/">Downloads</a>
        <a class="c-btn c-btn--primary" href="/portal/login/" data-account-link data-account-href="/portal/" data-account-label="My account">
          …i-user (c-icon--sm)…<span data-account-text>Client login</span></a>
      </div>
    </nav>
    <div class="c-header-tools">
      <div class="c-theme" role="group" aria-label="Colour theme" data-theme-toggle>
        <button type="button" data-theme-set="system" aria-pressed="true" title="Follow the system">…i-system…<span class="u-visually-hidden">Follow the system</span></button>
        <button type="button" data-theme-set="light" aria-pressed="false" title="Light theme">…i-sun…<span class="u-visually-hidden">Light</span></button>
        <button type="button" data-theme-set="dark" aria-pressed="false" title="Dark theme">…i-moon…<span class="u-visually-hidden">Dark</span></button>
      </div>
      <button class="c-btn c-btn--secondary c-btn--sm c-menu-btn" type="button" aria-expanded="false" aria-controls="site-nav" data-nav-toggle>
        <svg class="c-icon c-icon-open" aria-hidden="true"><use href="#i-menu"/></svg><svg class="c-icon c-icon-close" aria-hidden="true"><use href="#i-close"/></svg><span data-nav-label>Menu</span>
      </button>
    </div>
  </div>
  <div class="c-scroll-line" aria-hidden="true"></div>
</header>
```
- Below 72rem the same `nav` is a drawer (`.is-nav-open` on the header). Escape, an outside click, a link or
  widening the window closes it. The button always says **Menu** or **Close**.
- Mega-menu keys: Enter, Space or click toggles; ArrowDown opens it and enters the panel; ArrowUp, ArrowDown, Home
  and End move between links; Escape closes it and returns focus. Leaving the menu closes it.
- `site.js` sets `aria-pressed` on the theme button the stored choice names. A single `<button data-theme-toggle>` without
  `data-theme-set` children also works and toggles between light and dark.
- Without JavaScript the drawer links sit open under the bar, the mega panel opens on hover or focus, and the theme
  control is hidden (`@media (scripting: none)`).
- Below 30rem the brand wordmark stacks on two lines. Below 22.5rem only the mark shows; the link keeps its
  `aria-label`.

### 8.4 Breadcrumbs

```html
<nav class="c-crumbs" aria-label="Breadcrumb"><ol><li><a href="/">Home</a></li><li><a href="/support/">Support</a></li>
  <li><a href="/support/faq/" aria-current="page">Frequently asked questions</a></li></ol></nav>
```
- **The layout places them; a template never does.** `templates/layouts/base.php` renders the trail from the page's
  `Seo` breadcrumbs (every page but home) and puts it first inside the page hero's `.l-wrap`, before `.c-hero-grid`,
  so it takes the hero's width (the legal pages' prose column too), backdrop and product bar. A page whose content
  does not open with a `.c-hero` gets the trail as a strip of its own, `<div class="l-wrap c-crumbs-bar">`, above it.
  The BreadcrumbList JSON-LD comes from the same list and does not depend on where the trail is drawn.
- **One rhythm.** The trail sits `clamp(16px, 2vw, 24px)` under the header (inside a product hero, under its 3px
  bar), and every hero's first line (eyebrow, product tag or h1) sits `clamp(24px, 3vw, 40px)` under the trail,
  whatever the hero's own top padding. Links are 24px tall.
- Flash messages (8.23) sit in their own strip above the hero, never between the trail and the hero's heading.

### 8.5 Buttons and keys

```html
<a class="c-btn c-btn--primary" href="…">Explore the ecosystem</a>   <!-- neutral, or the product's bar inside data-product -->
<a class="c-btn c-btn--secondary" href="…">Start a free trial</a>     <!-- outlined with --field -->
<button class="c-btn c-btn--ghost" type="button">Cancel</button>
<button class="c-btn c-btn--danger" type="button">Delete account</button>
<!-- sizes: c-btn--sm (36px), default (48px), c-btn--lg (56px); c-btn--block; icon-only: c-btn--icon + aria-label -->
<button class="c-take" type="button">TAKE</button>                    <!-- only for a real take -->
<button class="c-go" type="button">GO <small>Q3</small></button>      <!-- only for a real GO -->
```
Portal aliases: `pt-btn`, `pt-btn--primary|secondary|ghost|danger|small`. Disabled: `disabled` or
`aria-disabled="true"`.

### 8.6 Segmented control

```html
<fieldset class="c-seg"><legend class="u-visually-hidden">Status filter</legend>
  <label><input type="radio" name="f" checked><span>All</span></label><label><input type="radio" name="f"><span>Open</span></label></fieldset>
<div class="c-seg" role="group" aria-label="View"><button type="button" aria-pressed="true">Cards</button><button type="button" aria-pressed="false">Table</button></div>
```

### 8.7 Badges, tags, roles, app chips

```html
<span class="c-badge c-badge--open">Open</span>            <!-- hollow dot -->
<span class="c-badge c-badge--triaged">Triaged</span>      <!-- half dot -->
<span class="c-badge c-badge--planned">Planned</span>      <!-- dashed edge -->
<span class="c-badge c-badge--progress">In progress</span> <!-- amber; alias c-badge--standby -->
<span class="c-badge c-badge--done">Done</span>            <!-- green; alias c-badge--go -->
<span class="c-badge c-badge--declined">Declined</span>    <!-- red with a dash; alias c-badge--alert -->
<span class="c-badge c-badge--muted">Closed</span>
<span class="c-badge c-badge--tier">Pro</span>             <!-- square, outlined in the product line -->
<span class="c-role">Time</span>                           <!-- inside data-product: filled with the bar -->
<span class="c-app" data-product="playmap"><i class="c-swatch"></i>PlayMap</span>
<span class="c-tag">Windows 11</span>
```
Portal: `pt-badge pt-badge--{open, triaged, planned, in_progress, done, declined, closed, requested,
awaiting_payment, issued, revoked, active, pending, approval, disabled, new, read, archived}` are status pills.
`admin, client, bug, feature, question, stable, beta, private, public` are square tags without a dot.

| Variant | Colour | Marker (the cue that is not colour) |
|---|---|---|
| `open`, `requested` / `triaged` / `planned` | neutral | hollow dot / half dot / dashed edge |
| `new` / `closed`, `read`, `archived` | neutral / muted, no fill | solid dot / grey dot |
| `in_progress`, `awaiting_payment`, `pending`, `approval` | amber (standing by) | dot |
| `done`, `issued`, `active` | green | dot |
| `declined`, `revoked`, `disabled` | red | dash instead of a dot |
| Severity `low`, `normal`, `high`, `show_stopper` | muted, neutral, amber, red | a three-bar level meter with 1, 2, 3 and 3 bars lit; show-stopper is the only filled pill |
| Assistant verdicts `in_scope` (Answered), `needs_human` (Needs a person) | green, amber | dot |
| `out_of_scope`, `code_or_internals`, `code_guard` / `abuse` | red | dash (the last two with a dashed edge) / a filled pill |

Status never uses a product colour, and the word is always inside the badge.

### 8.8 Tallies and lamps (cue dots)

```html
<span class="c-tally c-tally--pgm">PGM</span> <span class="c-tally c-tally--pvw">PVW</span> <span class="c-tally c-tally--standby">STBY</span>
<span class="l-cluster"><i class="c-lamp c-lamp--go"></i>Go</span>   <!-- also --pgm, --standby, --off; always beside a word -->
```

### 8.9 Heroes

Home, with a visual slot (the mixer is in 8.11):
```html
<section class="c-hero" aria-labelledby="hero-title" data-chat-tuck>
  <div class="l-wrap c-hero-grid">
    <div>
      <h1 class="c-hero-title" id="hero-title">Everything the room sees, on cue.</h1>
      <p class="c-hero-lede">…</p>
      <div class="c-hero-actions"><a class="c-btn c-btn--primary" href="/ecosystem/">Explore the ecosystem</a><a class="c-btn c-btn--secondary" href="/downloads/">Start a free trial</a></div>
      <p class="c-hero-note">Works with Bitfocus Companion and Stream Deck, OSC, MIDI, NDI, and AI tools over MCP, with a person approving every change.</p>
    </div>
    <div class="c-hero-visual">…</div>
  </div>
</section>
```
Product (a 3px product bar on top and a followspot behind the visual):
```html
<section class="c-hero c-hero--product" aria-labelledby="p-title">   <!-- inside <main data-product="…"> -->
  <div class="l-wrap">
    <nav class="c-crumbs" aria-label="Breadcrumb">…</nav>   <!-- put here by the layout (8.4) -->
    <div class="c-hero-grid">
      <div>
        <p class="c-product-tag"><span class="c-swatch"></span>StageWatch <span class="c-role">Time</span></p>
        <h1 class="c-hero-title" id="p-title">The clock the whole stage trusts.</h1>
        <p class="c-hero-lede">…</p>
        <div class="c-hero-actions">…</div>
        <dl class="c-specs u-mt-6"><div><dt>Presenter</dt><dd>Confidence monitor</dd></div>…</dl>
      </div>
      <div class="c-hero-visual">…confidence monitor (8.18) or a screenshot (8.19)…</div>
    </div>
    <div class="c-shots">…three .c-shot…</div>
    <nav class="c-subnav u-mt-6" aria-label="StageWatch pages"><a href="/stagewatch/" aria-current="page">Overview</a><a href="/stagewatch/features/">Features</a>…</nav>
  </div>
</section>
```
Compact page (support, legal, workflows, downloads, contact, about):
```html
<section class="c-hero c-hero--page" aria-labelledby="t">
  <div class="l-wrap">
    <nav class="c-crumbs" aria-label="Breadcrumb">…</nav>   <!-- put here by the layout (8.4) -->
    <div class="c-hero-grid"><p class="c-eyebrow">Support</p><h1 class="c-hero-title" id="t">Questions before the show</h1><p class="c-hero-lede">…</p></div>
  </div>
</section>
```

### 8.10 Section heading, eyebrow, legend, tagline

```html
<div class="c-section-head">                       <!-- title left, lede right from 64rem; c-section-head--stack keeps them stacked -->
  <div><p class="c-eyebrow">Ecosystem</p><h2 id="s">How they connect</h2></div>
  <p class="c-lede">…</p>
</div>
<section class="c-legend" aria-label="How colour is used"><div class="l-wrap c-legend-inner">
  <p class="c-legend-title">Every colour on this site means one thing. …</p>
  <ul class="c-legend-list" role="list"><li><b>Reference</b><span>The ecosystem, Show Bus</span></li>…eight items, in bar order, then Standing by…</ul>
</div></section>
<p class="c-stops">Every pixel<span data-product="patterns">.</span> Every second<span data-product="stagewatch">.</span>
  Every person<span data-product="agmessage">.</span> Every surface<span data-product="playmap">.</span></p>
```
The eyebrow's marker is the four app bars on ecosystem pages and the product's bar inside `data-product`.

### 8.11 Home hero mixer (`hero.js`)

```html
<div class="c-mixer" data-hero role="group" aria-label="Test pattern preview and programme">
  <div class="c-monitor c-reg c-bars-poster"><canvas data-hero-pgm role="img" aria-label="Programme monitor showing SMPTE colour bars"></canvas></div>
  <div class="c-umd"><span class="c-tally c-tally--pgm">PGM</span><span class="c-umd-name" data-hero-pgm-name>SMPTE bars 75%</span>
    <span class="c-umd-meta">1920×1200 50p</span><span class="c-umd-tc" data-hero-tc><?= date('H:i:s') ?>:00</span></div>
  <div class="c-takebar">
    <div class="c-pvw"><canvas data-hero-pvw width="192" height="120" aria-hidden="true"></canvas>
      <div class="c-pvw-text"><span class="c-tally c-tally--pvw">PVW</span><span class="c-pvw-name" data-hero-pvw-name>Crosshatch 16×10</span></div></div>
    <div class="c-take-actions">
      <fieldset class="c-seg"><legend class="u-visually-hidden">Transition</legend>
        <label><input type="radio" name="hero-trans" value="dissolve" data-hero-trans checked><span>Dissolve</span></label>
        <label><input type="radio" name="hero-trans" value="dip" data-hero-trans><span>Dip</span></label>
        <label><input type="radio" name="hero-trans" value="wipe" data-hero-trans><span>Wipe</span></label>
        <label><input type="radio" name="hero-trans" value="push" data-hero-trans><span>Push</span></label></fieldset>
      <button class="c-btn c-btn--secondary c-btn--sm c-auto" type="button" aria-pressed="true" data-hero-auto><i class="c-lamp" aria-hidden="true"></i>Auto demo</button>
      <button class="c-take" type="button" data-hero-take>TAKE</button>
    </div>
  </div>
</div>
```
It draws six real patterns: SMPTE bars with PLUGE, a crosshatch, an LED cabinet map, a zone plate, ramps with a
step wedge, and a checkerboard. Before the script runs, or without it, the monitor shows the CSS bars poster
(`.c-bars-poster`) and the server's time. The page's LCP is the h1, not the canvas. The transitions are four of
Patterns' own (Dissolve, Dip, Wipe, Push); each frame of one is at most two drawImage calls and a fill. Auto demo
is the demo's own control, not a Patterns feature: it pauses and resumes the automatic takes (WCAG 2.2.2). The
loop stops off screen and in hidden tabs. Put `data-chat-tuck` on
the hero so the floating launcher stays out of the picture.

### 8.12 Product cards (the multiview)

```html
<div class="c-multiview"><ul class="c-multiview-grid" role="list">
  <li class="c-product-card" data-product="patterns">
    <div class="c-product-card-screen c-screen" aria-hidden="true"><svg viewBox="0 0 320 200">…</svg></div>   <!-- or a 16:10 <img alt=""> -->
    <div class="c-umd"><span class="c-umd-bar"></span><h3>Patterns</h3><span class="c-umd-role">Picture</span></div>
    <div class="c-product-card-body">
      <p>…</p>
      <ul class="c-facts" role="list"><li>Portable app, runs from a USB stick</li>…</ul>
      <p class="c-links"><a href="/patterns/">Patterns overview</a><a href="/patterns/features/">Features</a></p>
    </div>
  </li>
</ul></div>
```
Tiles are 1, 2, then 4 across (container widths 38rem and 70rem). The drawn screens for all four apps are in the
kitchen sink; keep them as PHP partials. Generic cards: `.c-card` (`.c-card-title`, `.c-card-meta`,
`.c-card-body`, `.c-card-footer`), `.c-card--link` when the whole card is a link, and `data-product` adds the
product's top bar.

### 8.13 Feature grid and bento

```html
<div class="c-features">
  <article class="c-feature" data-reveal>…i-pattern…<h3>Pixel-exact patterns</h3><p>…</p><ul class="c-facts" role="list">…</ul></article>
</div>
<div class="c-bento">
  <article class="c-bento-item c-bento-item--wide"><div class="c-bento-media c-screen">…img or svg…</div><h3>…</h3><p>…</p></article>
  <article class="c-bento-item c-bento-item--tall">…</article>
  <article class="c-bento-item">…</article>
</div>
```
Bento items are full width, then half, then a third; `--wide` is two thirds and `--tall` spans two rows.

### 8.14 Integration chips (no logos)

```html
<ul class="c-chips" role="list">
  <li class="c-chip"><span class="c-chip-mark">BFC</span><span>Bitfocus Companion<small>Module for every app</small></span></li>
  <li class="c-chip"><span class="c-chip-mark">NDI</span>NDI®</li>
</ul>
```

### 8.15 Spec and requirements lists, key–value facts, code lines

```html
<dl class="c-specs"><div><dt>Cue lights</dt><dd>Art-Net, sACN, Hue</dd></div>…</dl>      <!-- hairline grid -->
<dl class="c-specs c-specs--rows"><div><dt>Windows</dt><dd>Windows 10 and 11, 64-bit</dd></div>…</dl>   <!-- requirements -->
<dl class="c-kv"><dt>Licence</dt><dd>StageWatch Pro</dd>…</dl>                           <!-- portal: pt-kv -->
<div class="c-codeline"><code id="sha">sha256 9f2c…</code>
  <button class="c-btn c-btn--ghost c-btn--sm c-copy" type="button" data-copy-target="#sha">…i-copy…<span data-copy-label>Copy</span></button></div>
```
`data-copy="literal text"` also works. The label says "Copied" for two seconds and a live region announces it.

### 8.16 Signal flow (how they connect)

```html
<figure class="c-flow-wrap">
  <div class="c-flow">
    <p class="c-flow-h c-flow-h--in">Inputs</p><p class="c-flow-h c-flow-h--bus" aria-hidden="true">Bus</p>
    <p class="c-flow-h c-flow-h--apps">Apps</p><p class="c-flow-h c-flow-h--room">To the room</p>
    <ul class="c-flow-inputs" role="list">
      <li class="c-flow-node c-flow-node--ctl"><h3>…i-deck…Companion and Stream Deck</h3><p>Keys that show live state</p></li>
      <li class="c-flow-node c-flow-node--ctl"><h3>OSC and MIDI</h3><p>…</p></li>
      <li class="c-flow-proposal">
        <div class="c-flow-node c-flow-node--ai"><h3>AI tools over MCP</h3><p>Read the show, propose a change</p></div>
        <span class="c-flow-wire c-flow-wire--proposal">Proposal</span>
        <div class="c-flow-gate"><h3>…i-approve…A person approves</h3><p>Approve, edit or decline</p>
          <span class="c-flow-gate-state"><i class="c-lamp c-lamp--standby"></i>Standing by for you</span></div>
      </li>
    </ul>
    <div class="c-flow-bus"><b>Show Bus</b><span>Discovery, shared state and approvals on the venue network. No internet needed.</span></div>
    <ul class="c-flow-apps" role="list">
      <li class="c-flow-app" data-product="patterns"><div class="c-flow-node c-flow-node--app"><h3><span class="c-swatch"></span>Patterns</h3><p>…</p></div>
        <p class="c-flow-out">LED walls, projectors, NDI receivers</p></li>
      …StageWatch, AGMessage, PlayMap…
    </ul>
  </div>
  <figcaption class="c-flow-note u-mt-4">… AI tools over MCP connect only to the approval gate, never to the bus.</figcaption>
</figure>
```
From a container width of 56rem it is four columns with wires: operator inputs reach the vertical bus, the AI
reaches only the amber gate, and each app feeds "To the room". Narrower, it stacks with the bus as a bar. All
labels are HTML text, so they stay at 14–16px on a phone.

### 8.17 Workflow steps: the cue ladder

```html
<div class="c-ladder" data-ladder>                  <!-- c-ladder--static: no State column, no controls -->
  <div class="c-ladder-head" aria-hidden="true"><span>Cue</span><span>Step</span><span>App</span><span>Takes</span><span>State</span></div>
  <ol class="c-ladder-list" role="list">
    <li class="c-cue" data-cue="Q1" data-state="done" data-product="playmap">   <!-- done | standby | waiting -->
      <span class="c-cue-q">Q1<small>T−04:00</small></span>
      <div class="c-cue-body"><h3>Scan and blend the screen</h3><p>…</p></div>
      <div class="c-cue-meta"><span class="c-app"><i class="c-swatch"></i>PlayMap</span><span class="c-cue-time">15 min</span>
        <span class="c-cue-state"><i class="c-lamp"></i><span data-cue-label>Done</span></span></div>
    </li>
  </ol>
  <div class="c-ladder-controls">
    <button class="c-go" type="button" data-ladder-go>GO <small data-ladder-go-q>Q3</small></button>
    <button class="c-btn c-btn--secondary c-btn--sm" type="button" data-ladder-back>Back</button>
    <p class="c-ladder-status" data-ladder-status aria-live="polite">Standing by: Q3, build the running order.</p>
    <a href="/workflows/">See all six workflows</a>
  </div>
</div>
```
Workflow pages render `content/workflows.php` steps as cues. The `T−hh:mm` line counts down to doors and is
optional. Below 46rem of container width the meta drops under the step.

### 8.18 Confidence monitor (`countdown.js`)

```html
<div class="c-hero-visual" data-countdown-scope>
  <div class="c-cm" data-countdown="75" data-warn="60" data-overrun="12" data-state="go" role="img" aria-label="StageWatch confidence monitor: a keynote countdown">
    <div class="c-cm-top"><span class="c-cm-room">Main stage</span><span class="c-cm-onair">ON AIR</span><span class="c-cm-clock" data-countdown-clock>14:32:07</span></div>
    <div class="c-cm-main"><p class="c-cm-seg">Keynote <span>Opening address</span></p><p class="c-cm-time" data-countdown-time>01:15</p>
      <p class="c-cm-msg" data-countdown-msg></p><div class="c-cm-bar"><span class="c-cm-fill" data-countdown-fill></span></div></div>
    <div class="c-cm-foot"><span>Next</span><b>Panel: the hybrid AGM</b><span class="c-cm-clock">14:45</span></div>
  </div>
  <div class="c-depts">
    <div class="c-dept" data-dept data-standby-at="15" data-go-at="0"><b>LX</b><span><i class="c-lamp"></i><span data-dept-state>Clear</span></span></div>…
  </div>
  <div class="c-cm-controls">
    <button class="c-btn c-btn--secondary c-btn--sm" type="button" data-countdown-action="pause" aria-pressed="false">…i-pause…<span data-label>Pause</span></button>
    <button class="c-btn c-btn--secondary c-btn--sm" type="button" data-countdown-action="minus" aria-label="Take a minute off">−1:00</button>
    <button class="c-btn c-btn--secondary c-btn--sm" type="button" data-countdown-action="plus" aria-label="Add a minute">+1:00</button>
    <button class="c-btn c-btn--ghost c-btn--sm" type="button" data-countdown-action="reset">…i-reset…Reset</button>
    <ul class="c-cm-legend" role="list"><li><i class="c-lamp c-lamp--go"></i>On time</li><li><i class="c-lamp c-lamp--standby"></i>Wrap up at 1:00</li><li><i class="c-lamp c-lamp--pgm"></i>Over time</li></ul>
  </div>
</div>
```
The clock is green, amber ("WRAP UP") from `data-warn` seconds, and red counting up ("OVER TIME"). It loops after
`data-overrun` seconds over. It is sized in container units, so it works from 280px to full width.

### 8.19 Screenshot frame (16:10)

```html
<figure class="c-shot">
  <div class="c-shot-frame">
    <img src="/assets/img/products/patterns/run-mode-800.webp" srcset="…-800.webp 800w, ….webp 1600w" sizes="(width >= 64rem) 40vw, 90vw"
         width="1600" height="1000" alt="Patterns in Run mode: the cue stack with the five-minute call standing by" loading="lazy" decoding="async">
  </div>
  <figcaption class="c-shot-caption">What the screen shows, in the product's words.</figcaption>
</figure>
<!-- until the capture exists -->
<div class="c-shot-frame"><div class="c-shot-placeholder"><span class="c-shot-label"><b>StageWatch — cue ladder</b>1920 × 1200 · 16:10</span></div></div>
<div class="c-shots" data-scroll-region="StageWatch screenshots">…three figures; a swipeable strip below 48rem…</div>   <!-- c-shots--2 for two -->
```

### 8.20 Product sub-navigation and tabs

```html
<nav class="c-subnav" aria-label="StageWatch pages"><a href="/stagewatch/" aria-current="page">Overview</a>…</nav>
<nav class="c-tabs" aria-label="Ticket types"><a href="?" aria-current="page">All <span class="c-tabs-count">12</span></a>…</nav>   <!-- portal: pt-tabs -->
<div class="c-tabs" role="tablist" aria-label="Downloads" data-tabs>
  <button role="tab" type="button" id="t1" aria-controls="p1" aria-selected="true">Latest</button>…</div>
<div class="c-tabpanel" role="tabpanel" id="p1" aria-labelledby="t1" tabindex="0">…</div>   <!-- others hidden -->
```

### 8.21 Pricing: tier cards, comparison table, event pass

```html
<div class="c-tiers">
  <article class="c-tier is-rec"><span class="c-flag">Best for one room</span>
    <h3 class="c-tier-name">Pro</h3>
    <p class="c-price"><span class="c-price-amount">£29</span><span class="c-price-unit">One-off · one PC</span></p>
    <p class="c-tier-sub">…</p>
    <ul class="c-tier-list" role="list"><li>…i-check…Everything in Standard</li>…</ul>
    <div class="c-tier-cta"><a class="c-btn c-btn--primary" href="…">Request Pro</a></div>
  </article>
  <article class="c-tier"><span class="c-flag c-flag--outline">Launch price</span>…
    <p class="c-price"><span class="c-price-amount">£59</span><s class="c-price-was"><span class="u-visually-hidden">was </span>£79</s>…</p></article>
</div>

<div class="c-compare" data-compare>
  <p class="c-compare-hint">…i-arrow…Scroll sideways to compare all five licences</p>
  <div class="c-compare-scroll" data-compare-scroll tabindex="0" role="region" aria-label="StageWatch licence comparison">
    <table class="c-compare-table">
      <caption class="u-visually-hidden">What each StageWatch licence includes</caption>
      <thead>
        <tr><th scope="col">Feature</th>
          <th scope="col" class="is-rec"><span class="c-tier-head"><span class="c-flag">Best for one room</span><span class="c-tier-name">Pro</span>
            <span class="c-price"><span class="c-price-amount">£29</span></span></span></th>…</tr>
        <tr><td></td><td class="is-rec"><span class="c-tier-head-sub">One-off, one PC</span><a class="c-btn c-btn--primary c-btn--sm" href="…">Request Pro</a></td>…</tr>
      </thead>
      <tbody>
        <tr class="c-compare-group"><th scope="rowgroup" colspan="6">The timer</th></tr>
        <tr><th scope="row">Running order<small>Next, auto-advance, ahead/behind</small></th>
          <td><span class="c-yes">…i-check…<span class="u-visually-hidden">Included</span></span></td>
          <td><span class="c-no">…i-dash…<span class="u-visually-hidden">Not included</span></span></td>
          <td class="is-rec"><span class="c-val">12</span></td>…</tr>
      </tbody>
    </table>
  </div>
  <p class="c-fineprint">Prices in pounds sterling, one-off. …</p>
</div>

<div class="c-pass">…i-ticket…<b>Production event pass <span class="c-price-amount">£15</span></b><p>…</p><a class="c-btn c-btn--secondary c-btn--sm" href="…">Request an event pass</a></div>
```
- Mark the recommended column with `.is-rec` on every cell in it. It follows `--accent`, so it is the product's
  colour on every licence page.
- `site.js` sets `data-overflow` on `.c-compare` to `none`, `start`, `middle` or `end`. When the table fits, the
  first header row sticks under the site header. When it scrolls (on phones), the feature column sticks, the hint
  shows and the right edge fades. Generate rows and groups from `content/licensing/<product>.php` `matrix`.
- **The sticky header row** is `position: sticky; top: var(--header-h)` only under `[data-overflow="none"]`, where
  `.c-compare-scroll` is `overflow: visible` and so is no scroll container: the row sticks to the page, under the
  sticky site header. Anywhere else (scrolling sideways, without scripting, or before `site.js` has measured it) the
  scroller would be the row's sticky container and the offset would push the row down over the rows beneath, so the
  row stays in place. Head cells are opaque (`--s1`, the recommended column its tint over `--s1`), so rows never
  show through. Pages need no override of their own; nothing between the table and the page may set an `overflow`
  other than `visible` or `clip`.
- Each `.c-tier-head` reserves a 30px row for the flag, so names and prices line up across the head row. A flag that
  wraps to a second line grows its own row instead of being drawn over the tier name.
- Tier cards come first on phones; the table is the full detail.

### 8.22 FAQ accordion

```html
<div class="c-faq" id="faq-stagewatch-licence">          <!-- partials/faq.php with name 'stagewatch-licence-faq' -->
  <details class="c-faq-item" name="stagewatch-licence-faq" open>   <!-- the same name makes it exclusive -->
    <summary class="c-faq-q"><span>What happens when the <span class="u-nowrap">14-day</span> trial ends?</span><span class="c-xhair" aria-hidden="true"></span></summary>
    <div class="c-faq-a"><p>…</p></div>
  </details>
</div>
```
- Render it with `templates/partials/faq.php` (`faqs`, `name`, `schema`, `openFirst`). The block's anchor is
  `faq-<name>`, without repeating a leading `faq-` or trailing `-faq` (`home-faq` → `#faq-home`, `trouble` →
  `#faq-trouble`). With `schema` on, the page's FAQPage node takes that one fragment as its `@id`
  (`https://…/stagewatch/licensing/#faq-stagewatch-licence`), so the node names the block it describes. Give each
  block on a page its own name (it is also the exclusive-open group), which keeps the anchors unique; emit the
  FAQPage from one block per page.
- The question is one `span` (the summary is a two-column grid: the words, then the crosshair). Hyphenated words
  are wrapped in `u-nowrap`, as in the product headings, so "14-day" or "back-room" never breaks at the hyphen on a
  phone; the longest such word in the content fits a 320px screen.

### 8.23 Callouts, flash messages, toasts

```html
<div class="c-callout c-callout--info" role="note">…i-info…<strong class="c-callout-title">Show in the next 48 hours?</strong><p>…</p></div>
<!-- c-callout--success (i-ok, role="status"), --warning (i-alert), --danger (i-error, role="alert") -->
<div class="pt-alert pt-alert--success" role="status">Your licence request was sent.</div>   <!-- templates/partials/flashes.php -->
<div class="c-toasts" aria-live="polite">
  <div class="c-toast c-toast--success" data-dismissible>…i-ok…<div><strong>Vote added</strong><p>48 people want this.</p></div>
    <button class="c-btn c-btn--ghost c-btn--icon c-btn--sm" type="button" data-dismiss aria-label="Dismiss">…i-close…</button></div>
</div>
```
Toasts never time out on their own (WCAG 2.2.1); they have a close button.

The layout renders a page's flash messages in a strip under the header, before the hero and its breadcrumbs:
`<div class="l-wrap c-flashes">` holding `templates/partials/flashes.php`. The messages carry the strip's space
(16px above and below each), so a strip whose messages a page hides takes no room.

Every flash message, and every answer to what the visitor just did ("Message sent", "Check your email"), carries
`data-feedback` and `tabindex="-1"`: `site.js` focuses the first visible one on load, so it is the first thing
read. When the address's #fragment points elsewhere on the page (a new reply, a vote), the message is announced
instead and the page stays where the fragment put it. While a form's error summary shows a refusal, `site.css`
hides the strip's copy of it, so the refusal is said once.

### 8.24 Forms

```html
<form class="c-form c-form--panel" method="post" action="/contact/" novalidate>
  <div class="c-callout c-callout--danger c-error-summary" data-error-summary tabindex="-1" role="alert" aria-labelledby="es">
    …i-alert…<strong class="c-callout-title" id="es">Check one field before sending</strong>
    <ul><li><a href="#email">Enter an email address in the format name@example.co.uk</a></li></ul></div>
  <div class="c-form-row">
    <div class="c-field"><label for="name">Name</label><input id="name" name="name" autocomplete="name"></div>
    <div class="c-field is-invalid"><label for="email">Email</label>
      <input id="email" name="email" type="email" autocomplete="email" aria-invalid="true" aria-describedby="email-err">
      <p class="c-error-msg" id="email-err">Enter an email address in the format name@example.co.uk</p></div>
  </div>
  <div class="c-field"><label for="org">Organisation <span class="c-label-opt">Optional</span></label><input id="org" name="org" autocomplete="organization"></div>
  <div class="c-field"><label for="product">Product</label><select id="product" name="product">…</select></div>
  <fieldset class="c-fieldset"><legend>What is it about?</legend>
    <div class="c-radio-cards"><label class="c-radio-card"><input type="radio" name="topic" value="sales"><span>Buying a licence</span></label>…</div></fieldset>
  <div class="c-field"><label for="msg">Message</label><p class="c-help" id="msg-help">What you are running, on which machines, and what you expected to happen.</p>
    <textarea id="msg" name="message" maxlength="2000" aria-describedby="msg-help msg-count" data-count="#msg-count"></textarea>
    <p class="c-count" id="msg-count">0 / 2000</p></div>
  <div class="c-field"><label for="file">Attach the show's log <span class="c-label-opt">Optional</span></label><input id="file" name="file" type="file"></div>
  <label class="c-check"><input type="checkbox" name="copy">Send me a copy of this message</label>
  <div class="c-form-foot"><button class="c-btn c-btn--primary" type="submit">Send message</button><p>We use your details only to reply.</p></div>
</form>
```
- Labels above, the hint above the control, `Optional` on the right. An error is an icon plus a sentence that
  says how to fix it, with `aria-invalid` and `aria-describedby`. After a failed POST the server renders the
  error summary and `site.js` focuses it.
- **The character counter is not a live region.** A count announced after every keystroke talks over the typing
  echo. `.c-count` is plain text that `site.js` keeps up to date (`data-count`), the server renders its starting value,
  and the field lists it in `aria-describedby` after the hint, so a screen reader reads the count when the field
  takes focus. Never add `aria-live` or a `role` to it.
- Controls inside `.c-form`, `.pt-form`, `.c-field` and `.pt-field` are styled without classes. Elsewhere use
  `.c-input`, `.c-select` and `.c-textarea`. Textareas grow with their content (`field-sizing`).
- Portal contract: `form.pt-form > div.pt-field > label + control + p.pt-help + p.pt-error`, `pt-field--inline`
  for a checkbox and its label, and `pt-fieldset > legend`.
- **One error summary partial** (`templates/partials/error-summary.php`), first inside every form that can be
  refused, public or portal. It lists each field's error as a link to its control (links at least 24px tall), or,
  when no field explains a refusal (too many sends, a send faster than a person), says why. On a page with several
  forms only the form that was sent shows it. Forms carry `novalidate`, so the server's summary replaces the
  browser's bubbles.
- **Sent once.** `site.js` marks a posting form busy on the first press, until the next page: `aria-busy` on the
  form and `aria-disabled` on the button (never `disabled`, so the button keeps its focus and its name). A button
  with `data-busy="Sending…"` shows and announces those words. Any further press does nothing, and a page
  restored by the browser's Back button can send again. Without JavaScript the form still posts, and the server's
  defences apply on their own.
- **Bot defences** (`Psc\Support\FormGuard`, on every public form that sends or stores something): a hidden
  honeypot and a signed, session-bound time the form was shown. A filled honeypot is answered as if it worked. A
  send faster than a person fills the form in comes back with the input kept and asks for one more press. It is
  never a silent loss.

### 8.25 Data tables (portal and admin), sorting, empty state

```html
<div class="c-table-wrap">                                   <!-- portal: pt-table-wrap > table.pt-table -->
  <table class="c-table c-table--stack">                     <!-- also --zebra, --compact; pt-table--stack -->
    <caption class="u-visually-hidden">Feature requests, most votes first</caption>
    <thead><tr><th scope="col">ID</th><th scope="col" aria-sort="descending"><button class="c-sort" type="button">Request</button></th>
      <th scope="col">Status</th><th scope="col" class="c-num">Votes</th></tr></thead>
    <tbody>
      <tr class="is-unread"><td data-label="ID"><span class="c-id">FR-0142</span></td>
        <td data-label="Request"><a href="…">Presenter view on a second machine</a><small>PowerPoint on the lectern laptop</small></td>
        <td data-label="Status"><span class="c-badge c-badge--planned">Planned</span></td>
        <td data-label="Votes" class="c-num"><button class="c-vote" type="button" aria-pressed="true" aria-label="Remove your vote, 48 votes">48</button></td></tr>
      <tr class="c-table-empty"><td colspan="4">No requests match this filter.</td></tr>
    </tbody>
  </table>
</div>
<div class="c-empty"><span class="c-empty-mark" aria-hidden="true"></span><p>You have not opened any tickets yet. …</p><a class="c-btn c-btn--primary" href="/portal/tickets/new/">Open a ticket</a></div>
```
- `.c-num` (`.pt-num`) right-aligns monospaced numbers. Dates are absolute (`<time datetime>`).
- **Stacked tables**: with `--stack`, a table narrower than 40rem becomes one card per row. Each cell shows its
  `data-label`, so give every `td` a `data-label`. Changing `display` can remove table semantics in some screen
  readers; when a stacked table matters, add `role="table"`, `role="row"`, `role="columnheader"` and
  `role="cell"` in the markup. Without `--stack`, a table scrolls inside its wrapper.
- A wrapper that scrolls and holds nothing focusable is made focusable and labelled (from the caption) by
  `site.js`, so keyboard users can scroll it (WCAG 2.1.1). Use `data-scroll-region="Label"` on any other
  sideways scroller.

### 8.26 Filters, search, pagination, stats, votes

```html
<form class="c-search" role="search" aria-label="Filter requests">        <!-- portal: pt-search -->
  <div class="c-search-field">…i-search (c-icon--sm)…<label class="u-visually-hidden" for="q">Search requests</label><input id="q" type="search" placeholder="Search requests"></div>
  <label for="status">Status</label><select id="status">…</select><button class="c-btn c-btn--secondary c-btn--sm" type="submit">Filter</button>
</form>
<nav class="c-pager" aria-label="Pages"><a rel="prev" href="…">Previous</a>
  <ol role="list"><li><a href="…">1</a></li><li><a href="…" aria-current="page">2</a></li><li><span>…</span></li></ol>
  <span class="c-pager-info">7–12 of 38</span><a rel="next" href="…">Next</a></nav>          <!-- portal: pt-pager, pt-pager-info -->
<div class="c-stats"><div class="c-stat"><span class="c-stat-value">12</span><span class="c-stat-label">Open tickets</span>
  <span class="c-stat-meta"><i class="c-lamp c-lamp--standby"></i>3 waiting on you</span></div>…</div>   <!-- portal: pt-stats -->
<button type="submit" class="pt-vote" aria-pressed="true"><span class="pt-vote-count">48</span> <span class="pt-small">votes</span><span class="u-visually-hidden"> — vote for “…”</span></button>
```
- A vote's triangle is hollow; pressed, it fills, the border doubles and the button turns green, with the
  template's "You voted" line beside it. A `form` that holds a vote is inline, so it sits in a table cell or in
  `.pt-header-actions` (where the vote grows to the 48px of the buttons beside it).
- `pt-search` is the portal's filter bar: a panel holding either labelled `div.pt-field` blocks (search fields take
  more room, help sits under the field) and a `.pt-actions` row aligned to the controls, or a bare label, select and
  button in one row. On phones the fields go two to a row and the search field takes a row of its own.
- A stat whose label is a link (`pt-stat-label > a`) makes the whole tile the target, with the focus ring round it.

### 8.27 Portal shell and parts (docs/PORTAL-UI.md)

`pt-shell` (a 13rem rail plus the main column from 64rem, 15rem from 90rem; on phones the rail is a sideways-scrolling row, and the
Administration links stay visible), `pt-nav` (links with `aria-current`), `pt-nav-heading`, `pt-nav-signout`,
`pt-main`, `pt-header` (`pt-eyebrow`, `pt-title`, `pt-lede`, `pt-header-actions`), `pt-section` + `pt-h2`,
`pt-grid--2|3`, `pt-card` (`--link`, `-title`, `-meta`, `-body`, `-footer`), `pt-stats`, `pt-table-wrap`,
`pt-empty`, `pt-actions`, `pt-kv`, `pt-thread` > `pt-comment` (`--staff` has a text-coloured bar; `--internal` is
amber and dashed), `pt-comment-meta`, `pt-comment-body`, `pt-files`, `pt-tabs`, `pt-pager`, `pt-vote`,
`pt-search`, `pt-prose`, `pt-auth` (the narrow sign-in card), and `pt-muted`, `pt-small`, `pt-mono`. Every one is
in the kitchen sink.

How the portal's markup is laid out (everything below is CSS; the templates keep the contract's markup):

- **Rail.** From 64rem a sticky 13rem rail (15rem from 90rem, so the admin lists fit the main column on a 1366px
  laptop). Below it, a compact band of sideways-scrolling rows with a faded right edge, the Administration row under
  it and the sign-out line last; the current page's link opens scrolled into the middle of its row
  (`scroll-initial-target`, an enhancement). Signed out (the rail has no sign-out form and
  the page is a `pt-auth` card), there is no rail: "Sign in" and "Create an account" become one segmented switch
  centred above the card.
- **Page flow.** Blocks in `pt-main`, a `pt-section` and `pt-auth` are 16px apart unless they set their own space;
  plain lists in a section are indented with 6px between items; an `h3` in a section is 17px with 24px above it.
  Paragraphs and plain lists there keep the 70ch body measure, and a `pt-alert` in a section the form's 46rem.
- **Header.** `pt-title` may hold a `pt-mono` reference; badges in `pt-lede` sit on the text line. A ghost button
  in `pt-header-actions` has a hairline so it reads as a button.
- **Ghost links.** A ghost link-button (`a.pt-btn--ghost`) in a `pt-actions` row (Cancel, Forgotten your password?,
  See all your tickets), or alone in a `pt-card-footer` (View details), is drawn as an underlined text link: its words
  line up with the text above at every width, also when the row wraps on a phone, and it keeps the button's height
  as its target; the row's gap widens to 24px. A ghost `button` (Sign out) stays a button.
- **Cards.** `pt-card--link` is one target: its `span` parts (`-title`, `-meta`, `-body`, `-footer`) are blocks and a
  badge sits at its top left. A bare `ul` (no class) in `pt-card-body` is a list of rows: the link, its badges beside it, the
  `pt-small` meta line under it, hairlines between. Headings in a card body are 16px; release notes (`pt-prose`)
  are 14px. A `pt-actions` row or `p` in `pt-card-footer` loses its own margin. A `form` can be the card.
- **Forms.** `pt-form` keeps a 46rem measure (the full width inside a card or `pt-auth`). The contract's order is
  label, control, `pt-help`, `pt-error`; the hint is shown above the control and the error below it, as in 8.24.
  `pt-field--inline` is a two-column grid: the checkbox or radio, then its label with `pt-help` and `pt-error`
  under the label. `input.pt-mono` (hardware IDs) is mono at 14px. `abbr[title]` has a dotted underline.
- **Tables.** Edge shadows appear only where columns are scrolled out of view. A table narrower than 40rem without
  `--stack` keeps its columns: cells stop wrapping, the row title (`tbody th`) keeps 12.5rem, and the wrapper
  scrolls, with a visible caption staying in view above the moving columns. A table of nine or more columns (the
  admin lists) is set compact (13px, 7px between cell and rule, column names that wrap) so it fits the main column
  from a 1366px laptop up, and the row title never drops below 11rem. Give every `td` a
  `data-label` and add `pt-table--stack` to get one card per row instead. `pre` in a cell (audit details) wraps at
  30rem.
- **Files.** Each `pt-files` item without an icon gets a document glyph; the size, or a small delete `form.pt-actions`,
  sits at the end of the row, also inside a card body.
- **Flashes and empty states.** `pt-alert` flows as a block (text and links on one line). `pt-empty` puts its words on
  rows of their own and its buttons side by side.

### 8.28 Support assistant: launcher, panel, bottom sheet (`chat.js`)

```html
<div class="c-chat" data-chat>
  <a class="c-chat-fab is-tucked" href="/contact/" data-chat-open data-chat-fab>   <!-- chat.js makes it the panel's button -->
    <i class="c-lamp" aria-hidden="true"></i><span>Ask<span class="c-chat-fab-long"> the assistant</span></span></a>
  <noscript><a class="c-chat-fab" href="/contact/"><span>Get in touch</span></a></noscript>   <!-- not on /contact/ itself -->
  <section class="c-chat-panel" id="chat-panel" data-chat-panel hidden aria-labelledby="chat-title">
    <header class="c-chat-head"><h2 id="chat-title">Show assistant</h2><span class="c-chat-status"><i class="c-lamp c-lamp--go"></i>Online</span>
      <span class="c-chat-tools">
        <button class="c-btn c-btn--ghost c-btn--icon c-btn--sm" type="button" data-chat-reset aria-label="Start a new conversation">…i-reset…</button>
        <button class="c-btn c-btn--ghost c-btn--icon c-btn--sm" type="button" data-chat-close aria-label="Close the assistant">…i-close…</button></span></header>
    <p class="c-chat-scope">…i-info…<span><b>I can help with Patterns, StageWatch, AGMessage and PlayMap.</b> I answer from the support pages; nothing is sent for you without your approval.</span></p>
    <div class="c-chat-log" data-chat-log role="log" aria-live="polite" aria-label="Conversation"></div>
    <div class="c-chat-suggest"><button class="c-chat-chip" type="button" data-chat-suggest>Which licence do I need?</button>…</div>
    <form class="c-chat-form" data-chat-form action="/contact/">
      <label class="u-visually-hidden" for="chat-input">Ask about your show</label>
      <textarea class="c-chat-input" id="chat-input" data-chat-input rows="1" maxlength="1200" placeholder="Ask about your show"></textarea>
      <button class="c-chat-send" type="submit" aria-label="Send">…i-send…</button>
      <p class="c-chat-count" data-chat-count></p>
    </form>
    <p class="c-chat-foot">Answers can be wrong; check them before doors. Don't paste licence keys or passwords.</p>
    <p class="u-visually-hidden" data-chat-status role="status"></p>
  </section>
</div>
```
- The status reads `c-lamp--go` "Online" while the assistant is configured and `c-lamp--off` "Offline" otherwise,
  with the panel's unavailable text and contact links in place of the suggestions. It is a site-wide fact
  (`ChatController::online()`), never a per-visitor one, so cached pages stay the same for everyone.
- `chat.js` writes the messages: `.c-chat-msg` + `--user` / `--assistant` / `--error`, `.c-chat-msg-body`,
  `.c-chat-typing`, `.c-chat-handoff` (Contact us, Open a support ticket) and `.c-chat-feedback`. The visible
  "You" and "Assistant" labels come from CSS, with empty alternative text so they are not read twice. Source
  links in an answer can use `.c-chat-src`.
- The support page uses an inline panel: add `c-chat--inline` and `data-chat-inline` to the root and render the
  panel without `hidden`. A page should have one `[data-chat]` root, because `chat.js` binds every
  `[data-chat-open]` on the page to each root.
- The floating panel sits bottom right. Below 40rem it is a bottom sheet and the launcher hides while it is open.
  `site.js` tucks the launcher away while any `[data-chat-tuck]`, `[data-chat-inline]` or other `[data-chat-open]`
  element is on screen, never while its panel is open or it has focus.
- **The launcher is a link drawn as a button.** It has no underline in any state and the two-tone ring on focus
  (8.5). The server renders it `is-tucked` (slid down, transparent, hidden), and `site.js` untucks it once it has
  measured the page, so a page that opens with a zone in view never flashes it. Without scripting the tucked
  launcher is not shown at all (`@media (scripting: none)` takes it out of the layout) and the `<noscript>` link
  stands in: on every page but the contact page itself there is exactly one launcher, a visible link to the contact
  page. The tuck is not scoped to a scripting query, so a browser that cannot evaluate one still gets one launcher.
- The support band's launcher card is `.c-launcher` (`.c-launcher-icon`, `b`, `small`) with `data-chat-open`.
  Product help links are `.c-support-links`: the list clips its edges and each cell paints over its neighbours, so a
  link's focus ring is drawn inside it (`outline-offset: -3px`) and the first link's ring is never cut off. Pages
  need no override of their own.

### 8.29 Footer

```html
<footer class="c-footer">
  <span class="c-bars" aria-hidden="true"></span>
  <div class="l-wrap">
    <div class="c-footer-grid">
      <div class="c-footer-brand"><a class="c-brand" href="/">…mark + name…</a><p>Made in the UK by Ben Rohleder, …</p></div>
      <nav aria-labelledby="ft-products"><h2 class="c-footer-title" id="ft-products">Products</h2>
        <ul class="c-footer-links" role="list"><li data-product="patterns"><a href="/patterns/"><i class="c-swatch"></i>Patterns</a></li>…</ul></nav>
      …Ecosystem, Support, Company (content/site.php footer)…
    </div>
    <div class="c-footer-legal"><p>© 2026 Ben Rohleder</p><ul role="list"><li><a href="/legal/privacy/">Privacy</a></li>…</ul>
      <p class="c-footer-tm">NDI® is a registered trademark of Vizrt NDI AB. …</p></div>
  </div>
</footer>
```

### 8.30 Icons

The sprite is `public/assets/img/icons.svg`, 96 symbols in five groups:

| Group | Icons |
|---|---|
| Interface | check, dash, close, menu, chevron, arrow, external, sun, moon, system, chat, send, info, alert, ok, error, bus, deck, approve, search, doc, download, copy, ticket, key, user, plus, minus, filter, attach, play, pause, reset, pattern, clock, people, projector, mail, bug, idea, question, lock |
| Pictures, screens and media | monitor, laptop, phone, grid, layout, layers, blend, mesh, cube, scan, camera, video, film, slides, speaker, mic |
| Signals, networks and control | broadcast, sync, link, plug, network, wifi, offline, globe, radar, pulse, cue (a cue-light box), timer, keyboard, settings (sliders), chip, usb, zap |
| People, places and messages | users (as people), message, building, home, calendar, book |
| Files, actions and states | file, list, chart, receipt, tag, upload, edit, trash, eye, logout, flag, star, shield (with a tick), sparkles, bulb (as idea) |

They sit on a 24px grid with a 1.5px stroke, square caps, mitred joins and no fills, like front-panel silkscreen.
Use `.c-icon` (20px), `--sm` (16px) or `--lg` (28px); the colour is `currentColor`. An icon goes with words; an
icon-only button has an `aria-label`. Tallies, lamps, registration crosses, the FAQ crosshair, the vote triangle and
the brand mark are CSS, not icons. Add new icons to the same grid and stroke, in their group, and check them at 16px
beside their neighbours. Every name used in `content/` (`'icon' => '…'`) and in templates (`icon('…')`) must be a
symbol here.

### 8.31 Long-form text, error pages, print

```html
<div class="c-prose">…rendered Markdown…</div>          <!-- legal, support articles, release notes; portal: pt-prose -->
<section class="c-error">                              <!-- templates/errors/error.php already uses these -->
  <p class="c-error-code" aria-hidden="true">404</p>   <!-- drawn as a monitor with no signal -->
  <h1>This page is off air</h1><p>…</p><p class="c-error-links"><a href="/">Home</a> · …</p>
</section>
```
Print hides the header, assistant and controls, switches to the light tokens, keeps monitors in colour and adds
link addresses in prose.

### 8.32 Utilities

`u-visually-hidden`, `u-muted`, `u-small`, `u-mono`, `u-num` (tabular figures), `u-ink` (the product's text
colour), `u-nowrap`, `u-center`, `u-mt-0|2|4|6|8`, `u-hide-sm` and `u-show-sm` (40rem), and `u-theme-light` /
`u-theme-dark` (switch a container's theme).

---

### 8.33 Brand mark, favicons and app icons

The brand mark is the seven 75% bars over a dark strip holding one short light segment (`.c-brand-mark`: 28 × 22,
bars 16 tall, a 1-unit gap, the segment 5 tall and 9 wide). `tools/og/brand.py` draws it on whole pixels at every
size, so the bars stay crisp; only tile corners are smoothed.

| File | Size | Shape |
|---|---|---|
| `public/favicon.svg` | 32-unit grid | dark rounded tile (`#090C10`, radius 6); every edge lands on a whole pixel at 16 and 32px |
| `public/favicon.ico` | 16, 32, 48 | the same tile, laid out per size (the light segment is two bars wide) |
| `brand/apple-touch-icon.png` | 180 | full bleed (iOS rounds it), mark at 62% |
| `brand/icon-192.png`, `brand/icon-512.png` | 192, 512 | rounded tiles for the web manifest's "any" purpose |
| `brand/icon-maskable-512.png` | 512 | full bleed, the whole mark inside the 80% safe circle |
| `brand/logo-512.png` | 512 | full bleed, for the Organization JSON-LD (`Schema.php`) |

The mark is never recoloured or redrawn with other bars, and the tile stays dark in both themes (principle 3).

### 8.34 Open Graph cards

`public/assets/img/og/*.png` are 1200 × 630, built by `node tools/og/build.mjs` from `tools/og/template.html` with
this stylesheet, the fonts and the sprite. Each card is the dark room with the 48px grid, the brand mark and
wordmark, the domain, the SMPTE bar strip along the bottom, and a headline from the page's H1 (or its title) beside
one drawn visual. Product cards add the product's 8px top bar, its swatch, name and filled role chip, and a faint
followspot in its colour; PlayMap's card says "Early access".

| Card | Pages | Visual |
|---|---|---|
| `home`, `default` | home; any page without its own | the multiview of the four drawn screens; the four apps as a router panel |
| `ecosystem`, `integrations`, `ai` | the ecosystem pages | the apps on Show Bus; integration chips; the proposal reaching only the amber gate |
| `patterns`, `stagewatch`, `agmessage`, `playmap` | each product's overview, features and licensing | the product's drawn screen on a monitor with its UMD |
| `stagewatch-licensing` | StageWatch pricing | Standard, Pro and Production from `content/licensing/stagewatch.php`, as the pricing page shows them: Pro's badge, and a launch price as the figure with the list price struck through under an outlined "Launch price" flag |
| `licensing`, `workflows`, `downloads`, `support`, `contact`, `about` | the hubs and pages of those names | a licence sheet; the six workflows as a cue list, each with its apps' swatches and a legend naming them; what the downloads hold; the assistant; the contact topics; the four habits the apps share (about's principles, so the card adds to its headline rather than repeating it) |

Headlines are 66px (80px for one-word titles), stepping down to 50px to fit three lines (four for the home card's
long headline, so it stays large beside the multiview); hyphenated words never
break, and the build warns when a headline needs a fourth line (shorten it: Support's card uses its H1, "Support",
with the page's description as the line, rather than its 55-character title). Text is sized to stay legible at a 400px thumbnail. Words and prices come from `content/`; the build fails if
`content/pages.php` names an `og` image with no card. Cards are palette PNGs when that is visually identical, and stay
under 150 KB (25–90 KB today).

### 8.35 Cookie choice (`templates/partials/consent.php`, `site.js` initConsent)

```html
<section class="c-consent" data-consent data-ga="G-…" aria-labelledby="consent-title" hidden>   <!-- after the skip link -->
  <div class="c-consent-card">
    <div class="c-consent-text"><h2 class="c-consent-title" id="consent-title">Can we count your visit?</h2><p>… <a href="/legal/cookies/">Read the cookie notice</a>.</p></div>
    <div class="c-consent-actions">
      <button type="button" class="c-btn c-btn--secondary" data-consent-choice="granted">Accept statistics cookies</button>
      <button type="button" class="c-btn c-btn--secondary" data-consent-choice="denied">Reject statistics cookies</button></div>
  </div>
</section>
<button type="button" class="c-footer-consent" data-consent-open hidden>Cookie settings</button>   <!-- footer; also on the cookie notice -->
```
- **Shown only on pages that carry the Google tag**, and only while the visitor has given no answer in the last
  year (`psc-consent` in localStorage, `granted:<ms>` or `denied:<ms>`). It sits first in the DOM after the skip link,
  so the keyboard reaches it early, but it is fixed over the foot of the page, never a wall. It is not modal and
  takes no focus on load.
- **Accept and reject are equals:** the same style, size, weight and wording pattern. Saying no changes nothing
  else on the site.
- **Basic Consent Mode:** the tag's inline part denies every consent type and fetches gtag.js only after
  acceptance, so nothing reaches Google and no `_ga` cookie exists before then. Rejecting after accepting withdraws
  consent and deletes the `_ga` cookies.
- **Reopening:** any `[data-consent-open]` reopens the card with focus on the current answer, and Escape closes a
  reopened card. The openers stay hidden without scripting, because the choice needs scripting, and so does the tag.

## 9. Imagery: real screenshots

- Capture each app in its dark theme at 100% scale, cropped to the window content with no OS chrome. Blur or
  replace real client names.
- Aim for **16:10** (1920×1200 or 2560×1600). The frame covers, so other ratios lose a little at the edges. For a
  very different ratio (such as a 3.2:1 blend pattern) put it in a `.c-bento-media` or a plain figure instead.
- Ship WebP (AVIF where it helps) with `srcset` at 800w and 1600w, always with `width` and `height` (no layout
  shift), and `loading="lazy" decoding="async"` below the fold. The home page LCP stays the h1.
- Always present a capture inside `.c-shot-frame` (the dark bezel stays dark in the light theme) with a
  `figcaption` that says what the screen shows, in the product's words. `alt` describes what matters on the screen,
  not "screenshot of".
- No stock photos and no external images. Drawn visuals (multiview tiles, meshes, monitors) are inline SVG or CSS,
  and their text is never smaller than 12px on screen.

---

## 10. Accessibility rules

- One `h1` per page; landmarks `header`, `nav[aria-label]`, `main#main`, `footer`; a skip link first.
- Every interactive element has a visible focus state: the two-tone amber ring (or `--text` inside amber). Inputs
  show an amber border and halo.
- Targets are at least 36px tall (small buttons), 44px for chips and radio cards, and 48px for primary actions.
- Control boundaries use `--field` (3:1 or better in both themes); every text pair passes 4.5:1 (section 4.4).
- Colour is never the only cue (principle 2). Tables have captions and `scope`. Check and dash icons carry hidden
  "Included" or "Not included".
- No character-key shortcuts. Escape closes the drawer, the mega-menu and the assistant.
- Motion is opt-in (section 7), and anything that moves by itself has a pause control.
- Windows High Contrast (`forced-colors`): selected segments, tabs, pressed votes, chosen radio cards, the
  recommended tier and the STANDBY row get an outline; swatches, lamps and bars keep their colours.
- The kitchen sink has no horizontal scroll at 320, 360, 390, 768, 1024, 1152, 1280, 1366, 1440 or 1920px, with or
  without the `overflow-x: clip` safety net on `body`.

---

## 11. Do and don't

| Do | Don't |
|---|---|
| Put `data-product` on `<main>` of a product's pages and let `--accent` do the work | Hard-code a product hex in a template, or colour one product's page with another's colour |
| Use green, red and amber only for their states | Use a product colour for a status badge or an info message |
| Use product yellow as a fill in the light theme | Set yellow text on white |
| Keep monitors dark in both themes (`.c-screen`) | Recolour a screenshot frame for the light theme |
| Use JetBrains Mono for timecode, IDs, versions and table values | Use mono for labels, eyebrows or prose |
| Write sentence case; capitals only for PGM, PVW, TAKE, GO, STANDBY, ON AIR | Use all-caps letter-spaced eyebrows |
| Use one bold visual per page | Add glows, gradients or animation for decoration |
| Use TAKE and GO keys only for a real take or GO | Use them as ordinary call-to-action buttons |
| Give every stacked-table cell a `data-label` | Hide table headers without a replacement |
| Show the AI behind the amber "A person approves" gate | Draw an AI wired straight to Show Bus or a screen |
| Leave the "Optional" marker and the hint above a field | Put the hint under the field, or show "Looks good" messages |

---

## 12. Which page uses what

| Page | Components |
|---|---|
| Home | `c-hero` + mixer, `c-legend`, `c-multiview`, `c-flow`, `c-ladder` (one workflow), `c-faq`, the support band (`c-launcher`, `c-support-links`), `c-footer` |
| Ecosystem, integrations, AI | `c-hero--page`, `c-stops` tagline, `c-flow`, `c-features`, `c-chips`, `c-callout--info`, `c-cue` (the approval steps) |
| Product overview (×4) | `c-hero--product` (with `c-specs`, the product visual, `c-shots`, `c-subnav`), `c-bento`, `c-features`, `c-chips`, `c-ladder--static` (getting started), `c-faq` |
| Product features (×4) | `c-hero--page` + `c-subnav`, `c-features` per feature group, `c-shot` |
| Product licensing (×4) and Licensing hub | `c-tiers`, `c-compare`, `c-pass`, `c-specs--rows` (policies), `c-ladder--static` (how to buy), `c-faq`, `c-fineprint` |
| Workflows hub and six workflows | `c-hero--page`, `c-card[data-product]` grid (hub), `c-ladder` with T-minus, `c-specs--rows` (you need), `c-callout--success` (outcome), `c-faq` |
| Downloads | `c-hero--page`, `c-card` per product, `c-tabs` (latest, earlier, checksums), `c-codeline` + copy, `c-table--compact` |
| Support hub and four product help pages | `c-hero--page`, inline assistant (`c-chat--inline`), `c-support-links`, `c-faq`, `c-prose` |
| FAQ | `c-hero--page`, `c-tabs` (groups) or section heads, `c-faq` |
| Contact | `c-hero--page`, `c-callout--info` (show in 48 hours), `c-form--panel`, `c-kv` |
| About, legal | `c-hero--page`, `c-prose` |
| Portal: dashboard, downloads, licences, tickets, ideas board, account | `pt-shell`, `pt-header`, `pt-stats`, `pt-grid` + `pt-card--link`, `pt-table`, `pt-badge`, `pt-vote`, `pt-thread`, `pt-files`, `pt-kv`, `pt-alert`, `pt-empty`, `pt-pager` |
| Admin tables | `pt-table` (+ `c-table--zebra c-table--compact`, `aria-sort` + `c-sort`), `pt-search`, `pt-tabs`, `pt-badge`, `c-table--stack` with `data-label` for phones |
| Errors | `c-error` |

---

## 13. Browser support

The baseline is evergreen browsers from 2024 on: Chrome and Edge 123, Firefox 120, Safari 17.5. These have CSS
nesting, `light-dark()`, `:has()`, container queries, cascade layers and `color-mix()`. Older browsers that lack
`light-dark()` get the dark theme. View transitions, scroll-driven animations, `interpolate-size`,
`field-sizing` and `@media (scripting)` are enhancements; without them nothing breaks.

## 14. Facts still to confirm

The kitchen sink uses StageWatch's real tiers from `content/licensing/stagewatch.php`. The ticket names, people and
numbers in the portal and form samples are invented. The tagline "Every pixel. Every second. Every person.
Every surface." and the 404 title "This page is off air" are suggestions for the content owner.
