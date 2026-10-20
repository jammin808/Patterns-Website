// Checks pages one by one: status, console errors, failed requests, title/description length, one h1, canonical,
// JSON-LD, images without alt, horizontal overflow at phone and desktop widths, axe WCAG 2.2 AA (serious and
// critical). Optional screenshots.
//   node check.mjs --base http://127.0.0.1:8091 /patterns/ /patterns/features/ [--shots ./out]
import { chromium } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import fs from 'node:fs';
import path from 'node:path';

const args = process.argv.slice(2);
let base = 'http://127.0.0.1:8080';
let shots = null;
const paths = [];
for (let i = 0; i < args.length; i++) {
  if (args[i] === '--base') base = args[++i];
  else if (args[i] === '--shots') shots = args[++i];
  else paths.push(args[i]);
}
if (shots) fs.mkdirSync(shots, { recursive: true });

const browser = await chromium.launch();
let failures = 0;
for (const p of paths) {
  for (const [label, viewport, scheme] of [['desktop', { width: 1440, height: 900 }, 'dark'], ['phone', { width: 390, height: 844 }, 'light']]) {
    const context = await browser.newContext({ viewport, colorScheme: scheme });
    const page = await context.newPage();
    const problems = [];
    page.on('console', (m) => { if (m.type() === 'error') problems.push(`console: ${m.text()}`); });
    page.on('requestfailed', (r) => problems.push(`failed: ${r.url()}`));
    page.on('response', (r) => { if (r.status() >= 400 && !r.url().includes('/api/')) problems.push(`HTTP ${r.status()} ${r.url()}`); });
    const res = await page.goto(base + p, { waitUntil: 'networkidle' });
    if (res.status() !== 200) problems.push(`status ${res.status()}`);
    const f = await page.evaluate(() => ({
      title: document.title,
      desc: document.querySelector('meta[name="description"]')?.content ?? '',
      h1: [...document.querySelectorAll('h1')].map((h) => h.textContent.trim()),
      ld: [...document.querySelectorAll('script[type="application/ld+json"]')].map((s) => s.textContent),
      noAlt: [...document.querySelectorAll('img:not([alt])')].length,
      overflow: document.documentElement.scrollWidth - innerWidth,
      headings: [...document.querySelectorAll('h1,h2,h3,h4')].map((h) => +h.tagName[1]),
    }));
    if (f.h1.length !== 1) problems.push(`${f.h1.length} h1`);
    if (f.title.length > 65) problems.push(`title ${f.title.length} chars`);
    if (f.desc.length < 50 || f.desc.length > 160) problems.push(`description ${f.desc.length} chars`);
    for (const ld of f.ld) { try { JSON.parse(ld); } catch { problems.push('invalid JSON-LD'); } }
    if (f.noAlt) problems.push(`${f.noAlt} img without alt`);
    if (f.overflow > 1) problems.push(`overflow ${f.overflow}px`);
    for (let i = 1; i < f.headings.length; i++) if (f.headings[i] > f.headings[i - 1] + 1) { problems.push(`heading jump h${f.headings[i - 1]}→h${f.headings[i]}`); break; }
    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    for (const v of axe.violations) {
      if (['serious', 'critical'].includes(v.impact)) problems.push(`axe ${v.id} (${v.impact}) ×${v.nodes.length}: ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' | ')}`);
    }
    if (shots) {
      const name = (p.replace(/\//g, '_').replace(/^_|_$/g, '') || 'home') + `-${label}.png`;
      await page.screenshot({ path: path.join(shots, name), fullPage: true });
    }
    console.log(`${problems.length ? 'FAIL' : 'ok  '} ${label.padEnd(7)} ${p}  h1="${f.h1[0] ?? ''}"`);
    for (const pr of problems) console.log(`       - ${pr}`);
    failures += problems.length ? 1 : 0;
    await context.close();
  }
}
await browser.close();
process.exit(failures ? 1 : 0);
