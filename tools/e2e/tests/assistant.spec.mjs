// The assistant widget opens from the support page and, with no API key configured, offers the contact routes.
import { test, expect } from '@playwright/test';

test('assistant launcher opens a panel that degrades gracefully', async ({ page }) => {
  await page.goto('/support/');
  const launcher = page.locator('[data-chat-open]').first();
  await expect(launcher).toBeVisible();
  await launcher.click();
  const panel = page.locator('[data-chat-panel]');
  await expect(panel).toBeVisible();
  const input = panel.locator('textarea, input[type="text"]').first();
  await input.fill('What does StageWatch do?');
  await input.press('Enter');
  await expect(panel).toContainText(/contact|not available|unavailable/i, { timeout: 10_000 });
});
