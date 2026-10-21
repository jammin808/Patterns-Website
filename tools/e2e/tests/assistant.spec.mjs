// The assistant widget: every launcher opens the panel from the keyboard, the floating panel closes on Escape and
// hands focus back, the floating launcher shows unless another way in is on screen, the support page's inline
// panel takes no focus on load and never closes, and with no API key configured the panel says Offline and offers
// the contact routes. Without scripting the launcher is one plain link to the contact page. Beside it: the cue
// ladder keeps the cue standing by in view after GO, and the theme and dismissed notices stay in local storage.
import { test, expect } from '@playwright/test';

const scrollY = (page) => page.evaluate(() => Math.round(window.scrollY));

test('a launcher that is a link opens the panel with Space and Enter, and Escape closes it', async ({ page }) => {
  await page.goto('/stagewatch/');
  const launcher = page.locator('[data-chat-open]:not([data-chat-fab])').first();
  await launcher.scrollIntoViewIfNeeded();
  await expect(launcher).toHaveAttribute('href', '/contact/');
  await expect(launcher).toHaveAttribute('role', 'button');
  const panel = page.locator('[data-chat-panel]');
  for (const key of ['Space', 'Enter']) {
    await launcher.focus();
    const before = await scrollY(page);
    await page.keyboard.press(key);
    await expect(panel).toBeVisible();
    await expect(page.locator('[data-chat-input]')).toBeFocused();
    await expect(launcher).toHaveAttribute('aria-expanded', 'true');
    expect(await scrollY(page)).toBe(before);
    await expect(page).toHaveURL(/\/stagewatch\/$/);
    await page.keyboard.press('Escape');
    await expect(panel).toBeHidden();
    await expect(launcher).toBeFocused();
    await expect(launcher).toHaveAttribute('aria-expanded', 'false');
  }
});

test('the floating launcher shows unless another way in is on screen, and stays while its panel is open', async ({ page }) => {
  await page.goto('/workflows/conference-timing/');
  const fab = page.locator('[data-chat-fab]');
  await expect(fab).not.toHaveClass(/is-tucked/);
  await expect(fab).toBeVisible();

  await page.goto('/stagewatch/');
  const card = page.locator('[data-chat-open]:not([data-chat-fab])').first();
  await card.scrollIntoViewIfNeeded();
  await expect(fab).toHaveClass(/is-tucked/);
  await card.click();
  await expect(fab).not.toHaveClass(/is-tucked/);
  await page.keyboard.press('Escape');
  await expect(fab).toHaveClass(/is-tucked/);
});

test('the inline panel takes no focus on load and Escape never hides it', async ({ page }) => {
  await page.goto('/support/');
  const panel = page.locator('[data-chat-panel]');
  await expect(panel).toBeVisible();
  await page.waitForTimeout(300);
  expect(await page.evaluate(() => document.activeElement === document.body)).toBe(true);
  expect(await scrollY(page)).toBe(0);
  const input = panel.locator('[data-chat-input]');
  await input.click();
  await page.keyboard.press('Escape');
  await expect(panel).toBeVisible();
  await expect(input).toBeFocused();
});

test('without a key the panel says Offline and still offers the contact routes', async ({ page }) => {
  await page.goto('/support/');
  const panel = page.locator('[data-chat-panel]');
  await expect(panel.locator('.c-chat-status')).toHaveText(/Offline/);
  await expect(panel.getByRole('link', { name: 'contact us' })).toHaveAttribute('href', '/contact/');
  const input = panel.locator('[data-chat-input]');
  await input.fill('What does StageWatch do?');
  await input.press('Enter');
  await expect(panel.locator('[data-chat-log]')).toContainText(/contact|not available|unavailable/i, { timeout: 10_000 });
});

test('closing from another launcher leaves focus on the launcher that was pressed', async ({ page }) => {
  await page.goto('/stagewatch/');
  const card = page.locator('[data-chat-open]:not([data-chat-fab])').first();
  const fab = page.locator('[data-chat-fab]');
  await card.scrollIntoViewIfNeeded();
  await card.click();
  await expect(fab).not.toHaveClass(/is-tucked/);
  const box = await fab.boundingBox();
  test.skip(!box || !(await fab.isVisible()), 'the phone launcher hides under its open panel');
  await fab.focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('[data-chat-panel]')).toBeHidden();
  await expect(fab).toBeFocused();
});

test('without scripting the launcher is one plain link to the contact page', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  for (const [path, count] of [['/stagewatch/', 1], ['/workflows/conference-timing/', 1], ['/contact/', 0]]) {
    await page.goto(path);
    const shown = page.locator('.c-chat-fab:visible');
    await expect(shown).toHaveCount(count);
    if (count) {
      await expect(shown).toHaveAttribute('href', '/contact/');
      expect(await shown.getAttribute('role')).toBeNull();
    }
    await expect(page.locator('a[role="button"]')).toHaveCount(0);
  }
  await context.close();
});

test('after GO the cue standing by is in view, clear of the sticky header and the stuck controls', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.goto('/workflows/conference-timing/');
  const go = page.locator('[data-ladder-go]');
  await go.scrollIntoViewIfNeeded();
  // Put the cue standing by off screen above the header, then press GO from the keyboard.
  await page.evaluate(() => {
    const cue = document.querySelector('[data-cue][data-state="standby"]');
    window.scrollTo({ top: window.scrollY + cue.getBoundingClientRect().bottom + 40, behavior: 'instant' });
  });
  await go.focus();
  await page.keyboard.press('Enter');
  await expect.poll(() => page.evaluate(() => {
    const cue = document.querySelector('[data-cue][data-state="standby"]');
    const header = document.querySelector('[data-header]').getBoundingClientRect().bottom;
    const bar = document.querySelector('.c-ladder-controls').getBoundingClientRect();
    const floor = bar.bottom >= window.innerHeight - 1 ? bar.top : document.documentElement.clientHeight;
    const r = cue.getBoundingClientRect();
    return r.top >= header - 1 && r.bottom <= floor + 1;
  })).toBe(true);
});

test('the theme choice and a dismissed notice stay in this browser and set no cookie', async ({ page, context }) => {
  await page.goto('/stagewatch/');
  const light = page.locator('[data-theme-set="light"]').first();
  if (!(await light.isVisible())) await page.locator('[data-nav-toggle]').click();
  await light.click();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
  await page.locator('[data-dismiss]').first().click();
  expect(await context.cookies()).toEqual([]);
  await page.goto('/support/');
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
  await expect(page.locator('[data-dismissible]')).toBeHidden();
  expect(await context.cookies()).toEqual([]);
});
