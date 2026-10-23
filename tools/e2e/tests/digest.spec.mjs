// The weekly Industry Updates digest end to end (docs/NEWS.md §14.14): from the hub's follow row to the signup page,
// a sign-up sent at a person's pace, "Check your inbox", the confirmation email read from the run's mail log, its
// link's page (a button, nothing done yet), the button pressed, the done page's way out, the unsubscribe page and its
// button, and a mail client's RFC 8058 one-click POST to that address with no cookie: 200, never a redirect, and no
// cookie set.
import { test, expect, request as apiRequest } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

/** The newest email in the run's mail log sent to $to, its quoted-printable undone, or null. */
function newestMailTo(to) {
  const dir = path.join(process.env.PSC_E2E_STORAGE, 'mail');
  if (!fs.existsSync(dir)) return null;
  const mails = fs.readdirSync(dir).filter((f) => f.endsWith('.eml'))
    .map((f) => ({ f, at: fs.statSync(path.join(dir, f)).mtimeMs }))
    .sort((a, b) => b.at - a.at)
    .map(({ f }) => fs.readFileSync(path.join(dir, f), 'utf8')
      .replace(/=\r?\n/g, '')
      .replace(/=([0-9A-F]{2})/g, (_, hex) => String.fromCharCode(parseInt(hex, 16))));
  return mails.find((raw) => new RegExp(`^To: ${to.replace(/[.+]/g, '\\$&')}\\r?$`, 'm').test(raw)) ?? null;
}

/** The signup form turns back a send sooner than a person types an address (two seconds): take a person's time. */
const asAPerson = (page) => page.waitForTimeout(2_100);

test('digest: sign up from the follow row, confirm by the button, unsubscribe by the button and in one click', async ({ page, baseURL }) => {
  const email = `e2e-digest-${Date.now()}@example.test`;

  await page.goto('/news/');
  await page.locator('.news-follow-list').getByRole('link', { name: 'Weekly digest by email' }).click();
  await expect(page).toHaveURL(/\/digest\/$/);
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('The weekly digest');

  await page.getByLabel('Email address').fill(email);
  await asAPerson(page);
  await page.getByRole('button', { name: 'Send me the digest' }).click();
  await expect(page).toHaveURL(/\/digest\/#sent$/);
  const sent = page.locator('#sent');
  await expect(sent).toContainText('Check your inbox');
  await expect(sent).toBeFocused();

  // The confirmation, from the log driver's .eml: its link, taken as a path on this server.
  let mail = null;
  await expect.poll(() => { mail = newestMailTo(email); return mail !== null; }, { timeout: 10_000 }).toBe(true);
  expect(mail).toMatch(/^Subject: .*Confirm your Industry Updates digest/m);
  expect(mail).not.toMatch(/^List-Unsubscribe:/m);
  const link = mail.match(/https?:\/\/[^\s"<>]+(\/digest\/confirm\/\?token=[A-Za-z0-9_.%-]+)/);
  expect(link).not.toBeNull();

  // Opening the link does nothing by itself: the page asks, and only its button confirms.
  await page.goto(link[1]);
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Confirm your digest');
  await expect(page.locator('main')).toContainText(email);
  await page.reload();
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Confirm your digest');
  await page.getByRole('button', { name: 'Start my digest' }).click();
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('You are subscribed');

  // The done page's way out opens the unsubscribe page, which asks too, and its button unsubscribes.
  await page.getByRole('link', { name: 'Changed your mind? Unsubscribe' }).click();
  await expect(page).toHaveURL(/\/digest\/unsubscribe\/\?token=/);
  const unsubscribe = new URL(page.url()).pathname + new URL(page.url()).search;
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Unsubscribe from the digest');
  await page.getByRole('button', { name: 'Unsubscribe', exact: true }).click();
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('You are unsubscribed');

  // RFC 8058: what a mail client sends, multipart, with no cookie of any kind. Answered in place, setting none.
  const client = await apiRequest.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  const oneClick = await client.post(unsubscribe, { multipart: { 'List-Unsubscribe': 'One-Click' }, maxRedirects: 0 });
  expect(oneClick.status()).toBe(200);
  expect(oneClick.headers()['set-cookie']).toBeUndefined();
  expect(oneClick.headers()['cache-control']).toBe('private, no-store');
  expect(await oneClick.text()).toContain('You are not subscribed');
  await client.dispose();
});
