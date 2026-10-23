// Which of the four test card edges each take of the artwork leaves clear (docs/DESIGN.md 8.36).
//
//   cd tools/e2e && npm ci          # once: Playwright and its Chromium
//   node tools/news-art/edges.mjs
//
// For every category, composition and take, on the card (420 px wide, 16:9) and on the band (760 px, 16:5), it shows
// the take with the site's own stylesheets and asks the browser, pixel by pixel, whether a path of the picture paints
// over each region an edge puts on the screen: the bars along the top (f0) with its staircase, the castellations (f1)
// with its multiburst, the bars down the side (f2) and the bars along the foot (f3). An edge is allowed only where the
// picture leaves its strip and its furniture clear, so no figure stands on a strip of bars and no fragment of the
// furniture shows beside a motif. The safe-area corners are measured and reported, not held. It writes edges.json,
// with each take's signature, which build.mjs turns into the takes' edge maps.
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { sig } from './lib.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');
const require = createRequire(path.join(root, 'tools/e2e/package.json'));
const { chromium } = require('@playwright/test');
// The share of a region a picture may paint before its edge is refused.
const LIMIT = { strip: 0.004, furniture: 0.02 };

const table = JSON.parse(execFileSync('php', ['-r', 'echo json_encode(require $argv[1]);', path.join(root, 'app/src/News/art-data.php')], { encoding: 'utf8', maxBuffer: 1 << 26 }));
// A take wrapped as Art::tile() wraps it, with the edge and the palette fixed (the edge's own furniture is read back).
const svg = (cls, w) => `<svg class="${cls}" viewBox="0 0 ${w} 180" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false">`;
const wrap = (key, v, k, wide) => `<span class="news-art news-art--p0 news-art--f0${wide ? ' news-art--wide' : ''}">${wide ? svg('news-art-svg news-art-band', 576) + table.band[key][v][k] + '</svg>' : ''}${svg(wide ? 'news-art-svg news-art-tile' : 'news-art-svg', 320)}${table.card[key][v][k]}</svg></span>`;

// The site's stylesheets on a blank page; the page lets the picture take hits so the browser can say which pixels it
// paints (the live tile never does).
const blank = `<!doctype html><html lang="en"><head><meta charset="utf-8"><link rel="stylesheet" href="/assets/css/site.css"><link rel="stylesheet" href="/assets/css/pages/news.css">
<style>body{margin:0} .m{width:420px} .m.w{width:760px} .news-art, .news-art *{pointer-events:auto !important; transition:none !important}</style></head><body><div id="m"></div></body></html>`;
const server = http.createServer((req, res) => {
  const url = decodeURIComponent(new URL(req.url, 'http://x').pathname);
  if (url === '/') { res.writeHead(200, { 'Content-Type': 'text/html' }).end(blank); return; }
  const file = path.join(root, 'public', url);
  if (!file.startsWith(path.join(root, 'public')) || !fs.existsSync(file) || !fs.statSync(file).isFile()) { res.writeHead(404).end(); return; }
  res.writeHead(200, { 'Content-Type': { '.css': 'text/css', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' }[path.extname(file)] ?? 'application/octet-stream' });
  fs.createReadStream(file).pipe(res);
});
await new Promise((r) => server.listen(0, '127.0.0.1', r));
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1600, height: 900 } });
await page.goto(`http://127.0.0.1:${server.address().port}/`, { waitUntil: 'networkidle' });

const measure = (html, wide) => page.evaluate(([h, w]) => {
  const m = document.getElementById('m'); m.className = w ? 'm w' : 'm'; m.innerHTML = h;
  const art = m.querySelector('.news-art'), box = art.getBoundingClientRect(), W = box.width, H = box.height;
  const after = (f) => { art.className = art.className.replace(/news-art--f\d/, 'news-art--f' + f); const c = getComputedStyle(art, '::after'); return { x: W - parseFloat(c.right) - parseFloat(c.width), y: H - parseFloat(c.bottom) - parseFloat(c.height), w: parseFloat(c.width), h: parseFloat(c.height) }; };
  const a0 = after(0), a1 = after(1);
  after(0);
  const c = getComputedStyle(art, '::before'), cb = { l: parseFloat(c.left), t: parseFloat(c.top), r: W - parseFloat(c.right), b: H - parseFloat(c.bottom) };
  const hit = (x, y) => document.elementsFromPoint(box.left + x, box.top + y).some((e) => e instanceof SVGPathElement && !e.classList.contains('news-art-h'));
  const share = (rects) => { let n = 0, k = 0; for (const r of rects) for (let y = Math.max(0, r.y) + 0.5; y < Math.min(H, r.y + r.h); y += 1) for (let x = Math.max(0, r.x) + 0.5; x < Math.min(W, r.x + r.w); x += 1) { n++; if (hit(x, y)) k++; } return n ? k / n : 0; };
  const arm = 12, t = 1.5, corners = [[cb.l, cb.t, 1, 1], [cb.r, cb.t, -1, 1], [cb.l, cb.b, 1, -1], [cb.r, cb.b, -1, -1]].flatMap(([x, y, sx, sy]) => [{ x: sx > 0 ? x : x - arm, y: sy > 0 ? y : y - t, w: arm, h: t }, { x: sx > 0 ? x : x - t, y: sy > 0 ? y : y - arm, w: t, h: arm }]);
  return {
    top: share([{ x: 0, y: 0, w: W, h: H * 0.03 }]), foot: share([{ x: 0, y: H * 0.97, w: W, h: H * 0.03 }]), side: share([{ x: 0, y: 0, w: W * 0.016, h: H }]),
    cast: share([{ x: 0, y: 0, w: W, h: H * 0.02 }, { x: 0, y: H * 0.98, w: W, h: H * 0.02 }]),
    stair: share([a0]), burst: share([a1]), corners: share(corners),
  };
}, [html, wide]);

const ok = (m) => [
  m.top <= LIMIT.strip && m.stair <= LIMIT.furniture,
  m.cast <= LIMIT.strip && m.burst <= LIMIT.furniture,
  m.side <= LIMIT.strip,
  m.foot <= LIMIT.strip,
].flatMap((y, f) => (y ? [f] : []));
const r3 = (x) => Math.round(x * 1000) / 1000;
const out = { limit: LIMIT, card: {}, band: {} };
for (const [frame, wide] of [['card', false], ['band', true]]) for (const key of Object.keys(table.card)) {
  out[frame][key] = [];
  for (let v = 0; v < 4; v++) {
    const row = [];
    for (let k = 0; k < 3; k++) { const m = await measure(wrap(key, v, k, wide), wide); row.push({ sig: sig(table[frame][key][v][k]), ok: ok(m), ...Object.fromEntries(Object.entries(m).map(([a, b]) => [a, r3(b)])) }); }
    out[frame][key].push(row);
  }
  console.log(`${frame} ${key}: ${out[frame][key].map((r) => r.map((t) => t.ok.join('')).join('/')).join(' ')}`);
}
await browser.close();
server.close();
fs.writeFileSync(path.join(here, 'edges.json'), JSON.stringify(out));
