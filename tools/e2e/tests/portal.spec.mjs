// The client journey: register, confirm the email from the mail log, sign in, report a bug, open the board.
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

test('register, verify, sign in, report a bug and see the ideas board', async ({ page }) => {
  const email = `client${Date.now()}@example.test`;
  await page.goto('/portal/register/');
  await page.getByLabel(/name/i).first().fill('E2E Client');
  await page.getByLabel(/email/i).first().fill(email);
  await page.getByLabel(/^password/i).first().fill('a long show password 42');
  const terms = page.getByLabel(/terms/i);
  if (await terms.count()) await terms.check();
  await page.getByRole('button', { name: /create|register|sign up/i }).click();
  await expect(page.locator('h1')).toContainText(/email/i);

  const link = latestMailLink(/http:\/\/127\.0\.0\.1:8765\/portal\/verify\/\?token=[a-f0-9.]+/);
  await page.goto(link.replace('http://127.0.0.1:8765', ''));
  await expect(page.locator('main')).toContainText(/confirmed|verified/i);

  await page.goto('/portal/login/');
  await page.getByLabel(/email/i).first().fill(email);
  await page.getByLabel(/password/i).first().fill('a long show password 42');
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(/\/portal\/$/);

  await page.goto('/portal/tickets/new/?type=bug&product=stagewatch');
  await page.getByLabel(/title|summary/i).first().fill('Countdown freezes after resume');
  // The ticket form's fields; the assistant's closed panel (outside <main>) holds a textarea of its own.
  for (const field of await page.locator('main textarea').all()) {
    if (!(await field.inputValue())) await field.fill('Steps and details for the e2e test.');
  }
  await page.getByRole('button', { name: /submit|send|create|report/i }).click();
  await expect(page.locator('h1')).toContainText('Countdown freezes after resume');

  await page.goto('/portal/ideas/');
  await expect(page.locator('h1')).toHaveCount(1);
});
