// The forms people send, at desktop and phone widths: a refused send is summarised at the top of the form, the
// summary takes focus and each of its links leads to its field; a send faster than a person fills the form in comes
// back with the input kept and asks for another press; a refusal says what to change; the answer takes focus; and a
// second press while the first is on its way sends nothing more, while the button says it is working. Contact,
// sign-up and password reset.
import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

/** Emails in the run's mail log holding $marker (quoted-printable soft breaks undone). */
function mailsWith(marker) {
  const dir = path.join(process.env.PSC_E2E_STORAGE, 'mail');
  if (!fs.existsSync(dir)) return 0;
  return fs.readdirSync(dir).filter((f) => f.endsWith('.eml'))
    .map((f) => fs.readFileSync(path.join(dir, f), 'utf8').replace(/=\r?\n/g, ''))
    .filter((raw) => raw.includes(marker)).length;
}

/** The forms turn back a send sooner than a person fills them in (three seconds at most): take a person's time. */
const asAPerson = (page) => page.waitForTimeout(3_100);

/**
 * Watches the next send of the form on this page: counts its posts to $path (each held back a second, so a second
 * press lands while the first is on its way), and keeps what the busy form's button says in this tab's session
 * storage, which outlives the page the button was on.
 */
async function watchSend(page, path) {
  const posts = { count: 0 };
  await page.route(`**${path}`, async (route) => {
    if (route.request().method() === 'POST') {
      posts.count += 1;
      await new Promise((resolve) => { setTimeout(resolve, 1_000); });
    }
    await route.continue();
  });
  await page.evaluate(() => {
    sessionStorage.removeItem('e2e-busy');
    new MutationObserver(() => {
      const button = document.querySelector('form[aria-busy="true"] [type="submit"][aria-disabled="true"]');
      if (button) sessionStorage.setItem('e2e-busy', button.textContent.trim());
    }).observe(document.body, { subtree: true, childList: true, characterData: true, attributes: true });
  });
  return { posts, busy: () => page.evaluate(() => sessionStorage.getItem('e2e-busy')) };
}

/**
 * An impatient double press: one press, then another while the first send is on its way (it is held for a second).
 * A browser folds a quick double click into one send by itself; a second press after the first has gone needs the
 * page's guard to send nothing more. Pressed from inside the page, since a test's own clicks wait for the next page.
 */
async function pressTwice(page) {
  await page.evaluate(() => new Promise((resolve) => {
    const button = document.querySelector('main form[method="post"] button[type="submit"]');
    button.click();
    setTimeout(() => { button.click(); resolve(); }, 300);
  }));
}

const noSideScroll = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth <= 1);

test('contact: an empty send is summarised and focused, each link leads to its field, and the input stays', async ({ page }) => {
  await page.goto('/contact/');
  await page.getByRole('button', { name: 'Send message' }).click();
  await expect(page).toHaveURL(/\/contact\/#form$/);
  const summary = page.locator('[data-error-summary]');
  await expect(summary).toBeFocused();
  await expect(summary).toContainText('Check 4 fields before sending');
  await expect(page.locator('#email')).toHaveAttribute('aria-invalid', 'true');
  await expect(page.locator('#email')).toHaveAttribute('aria-describedby', 'email-err');
  expect(await noSideScroll(page)).toBe(true);
  await summary.getByRole('link', { name: /email address/i }).click();
  await expect(page.locator('#email')).toBeFocused();

  await page.locator('#name').fill('E2E Walker');
  await page.locator('#email').fill('walker.example.test');
  await page.locator('#topic').selectOption('support');
  await page.locator('#message').fill('A message that is long enough to send.');
  await page.getByRole('button', { name: 'Send message' }).click();
  await expect(page.locator('[data-error-summary]')).toBeFocused();
  await expect(page.locator('#email-err')).toHaveText('Enter an email address in the format name@example.co.uk');
  await expect(page.locator('#name')).toHaveValue('E2E Walker');
  await expect(page.locator('#message')).toHaveValue('A message that is long enough to send.');
});

test('contact: too quick and too many links both keep the message; pressed twice it sends once', async ({ page }, info) => {
  const marker = `e2e-contact-${info.project.name}-${Date.now()}`;
  const message = `The countdown froze at 00:10 during rehearsal. ${marker}`;
  // Filled in and sent the moment the page opens, sooner than a person types: not sent yet, the input kept.
  await page.goto('/contact/');
  await page.locator('#name').fill('E2E Walker');
  await page.locator('#email').fill(`walker-${info.project.name}@example.test`);
  await page.locator('#topic').selectOption('support');
  await page.locator('#message').fill(message);
  await page.getByRole('button', { name: 'Send message' }).click();
  const summary = page.locator('[data-error-summary]');
  await expect(summary).toBeFocused();
  await expect(summary).toContainText('Your message is not sent yet');
  await expect(summary).toContainText('press Send message again');
  await expect(page.locator('#message')).toHaveValue(message);
  expect(mailsWith(marker)).toBe(0);

  // Too many links: what to fix, with the limit, however fast.
  const spammy = `Links https://a.example/1 https://b.example/2 www.c.example https://d.example/4 ${marker}`;
  await page.locator('#message').fill(spammy);
  await page.getByRole('button', { name: 'Send message' }).click();
  await expect(page.locator('[data-error-summary]')).toBeFocused();
  await expect(page.locator('#message-err')).toHaveText('Your message has 4 links, and the limit is 3. Remove some links, then send it again.');
  await expect(page.locator('#message')).toHaveValue(spammy);
  expect(mailsWith(marker)).toBe(0);

  // Fixed, at a person's pace, then pressed twice: one post, the button busy, and the answer takes focus.
  await page.locator('#message').fill(message);
  await asAPerson(page);
  const send = await watchSend(page, '/contact/');
  await pressTwice(page);
  await expect(page.locator('#sent')).toBeFocused();
  await expect(page.locator('#sent')).toContainText('Message sent');
  expect(send.posts.count).toBe(1);
  expect(await send.busy()).toBe('Sending…');
  expect(mailsWith(marker)).toBe(1);
  expect(await noSideScroll(page)).toBe(true);
});

test('sign-up: what to fix comes first, a quick send asks for the password again, and the answer takes focus', async ({ page }, info) => {
  await page.goto('/portal/register/');
  await page.getByRole('button', { name: 'Create account' }).click();
  const summary = page.locator('[data-error-summary]');
  await expect(summary).toBeFocused();
  await expect(summary).toContainText(/Check \d fields before creating your account/);
  expect(await noSideScroll(page)).toBe(true);
  await summary.getByRole('link', { name: /terms of use/i }).click();
  await expect(page.locator('#register-terms')).toBeFocused();

  // Filled in and sent the moment the page opens: not created yet, the details kept but never the password.
  const email = `walker-${info.project.name}-${Date.now()}@example.test`;
  await page.goto('/portal/register/');
  await page.locator('#register-name').fill('E2E Walker');
  await page.locator('#register-email').fill(email);
  await page.locator('#register-password').fill('a long show password 42');
  await page.locator('#register-terms').check();
  await page.getByRole('button', { name: 'Create account' }).click();
  await expect(page.locator('[data-error-summary]')).toBeFocused();
  await expect(page.locator('[data-error-summary]')).toContainText('type your password again, then press Create account');
  await expect(page.locator('#register-email')).toHaveValue(email);
  await expect(page.locator('#register-password')).toHaveValue('');

  // An address in the wrong form is said first, however fast.
  await page.locator('#register-email').fill('walker.example.test');
  await page.locator('#register-password').fill('a long show password 42');
  await page.getByRole('button', { name: 'Create account' }).click();
  await expect(page.locator('[data-error-summary]')).toContainText('Enter a valid email address.');
  await expect(page.locator('#register-name')).toHaveValue('E2E Walker');

  await page.locator('#register-email').fill(email);
  await page.locator('#register-password').fill('a long show password 42');
  await asAPerson(page);
  const send = await watchSend(page, '/portal/register/');
  await pressTwice(page);
  await expect(page.locator('h1')).toHaveText('Check your email');
  await expect(page.locator('[data-feedback]')).toBeFocused();
  await expect(page.locator('[data-feedback]')).toContainText(email);
  expect(send.posts.count).toBe(1);
  expect(await send.busy()).toBe('Creating account…');
});

test('password reset: the address is checked, a quick send keeps it, and the answer takes focus', async ({ page }) => {
  await page.goto('/portal/forgot/');
  await page.getByRole('button', { name: 'Send reset link' }).click();
  await expect(page.locator('[data-error-summary]')).toBeFocused();
  await expect(page.locator('[data-error-summary]')).toContainText('Enter the email address you use to sign in.');
  await page.locator('#forgot-email').fill('nobody.example.test');
  await page.getByRole('button', { name: 'Send reset link' }).click();
  await expect(page.locator('[data-error-summary]')).toContainText('Enter an email address in the format name@example.co.uk');
  await expect(page.locator('#forgot-email')).toHaveValue('nobody.example.test');
  await expect(page.locator('#forgot-email')).toHaveAttribute('aria-invalid', 'true');

  await page.goto('/portal/forgot/');
  await page.locator('#forgot-email').fill('nobody@example.test');
  await page.getByRole('button', { name: 'Send reset link' }).click();
  await expect(page.locator('[data-error-summary]')).toBeFocused();
  await expect(page.locator('[data-error-summary]')).toContainText('press Send reset link again');
  await expect(page.locator('#forgot-email')).toHaveValue('nobody@example.test');

  await asAPerson(page);
  const send = await watchSend(page, '/portal/forgot/');
  await pressTwice(page);
  await expect(page.locator('[data-feedback]')).toBeFocused();
  await expect(page.locator('[data-feedback]')).toContainText('If that address has an account, a reset link is on its way.');
  expect(send.posts.count).toBe(1);
  expect(await send.busy()).toBe('Sending…');
});

test('without scripting the forms still send and still explain', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  await page.goto('/contact/');
  await page.getByRole('button', { name: 'Send message' }).click();
  await expect(page.locator('[data-error-summary]')).toContainText('Check 4 fields before sending');
  await page.goto('/portal/forgot/');
  await page.locator('#forgot-email').fill('nobody@example.test');
  await page.waitForTimeout(2_100);
  await page.getByRole('button', { name: 'Send reset link' }).click();
  await expect(page.locator('main')).toContainText('If that address has an account, a reset link is on its way.');
  await context.close();
});
