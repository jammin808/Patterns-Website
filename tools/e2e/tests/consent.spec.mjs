// The cookie choice, from an empty browser: it asks on a first visit, nothing reaches Google before an answer, a
// rejection keeps Google out and is remembered, an acceptance loads the tag at once and on every later page, and
// "Cookie settings" reopens it, where rejecting withdraws the acceptance and removes the _ga cookies. Google's hosts
// are intercepted, so the run never reaches them.
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test.use({ storageState: { cookies: [], origins: [] } });

/** Counts requests to Google's hosts and answers them locally (an empty script; no content for the beacon). */
async function interceptGoogle(page) {
  const seen = [];
  await page.route(/https:\/\/([a-z0-9-]+\.)*(googletagmanager|google-analytics|analytics\.google)\.com\//, async (route) => {
    seen.push(route.request().url());
    await route.fulfill(route.request().url().includes('/gtag/js')
      ? { status: 200, contentType: 'text/javascript', body: '/* stand-in for gtag.js */' }
      : { status: 204, body: '' });
  });
  return seen;
}

const banner = (page) => page.locator('[data-consent]');

test('a first visit is asked, and nothing reaches Google until the visitor answers', async ({ page }) => {
  const google = await interceptGoogle(page);
  await page.goto('/');
  await expect(banner(page)).toBeVisible();
  await expect(page.getByRole('button', { name: 'Accept statistics cookies' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Reject statistics cookies' })).toBeVisible();
  const axe = await new AxeBuilder({ page }).include('[data-consent]').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
  expect(axe.violations.map((v) => `${v.id}: ${v.nodes[0]?.target?.join(' ')}`)).toEqual([]);
  await page.goto('/stagewatch/');
  await expect(banner(page)).toBeVisible();
  expect(google, 'no request to Google before an answer').toEqual([]);
  expect((await page.context().cookies()).filter((c) => c.name.startsWith('_ga'))).toEqual([]);
});

test('rejecting keeps Google out and is remembered on later pages', async ({ page }) => {
  const google = await interceptGoogle(page);
  await page.goto('/');
  await page.getByRole('button', { name: 'Reject statistics cookies' }).click();
  await expect(banner(page)).toBeHidden();
  await page.goto('/support/');
  await expect(banner(page)).toBeHidden();
  expect(google).toEqual([]);
  expect(await page.evaluate(() => localStorage.getItem('psc-consent'))).toMatch(/^denied:\d+$/);
});

test('accepting loads the tag at once and on later pages; Cookie settings can withdraw it', async ({ page, context }) => {
  const google = await interceptGoogle(page);
  await page.goto('/');
  await page.getByRole('button', { name: 'Accept statistics cookies' }).click();
  await expect(banner(page)).toBeHidden();
  await expect.poll(() => google.filter((u) => u.includes('/gtag/js?id=G-E2ETEST00')).length).toBe(1);
  expect(await page.evaluate(() => window.dataLayer.some((e) => e[0] === 'consent' && e[1] === 'update' && e[2].analytics_storage === 'granted'))).toBe(true);

  await page.goto('/stagewatch/');
  await expect(banner(page)).toBeHidden();
  await expect.poll(() => google.filter((u) => u.includes('/gtag/js')).length, 'loaded on the next page from the stored answer').toBe(2);

  // Withdraw: a _ga cookie as Google would set it is removed, and consent is updated to denied.
  await context.addCookies([{ name: '_ga', value: 'GA1.1.1.1', domain: '127.0.0.1', path: '/' }]);
  await page.reload();
  const settings = page.getByRole('button', { name: 'Cookie settings' });
  await settings.scrollIntoViewIfNeeded();
  await settings.click();
  await expect(banner(page)).toBeVisible();
  await expect(page.getByRole('button', { name: 'Accept statistics cookies' })).toBeFocused();
  await page.getByRole('button', { name: 'Reject statistics cookies' }).click();
  await expect(banner(page)).toBeHidden();
  await expect(settings).toBeFocused();
  expect((await context.cookies()).filter((c) => c.name.startsWith('_ga'))).toEqual([]);
  expect(await page.evaluate(() => window.dataLayer.some((e) => e[0] === 'consent' && e[1] === 'update' && e[2].analytics_storage === 'denied'))).toBe(true);
  expect(await page.evaluate(() => localStorage.getItem('psc-consent'))).toMatch(/^denied:\d+$/);
});

test('an answer older than a year is asked again', async ({ page }) => {
  await interceptGoogle(page);
  await page.goto('/');
  await page.evaluate(() => localStorage.setItem('psc-consent', `granted:${Date.now() - 366 * 24 * 3600 * 1000}`));
  await page.reload();
  await expect(banner(page)).toBeVisible();
});
