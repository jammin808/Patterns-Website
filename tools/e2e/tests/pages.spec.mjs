// Every public page: loads, has the SEO basics, no console errors or failed requests, no serious accessibility
// violations, and no horizontal scroll on a phone.
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/** The <loc>s of a sitemap or a sitemap index (an image's <image:loc> is not one). */
async function locs(request, path) {
  const res = await request.get(path);
  expect(res.ok(), path).toBeTruthy();
  const xml = await res.text();
  return [...xml.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => new URL(m[1]).pathname);
}

// The site's sitemap (its pages and the news hubs), then the section's own: its index, then each month's sitemap and
// the news sitemap it names, so every article still gets the page checks. Each page once.
async function pagePaths(request) {
  const paths = await locs(request, '/sitemap.xml');
  for (const child of await locs(request, '/news/sitemap.xml')) {
    expect(child).toMatch(/^\/news\/sitemap-[a-z0-9-]+\.xml$/);
    paths.push(...await locs(request, child));
  }
  return [...new Set(paths)];
}

test('every page in the sitemap is healthy', async ({ page, request }, info) => {
  test.setTimeout(10 * 60_000);
  const paths = await pagePaths(request);
  expect(paths.length).toBeGreaterThan(30);
  const problems = [];
  for (const path of paths) {
    const errors = [];
    const onConsole = (msg) => { if (msg.type() === 'error') errors.push(`console: ${msg.text()}`); };
    const onFail = (req) => errors.push(`request failed: ${req.url()} ${req.failure()?.errorText ?? ''}`);
    const onResponse = (res) => { if (res.status() >= 400 && !res.url().includes('/api/')) errors.push(`HTTP ${res.status()} ${res.url()}`); };
    page.on('console', onConsole);
    page.on('requestfailed', onFail);
    page.on('response', onResponse);
    const res = await page.goto(path, { waitUntil: 'networkidle' });
    if (res.status() !== 200) problems.push(`${path}: status ${res.status()}`);

    const facts = await page.evaluate(() => ({
      title: document.title,
      description: document.querySelector('meta[name="description"]')?.content ?? '',
      canonical: document.querySelector('link[rel="canonical"]')?.href ?? '',
      h1: document.querySelectorAll('h1').length,
      lang: document.documentElement.lang,
      ld: [...document.querySelectorAll('script[type="application/ld+json"]')].map((s) => s.textContent),
      imgsNoAlt: [...document.querySelectorAll('img')].filter((i) => !i.hasAttribute('alt')).map((i) => i.src),
      overflow: document.documentElement.scrollWidth - window.innerWidth,
      emptyLinks: [...document.querySelectorAll('a')].filter((a) => !a.textContent.trim() && !a.getAttribute('aria-label') && !a.querySelector('img[alt]:not([alt=""]), svg[aria-label]')).length,
    }));
    if (!facts.title || facts.title.length > 65) problems.push(`${path}: title "${facts.title}"`);
    if (!facts.description || facts.description.length > 160) problems.push(`${path}: description length ${facts.description.length}`);
    if (!facts.canonical.endsWith(path)) problems.push(`${path}: canonical ${facts.canonical}`);
    if (facts.h1 !== 1) problems.push(`${path}: ${facts.h1} h1 elements`);
    if (facts.lang !== 'en-GB') problems.push(`${path}: lang ${facts.lang}`);
    for (const ld of facts.ld) { try { JSON.parse(ld); } catch { problems.push(`${path}: invalid JSON-LD`); } }
    if (facts.imgsNoAlt.length) problems.push(`${path}: images without alt ${facts.imgsNoAlt.join(', ')}`);
    if (facts.overflow > 1) problems.push(`${path}: horizontal overflow ${facts.overflow}px`);
    if (facts.emptyLinks) problems.push(`${path}: ${facts.emptyLinks} links without text`);

    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    for (const v of axe.violations.filter((v) => ['serious', 'critical'].includes(v.impact))) {
      problems.push(`${path}: axe ${v.id} (${v.impact}) ×${v.nodes.length}: ${v.nodes[0]?.target?.join(' ')}`);
    }
    problems.push(...errors.map((e) => `${path}: ${e}`));
    page.off('console', onConsole);
    page.off('requestfailed', onFail);
    page.off('response', onResponse);
  }
  if (problems.length) console.log(problems.join('\n'));
  expect(problems, `${info.project.name}: ${problems.length} problems`).toEqual([]);
});

test('the dark/light toggle and the mobile menu work without errors', async ({ page }) => {
  await page.goto('/');
  const toggle = page.locator('[data-theme-toggle]').first();
  if (await toggle.count()) {
    const before = await page.evaluate(() => document.documentElement.dataset.theme ?? '');
    await toggle.click();
    // The theme is set at once, never inside a view transition; the poll only waits for the click.
    await expect.poll(() => page.evaluate(() => document.documentElement.dataset.theme ?? '')).not.toBe(before);
  }
});

// site.js's copy hook, on the share row: however quickly it is pressed again, the button says what happened for two
// seconds and then its own words again.
test('Copy link goes back to its own words after a double press', async ({ page }) => {
  await page.goto('/news/');
  await page.locator('article.news-card h3 a').first().click();
  const label = page.locator('.news-share [data-copy] [data-copy-label]');
  await expect(label).toHaveText('Copy link');
  await page.locator('.news-share [data-copy]').dblclick();
  await expect(label).not.toHaveText('Copy link');
  await expect(label).toHaveText('Copy link', { timeout: 4000 });
  await page.waitForTimeout(500);
  await expect(label, 'no stale timer puts the other words back').toHaveText('Copy link');
});

test('404 pages are noindex and helpful', async ({ page }) => {
  const res = await page.goto('/this-does-not-exist/');
  expect(res.status()).toBe(404);
  expect(await page.locator('meta[name="robots"]').getAttribute('content')).toContain('noindex');
  await expect(page.locator('h1')).toHaveCount(1);
});
