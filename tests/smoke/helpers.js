// Shared helpers for the smoke suite. No selector here is specific to one page; page objects stay in the specs.
const { expect } = require('@playwright/test');

/** Read a required env var or throw a message that says which one is missing. */
function need(name) {
  const v = process.env[name];
  if (!v) throw new Error(`Set ${name} (see tests/smoke/README.md).`);
  return v;
}

/**
 * Refuse to run against anything that is not marked as scratch data. The suite creates and edits records; pointing it at a
 * production instance by mistake is the one failure we make impossible. The caller must say so explicitly.
 */
function assertScratchTarget() {
  if (process.env.SMOKE_I_AM_ON_SCRATCH !== '1') {
    throw new Error('Refusing to run: set SMOKE_I_AM_ON_SCRATCH=1 to confirm SMOKE_BASE_URL is a scratch instance with throwaway data.');
  }
  const base = need('SMOKE_BASE_URL');
  const blocked = (process.env.SMOKE_BLOCKED_HOSTS || '').split(',').map((s) => s.trim()).filter(Boolean);
  const host = new URL(base).hostname;
  if (blocked.includes(host)) {
    throw new Error(`Refusing to run: ${host} is listed in SMOKE_BLOCKED_HOSTS.`);
  }
}

/** Collect console errors and failed same-origin requests so a page that "renders" but is broken still fails the test. */
function watchPage(page) {
  const problems = [];
  page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
  page.on('response', (r) => {
    const url = new URL(r.url());
    if (url.origin === new URL(need('SMOKE_BASE_URL')).origin && r.status() >= 500) {
      problems.push(`HTTP ${r.status()} ${url.pathname}`);
    }
  });
  return problems;
}

/** A page that loaded must not show PHP errors or an error stack. */
async function expectNoServerError(page) {
  const body = (await page.locator('body').innerText()).slice(0, 20000);
  expect(body).not.toMatch(/(Fatal error|Parse error|Warning: |Notice: |Uncaught |Stack trace:)/);
}

module.exports = { need, assertScratchTarget, watchPage, expectNoServerError };
