import { defineConfig, devices } from '@playwright/test';
import os from 'node:os';
import path from 'node:path';

// One storage folder per run, shared by the server and every test: each worker evaluates this file again with the
// runner's environment, so it keeps the folder the runner chose (where the server writes its mail) instead of its own.
const storage = process.env.PSC_E2E_STORAGE || path.join(os.tmpdir(), `psc-e2e-${process.pid}`);
process.env.PSC_E2E_STORAGE = storage;

export default defineConfig({
  testDir: './tests',
  timeout: 60_000,
  fullyParallel: false,
  workers: 1,
  reporter: [['list']],
  use: {
    baseURL: 'http://127.0.0.1:8765',
    trace: 'retain-on-failure',
    // Statistics cookies already rejected, so the cookie choice never covers what a spec clicks and nothing loads
    // from Google; consent.spec starts from an empty browser instead.
    storageState: { cookies: [], origins: [{ origin: 'http://127.0.0.1:8765', localStorage: [{ name: 'psc-consent', value: `denied:${Date.now()}` }] }] },
  },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } } },
    { name: 'mobile', use: { ...devices['Pixel 7'] }, testMatch: /(pages|forms|consent)\.spec/ },
  ],
  webServer: {
    command: 'php -S 127.0.0.1:8765 tools/dev-router.php',
    cwd: '../..',
    url: 'http://127.0.0.1:8765/health',
    reuseExistingServer: !process.env.CI,
    env: {
      PSC_CONFIG: path.resolve('config.e2e.php'),
      PSC_E2E_STORAGE: storage,
      PSC_NO_CONFIG_MOVE: '1',
    },
  },
});
