// Industry Updates in a browser (docs/NEWS.md §10, §12): the index lists the launch articles, a category narrows
// the list and the phone's category form reaches the same page, an article has its parts in order and reports a
// click on a source with a keepalive fetch while the source opens in a new tab, and the feed lists the articles
// with tracked links that go to the stored address. pages.spec checks every article and category page the
// sitemap names (status, SEO basics, axe, no sideways scroll).
import { test, expect } from '@playwright/test';

/** A category chip's own name, without its count. */
const chipName = (chip) => chip.evaluate((a) => a.firstChild.textContent.trim());

test('the index lists the articles and a category narrows them', async ({ page }) => {
  await page.goto('/news/');
  await expect(page.locator('h1')).toHaveText('Industry Updates');
  const all = await page.locator('article.news-card').count();
  expect(all).toBeGreaterThan(1);
  await expect(page.locator('article.news-card--lead')).toHaveCount(1);
  await expect(page.locator('.news-cats a[href="/news/"]')).toHaveAttribute('aria-current', 'page');

  const chip = page.locator('.news-cats a[href^="/news/category/"]').first();
  const href = await chip.getAttribute('href');
  const name = await chipName(chip);
  await chip.click();
  await expect(page).toHaveURL(new RegExp(`${href}$`));
  await expect(page.locator('h1')).toHaveText(name);
  await expect(page.locator(`.news-cats a[href="${href}"]`)).toHaveAttribute('aria-current', 'page');
  const cats = await page.locator('article.news-card .news-card-cats').allInnerTexts();
  expect(cats.length).toBeGreaterThan(0);
  for (const line of cats) expect(line.split(', ')).toContain(name);
  expect(cats.length).toBeLessThanOrEqual(all);
});

test('the phone shows a category form that reaches the same page', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/news/');
  await expect(page.locator('.news-cats-nav')).toBeHidden();
  const select = page.locator('#news-pick');
  await expect(select).toBeVisible();
  const value = await select.locator('option[value]:not([value=""])').first().getAttribute('value');
  await select.selectOption(value);
  await page.getByRole('button', { name: 'Show' }).click();
  await expect(page).toHaveURL(new RegExp(`/news/category/${value}/$`));
  await expect(select).toHaveValue(value);
  expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);
});

test('an article has its parts in order and counts a click on a source', async ({ page, context }) => {
  await page.goto('/news/');
  await page.locator('article.news-card h3 a').first().click();
  await expect(page).toHaveURL(/\/news\/[a-z0-9-]+\/$/);
  await expect(page.locator('h1')).toHaveCount(1);
  await expect(page.locator('nav.c-crumbs li')).toHaveCount(4);

  const order = await page.evaluate(() => ['.news-tags', 'h1', '.news-meta', '.news-body', '#news-confirmed', '#news-sources']
    .map((sel) => document.querySelector(sel)).map((el) => (el ? [...document.querySelectorAll('*')].indexOf(el) : -1)));
  expect(order).not.toContain(-1);
  expect([...order].sort((a, b) => a - b)).toEqual(order);
  await expect(page.locator('#news-confirmed ~ ul .c-badge--done').first()).toHaveText('Confirmed');

  // The source opens in a new tab; this run answers for the outside world, so nothing leaves the machine.
  const origin = new URL(page.url()).origin;
  await context.route((url) => url.origin !== origin, (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Source</title>' }));
  const link = page.locator('.news-sources a[data-news-link]').first();
  await expect(link).toHaveAttribute('target', '_blank');
  await expect(link).toHaveAttribute('rel', 'nofollow noopener');
  const id = await link.getAttribute('data-news-link');
  const [report, popup] = await Promise.all([
    page.waitForRequest((r) => r.method() === 'POST' && r.url() === `${origin}/api/news/links/${id}/click`),
    page.waitForEvent('popup'),
    link.click(),
  ]);
  expect(report.headers()['x-requested-with']).toBe('fetch');
  expect((await report.response())?.status()).toBe(200);
  expect(popup.url()).toBe(await link.getAttribute('href'));
  await popup.close();
  // That click was counted: a second from this address within ten minutes is not.
  const again = await page.evaluate((path) => fetch(path, { method: 'POST', headers: { 'X-Requested-With': 'fetch' } }).then((r) => r.json()), `/api/news/links/${id}/click`);
  expect(again).toEqual({ counted: false });
});

test('the feed lists the articles with links that go to their sources', async ({ request }) => {
  const res = await request.get('/news/feed.xml');
  expect(res.status()).toBe(200);
  expect(res.headers()['content-type']).toContain('application/rss+xml');
  const xml = await res.text();
  expect(xml).toContain('<rss version="2.0"');
  expect((xml.match(/<item>/g) ?? []).length).toBeGreaterThan(1);
  const go = /\/news\/go\/(\d+)\//.exec(xml);
  expect(go).not.toBeNull();
  const hop = await request.get(go[0], { maxRedirects: 0 });
  expect(hop.status()).toBe(302);
  expect(hop.headers().location).toMatch(/^https?:\/\//);
  expect(hop.headers()['x-robots-tag']).toContain('noindex');
  expect(hop.headers()['cache-control']).toContain('no-store');
});
