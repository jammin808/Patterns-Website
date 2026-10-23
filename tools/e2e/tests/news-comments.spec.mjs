// Industry Updates comments, signed in: the cached article swaps its sign-in link for the comment box, an unsent
// comment survives a reload, a posted one appears with a Delete button, deleting it takes it away, and the page for
// readers without scripting has the same form. Needs a live article: the launch articles imported at start-up.
import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

function latestMailLink(pattern) {
  const dir = path.join(process.env.PSC_E2E_STORAGE, 'mail');
  const files = fs.readdirSync(dir).filter((f) => f.endsWith('.eml')).sort();
  for (const f of files.reverse()) {
    const raw = fs.readFileSync(path.join(dir, f), 'utf8').replace(/=\r?\n/g, '').replace(/=3D/g, '=');
    const m = raw.match(pattern);
    if (m) return m[0];
  }
  throw new Error('no mail link found');
}

/* The first live article the section's sitemaps list: its index names a month's sitemap, which lists articles. */
async function firstArticle(request) {
  const index = await (await request.get('/news/sitemap.xml')).text();
  const month = /<loc>[^<]*?(\/news\/sitemap-\d{4}-\d{2}\.xml)<\/loc>/.exec(index);
  expect(month, 'the index names a month with articles').not.toBeNull();
  const xml = await (await request.get(month[1])).text();
  const paths = [...xml.matchAll(/<loc>[^<]*?(\/news\/[a-z]+\/[a-z0-9-]+\/)<\/loc>/g)].map((m) => m[1]);
  expect(paths.length, 'the month lists at least one article').toBeGreaterThan(0);
  return paths[0];
}

test('comment on an article while signed in, then delete it', async ({ page, request }) => {
  const article = await firstArticle(request);
  const email = `reader${Date.now()}@example.test`;
  const password = 'a long show password 42';

  // Signed out, the cached article offers sign-in and starts no session.
  await page.goto(article);
  const section = page.locator('[data-comments]');
  await expect(section).toBeVisible();
  await expect(section.locator('[data-comment-cta]')).toContainText(/sign in/i);
  expect((await page.context().cookies()).filter((c) => c.name.includes('psc'))).toEqual([]);

  // Create an account from the article's link, confirm it, and sign in.
  await section.locator('[data-comment-register]').click();
  await page.getByLabel(/name/i).first().fill('E2E Reader');
  await page.getByLabel(/email/i).first().fill(email);
  await page.getByLabel(/^password/i).first().fill(password);
  await page.getByLabel(/terms/i).check();
  await page.waitForTimeout(3_100); // the sign-up form turns back a send faster than a person fills it in
  await page.getByRole('button', { name: /create account/i }).click();
  await expect(page.locator('h1')).toContainText(/email/i);
  const link = new URL(latestMailLink(/https?:\/\/[^/\s]+\/portal\/verify\/\?token=[a-f0-9.]+/));
  await page.goto(link.pathname + link.search);
  await page.getByRole('button', { name: 'Confirm my email address' }).click();
  await expect(page.getByRole('link', { name: 'Continue to the article' })).toBeVisible();
  // The confirmation page's own sign-in (the portal's switch above the card has one too) carries the way back.
  await page.locator('.pt-main').getByRole('link', { name: 'Sign in', exact: true }).click();
  await page.getByLabel(/email/i).first().fill(email);
  await page.getByLabel(/password/i).first().fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(new RegExp(`${article}#comments$`));

  // The box replaces the sign-in link and names the reader as others will see them.
  const box = page.locator('[data-comment-box]');
  const input = box.getByLabel('Your comment');
  await expect(input).toBeVisible();
  await expect(box).toContainText('E2E R.');

  // An unsent comment is kept for this article in this tab.
  const words = `Tried this on a corporate show last week ${Date.now()}`;
  await input.fill(words);
  await page.reload();
  await expect(page.locator('[data-comment-box]').getByLabel('Your comment')).toHaveValue(words);

  // Posting adds it to the list, says so, and moves focus to it.
  await page.locator('[data-comment-box]').getByRole('button', { name: 'Post comment' }).click();
  const mine = page.locator('[data-comment-list] > li').filter({ hasText: words });
  await expect(mine).toHaveCount(1);
  await expect(mine).toBeFocused();
  await expect(page.locator('[data-comment-status]')).toHaveText('Your comment is posted.');
  await expect(page.locator('[data-comment-box]').getByLabel('Your comment')).toHaveValue('');

  // A refusal says what to fix, beside the field.
  await page.locator('[data-comment-box]').getByLabel('Your comment').fill('x');
  await page.locator('[data-comment-box]').getByRole('button', { name: 'Post comment' }).click();
  await expect(page.locator('[data-comment-error]')).toHaveText('Write a comment of at least 2 characters.');
  await expect(page.locator('[data-comment-box]').getByLabel('Your comment')).toHaveAttribute('aria-invalid', 'true');

  // The page without scripting shows it too, with its delete form.
  const commentPage = await page.context().newPage();
  await commentPage.goto(`${article}comment/`);
  await expect(commentPage.locator('h1')).toHaveText('Comments');
  await expect(commentPage.locator('li').filter({ hasText: words }).getByRole('button', { name: /^Delete/ })).toBeVisible();
  await commentPage.close();

  // Deleting asks first, then takes it away.
  await mine.getByRole('button', { name: /^Delete/ }).click();
  await mine.getByRole('button', { name: 'Yes, delete it' }).click();
  await expect(page.locator('[data-comment-list] > li').filter({ hasText: words })).toHaveCount(0);
  await expect(page.locator('[data-comment-status]')).toHaveText('Your comment is deleted.');
});
