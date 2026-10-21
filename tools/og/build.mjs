// Builds the Open Graph cards (1200 x 630 PNG) in public/assets/img/og/ from tools/og/template.html.
//
//   cd tools/e2e && npm ci          # once: Playwright and its Chromium
//   node tools/og/build.mjs         # every card; or name some: node tools/og/build.mjs home stagewatch
//
// A small static server (any free port on 127.0.0.1) serves public/ and this folder, so the cards use the site's own
// CSS, fonts and icon sprite. The words come from content/ (read through PHP), and every 'og' image named in
// content/pages.php must have a card here: a new one fails the build until it is added. Cards are optimised by
// tools/og/optimise.py (Pillow) and should stay under 150 KB.
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');
const require = createRequire(path.join(root, 'tools/e2e/package.json'));
const { chromium } = require('@playwright/test');
const outDir = path.join(root, 'public/assets/img/og');

// ---------------------------------------------------------------------------------------------- the words
const php = `
$root = $argv[1];
$pages = require $root . '/content/pages.php';
$og = [];
foreach ($pages as $path => $p) { if (!empty($p['og'])) { $og[basename($p['og'], '.png')][] = $path; } }
$products = [];
foreach (['patterns', 'stagewatch', 'agmessage', 'playmap'] as $k) {
  $c = require $root . '/content/products/' . $k . '.php';
  $products[$k] = ['name' => $c['name'], 'role' => $c['role'], 'status' => $c['status'] ?? 'available'];
}
$site = require $root . '/content/site.php';
$lines = [];
foreach ($site['nav'] as $item) { foreach (($item['children'] ?? []) as $ch) { $lines[$ch['product']] = $ch['line'] ?? ''; } }
$sw = require $root . '/content/licensing/stagewatch.php';
$tiers = [];
foreach ($sw['tiers'] as $t) { $tiers[$t['id']] = ['name' => $t['name'], 'price' => $t['price_label'], 'launch' => isset($t['launch_price']) && is_numeric($t['launch_price']) ? (string) $t['launch_price'] : null, 'machines' => $t['machines'], 'badge' => $t['badge'] ?? '']; }
$wf = require $root . '/content/pages.php';
$workflows = [];
foreach ($wf as $path => $p) { if (($p['type'] ?? '') === 'workflow') { $workflows[] = ['crumb' => $p['crumb'], 'key' => $p['workflow']]; } }
$flows = require $root . '/content/workflows.php';
$wfProducts = [];
foreach ($flows as $key => $f) { $wfProducts[$f['slug'] ?? $key] = $f['products'] ?? []; }
echo json_encode(['og' => $og, 'products' => $products, 'lines' => $lines, 'tiers' => $tiers, 'workflows' => $workflows, 'wfProducts' => $wfProducts], JSON_UNESCAPED_UNICODE);
`;
const data = JSON.parse(execFileSync('php', ['-r', php, root], { encoding: 'utf8' }));
const P = data.products;
const order = ['patterns', 'stagewatch', 'agmessage', 'playmap'];
const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const icon = (n) => `<svg class="c-icon" aria-hidden="true"><use href="/assets/img/icons.svg#i-${n}"/></svg>`;

// ---------------------------------------------------------------------------------------------- the visuals
const V = {
  monitor: (k) => `<div class="c-reg"><div class="og-monitor" data-screen="${k}"></div>
    <div class="og-umd" data-product="${k}"><i></i><b>${esc(P[k].name)}</b><em>${esc(P[k].role)}</em></div></div>`,
  multiview: () => `<div class="c-reg"><div class="og-mv">${order.map((k) => `<div class="og-mv-tile" data-product="${k}"><div data-screen="${k}"></div>
    <div class="og-mv-umd"><i></i><b>${esc(P[k].name)}</b><em>${esc(P[k].role)}</em></div></div>`).join('')}</div></div>`,
  apps: () => `<div class="og-rows">${order.map((k) => `<div class="og-row og-row--app" data-product="${k}"><i></i>
    <span class="og-row-main"><span class="og-row-top"><b>${esc(P[k].name)}</b><span class="og-role">${esc(P[k].role)}</span></span>
    <small>${esc(data.lines[k] ?? '')}</small></span></div>`).join('')}</div>`,
  bus: () => `<div class="og-bus"><div class="og-bus-apps">${order.map((k) => `<div class="og-bus-app" data-product="${k}"><b>${esc(P[k].name)}</b><em>${esc(P[k].role)}</em></div>`).join('')}</div>
    <div class="og-bus-line"><b>Show Bus</b></div></div><p class="og-note">On the venue network. No internet, no server, no account.</p>`,
  chips: () => `<div class="og-chips">${[
    ['BFC', 'Bitfocus Companion'], ['NDI', 'NDI®'], ['OSC', 'OSC'], ['MIDI', 'MIDI controllers'],
    ['DMX', 'Art-Net and sACN'], ['LTC', 'Timecode'], ['PPT', 'PowerPoint'], ['MCP', 'AI tools over MCP'],
  ].map(([m, n]) => `<div class="og-chip"><span>${m}</span>${esc(n)}</div>`).join('')}</div>`,
  approval: () => `<div class="og-flow">
    <div class="og-node og-node--ai"><b>AI tools and assistants</b><small>Draft, suggest and explain</small></div>
    <div class="og-wire">PROPOSAL</div>
    <div class="og-node og-node--gate"><b>${icon('approve')}A person approves</b><small>Approve, edit or decline</small>
      <em><i class="c-lamp c-lamp--standby"></i>Standing by for you</em></div>
    <div class="og-wire og-wire--plain"></div>
    <div class="og-node"><b>The app’s own checks</b><small>Still apply after approval</small></div></div>`,
  licence: () => `<div class="og-sheet"><div class="og-sheet-head">${icon('key')}Your licence</div><dl>
    <dt>Price</dt><dd>One-off</dd><dt>Tied to</dt><dd>Your show PC</dd><dt>Activated</dt><dd>Offline, with a licence file</dd>
    <dt>Also</dt><dd>Trials, nonprofit and rental pricing</dd></dl></div>`,
  // The tiers as the pricing page shows them: a tier's launch_price is the figure, with the list price struck
  // through and an outlined "Launch price" flag; the recommended tier carries its badge.
  tiers: () => {
    const t = data.tiers;
    const tier = (id) => {
      const x = t[id];
      const launch = x.launch;
      const price = launch ? `£${launch}` : x.price;
      const flag = x.badge ? `<span class="og-flagline">${esc(x.badge)}</span>` : (launch ? '<span class="og-flagline og-flagline--outline">Launch price</span>' : '');
      const unit = x.machines === 2 ? 'One-off, main and backup PC' : 'One-off, one PC';
      return `<div class="og-tier${x.badge ? ' is-rec' : ''}">${flag}<h3>${esc(x.name)}</h3>
        <p>${esc(price)}${launch ? `<s>${esc(x.price)}</s>` : ''}</p><small>${unit}</small></div>`;
    };
    return `<div class="og-tiers">${tier('standard')}${tier('pro')}${tier('production')}</div>`;
  },
  // Each workflow's apps as swatches, with a legend naming every swatch (a swatch never stands without its name).
  cues: () => `<div class="og-cues">${data.workflows.map((w, i) => `<div class="og-cue"><span>Q${i + 1}</span><b>${esc(w.crumb)}</b>
    <i>${(data.wfProducts[w.key] ?? []).map((k) => `<b class="c-swatch" data-product="${k}"></b>`).join('')}</i></div>`).join('')}</div>
    <p class="og-legend">${order.map((k) => `<span data-product="${k}"><i class="c-swatch"></i>${esc(P[k].name)}</span>`).join('')}</p>`,
  // About: the four habits the apps share (the about page's principles), rather than repeating the headline.
  habits: () => `<div class="og-sheet"><div class="og-sheet-head">What the four apps share</div><ul class="og-list">${[
    ['bus', 'Offline on the venue network'], ['reset', 'Built to keep running'], ['approve', 'A person decides'], ['check', 'Confirmed, not hopeful'],
  ].map(([i, t]) => `<li>${icon(i)}${t}</li>`).join('')}</ul></div>`,
  downloads: () => `<div class="og-rows">${[
    ['download', 'The latest Windows builds'], ['deck', 'Bitfocus Companion modules'], ['doc', 'Release notes'],
    ['clock', 'StageWatch’s 14-day trial'], ['user', 'Every build for signed-in clients'],
  ].map(([i, t]) => `<div class="og-row og-row--icon">${icon(i)}<b>${t}</b></div>`).join('')}</div>`,
  assistant: () => `<div class="og-chat"><div class="og-chat-head">Show assistant<em><i class="c-lamp c-lamp--go"></i>Online</em></div>
    <p class="og-chat-scope">${icon('info')}<span><b>I can help with Patterns, StageWatch, AGMessage and PlayMap</b>: what they do, licensing, downloads, setup and support.</span></p>
    <div class="og-chat-chips"><span>Which licence do I need?</span><span>Which app is for an AGM?</span><span>How do I report a bug?</span></div></div>`,
  topics: () => `<div class="og-radios">${['Buying a licence or pricing', 'Help with the software', 'A licence I already have', 'Rental, reseller or partnership']
    .map((t, i) => `<div class="og-radio${i === 0 ? ' is-on' : ''}">${t}</div>`).join('')}</div>`,
};

// ---------------------------------------------------------------------------------------------- the cards
// title: the page's H1 (docs: the SEO brief §2) or its title tag, in sentence case; sub: one line from content/.
const cards = {
  default: { title: 'Show control software for live events', sub: 'Test patterns, stage timers, back-room messaging and projection mapping.', visual: V.apps() },
  // The home headline is long and sits beside the widest visual: four lines at a larger size read better than three
  // squeezed to the floor.
  home: { title: 'Show control software for the people who run the room', sub: 'Four Windows apps, one show network. Made in the UK.', visual: V.multiview(), lines: 4 },
  ecosystem: { eyebrow: 'Ecosystem', title: 'Four apps. One show network.', sub: 'Pictures, time, people and media, working together on the venue LAN.', visual: V.bus() },
  integrations: { eyebrow: 'Integrations', title: 'Companion, Stream Deck, NDI, OSC and MIDI', sub: 'The apps work with the kit already in your rack.', visual: V.chips() },
  ai: { eyebrow: 'AI with approval', title: 'AI in show control, with people in charge', sub: '', visual: V.approval() },
  licensing: { eyebrow: 'Licensing', title: 'How licensing works', sub: 'One-off licences tied to your show PC and activated offline with a licence file.', visual: V.licence() },
  patterns: { product: 'patterns', title: 'Test patterns and show display for every screen in the room', visual: V.monitor('patterns') },
  stagewatch: { product: 'stagewatch', title: 'The stage timer and show-calling desk for live events', visual: V.monitor('stagewatch') },
  'stagewatch-licensing': { product: 'stagewatch', title: 'Pricing: one-off licences that work offline', sub: 'Prices in pounds sterling, with a free 14-day trial.', visual: V.tiers(), wide: true },
  agmessage: { product: 'agmessage', title: 'Back-room command for AGMs and board meetings', visual: V.monitor('agmessage') },
  playmap: { product: 'playmap', title: 'Play it, map it, run the show', sub: 'Media playback and 3D projection mapping in one Windows app.', visual: V.monitor('playmap') },
  workflows: { eyebrow: 'From load-in to curtain', title: 'Show workflows', sub: 'Step-by-step guides for LED walls, blends, conferences, AGMs, mapping and Stream Deck control.', visual: V.cues(), size: 80 },
  downloads: { title: 'Downloads', sub: 'Windows builds, Bitfocus Companion modules and release notes.', visual: V.downloads(), size: 80 },
  support: { title: 'Support', sub: 'Getting-started guides, troubleshooting, licence help and the support assistant.', visual: V.assistant(), size: 80 },
  contact: { eyebrow: 'Contact', title: 'Get in touch', sub: 'Licences, an AGM, rental packs or a show next week. Based in the UK.', visual: V.topics(), size: 80 },
  about: { eyebrow: 'About', title: 'Made in the UK by Ben Rohleder', sub: 'Built at real show desks for the crews who run conferences, AGMs and live events.', visual: V.habits() },
};

const missing = Object.keys(data.og).filter((k) => !cards[k]);
if (missing.length) {
  console.error(`No card for ${missing.map((k) => `${k}.png (${data.og[k].join(', ')})`).join('; ')}. Add it to tools/og/build.mjs.`);
  process.exit(1);
}
const wanted = process.argv.slice(2);
const todo = Object.keys(cards).filter((k) => !wanted.length || wanted.includes(k));

// ---------------------------------------------------------------------------------------------- serve and shoot
const types = { '.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.svg': 'image/svg+xml', '.woff2': 'font/woff2', '.png': 'image/png' };
const server = http.createServer((req, res) => {
  const url = decodeURIComponent(new URL(req.url, 'http://x').pathname);
  const file = url.startsWith('/og/') ? path.join(here, url.slice(4)) : path.join(root, 'public', url);
  if (!file.startsWith(root) || !fs.existsSync(file) || !fs.statSync(file).isFile()) { res.writeHead(404).end(); return; }
  res.writeHead(200, { 'Content-Type': types[path.extname(file)] ?? 'application/octet-stream' });
  fs.createReadStream(file).pipe(res);
});
await new Promise((r) => server.listen(0, '127.0.0.1', r));
const base = `http://127.0.0.1:${server.address().port}`;

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1200, height: 630 }, deviceScaleFactor: 1, colorScheme: 'dark' });
page.on('requestfailed', (r) => console.error('failed:', r.url()));
fs.mkdirSync(outDir, { recursive: true });
const written = [];
for (const key of todo) {
  const c = cards[key];
  await page.goto(`${base}/og/template.html`, { waitUntil: 'load' });
  await page.evaluate(({ c, p }) => {
    const og = document.querySelector('[data-og]');
    const slot = (n) => og.querySelector(`[data-slot="${n}"]`);
    if (c.product) {
      og.dataset.product = c.product;
      slot('tag').hidden = false;
      slot('name').textContent = p[c.product].name;
      slot('role').textContent = p[c.product].role;
      if (p[c.product].status === 'early-access') { slot('flag').hidden = false; slot('flag').textContent = 'Early access'; }
    } else if (c.eyebrow) {
      slot('eyebrow').hidden = false;
      slot('eyebrow').textContent = c.eyebrow;
    }
    if (c.wide) og.classList.add('og--wide');
    // Hyphenated words never break at the hyphen ("show-calling", "one-off").
    const keep = (t) => t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/(\S+-\S+)/g, '<span class="og-nb">$1</span>');
    slot('title').innerHTML = keep(c.title);
    slot('sub').innerHTML = keep(c.sub ?? '');
    slot('visual').innerHTML = c.visual;
    for (const el of og.querySelectorAll('[data-screen]')) {
      el.innerHTML = document.getElementById(`s-${el.dataset.screen}`).innerHTML;
    }
  }, { c, p: P });
  await page.evaluate(() => document.fonts.ready);
  // The headline steps down (to 50 px at the least) until it fits beside the visual in three lines (or the card's own).
  const fits = await page.evaluate(([start, maxLines]) => {
    const title = document.querySelector('.og-title');
    const text = document.querySelector('.og-text');
    for (let size = start; size >= 50; size -= 2) {
      title.style.fontSize = `${size}px`;
      const lines = Math.round(title.getBoundingClientRect().height / (size * 1.02));
      if (lines <= maxLines && text.getBoundingClientRect().height <= 430) return true;
    }
    return false;
  }, [c.size ?? 66, c.lines ?? 3]);
  if (!fits) console.warn(`${key}: the headline needs more than ${c.lines ?? 3} lines at 50 px; shorten it or its line.`);
  await page.waitForLoadState('networkidle');
  const file = path.join(outDir, `${key}.png`);
  await page.locator('[data-og]').screenshot({ path: file });
  written.push(file);
}
await browser.close();
server.close();

execFileSync('python3', ['-I', path.join(here, 'optimise.py'), ...written], { stdio: 'inherit' });
