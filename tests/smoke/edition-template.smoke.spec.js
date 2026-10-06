// Starting point for another edition (for example RivetMSP). Copy this file to <edition>.smoke.spec.js, set the paths below to
// that edition's routes, and keep the same env-var contract so one CI job can run any edition. Skipped until configured.
const { test, expect } = require('@playwright/test');
const { assertScratchTarget, expectNoServerError } = require('./helpers');

const EDITION_PAGES = (process.env.SMOKE_TEMPLATE_PAGES || '').split(',').map((s) => s.trim()).filter(Boolean);

test.describe('edition template', () => {
  test.skip(EDITION_PAGES.length === 0, 'SMOKE_TEMPLATE_PAGES not set (comma separated paths, e.g. /dashboard,/tickets)');
  test.beforeAll(() => assertScratchTarget());

  for (const path of EDITION_PAGES) {
    test(`page renders without a server error: ${path}`, async ({ page }) => {
      const res = await page.goto(path);
      expect(res.status()).toBeLessThan(500);
      await expectNoServerError(page);
    });
  }
});
