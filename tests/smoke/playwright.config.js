// Playwright configuration for the edition smoke suite. Everything environment-specific comes from env vars (see README.md).
const { defineConfig, devices } = require('@playwright/test');

const baseURL = process.env.SMOKE_BASE_URL;

module.exports = defineConfig({
  testDir: '.',
  testMatch: /.*\.smoke\.spec\.js/,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,          // one signed-in agent, shared scratch data: run in order
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
  use: {
    baseURL,
    ignoreHTTPSErrors: process.env.SMOKE_IGNORE_TLS === '1',   // self-signed scratch certificates
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
