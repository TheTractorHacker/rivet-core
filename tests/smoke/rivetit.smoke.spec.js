// RivetIT smoke suite: sign in as an agent, open the pages every release must keep working, create and view a ticket, check the
// client portal and the approvals pages, sign out. Read tests/smoke/README.md first. Everything is driven by env vars:
//
//   SMOKE_BASE_URL            https://scratch.example.test        (no trailing slash needed)
//   SMOKE_I_AM_ON_SCRATCH=1   explicit confirmation that this instance holds throwaway data
//   SMOKE_AGENT_EMAIL / SMOKE_AGENT_PASSWORD     an agent (technician or admin) account on that instance
//   SMOKE_CLIENT_EMAIL / SMOKE_CLIENT_PASSWORD   optional: a portal contact, enables the portal tests
//   SMOKE_TOTP_SECRET         optional, base32; only if the agent account has MFA (needs the otplib package, see README)
//
// STATUS: written against RivetIT's routes and login form (login.php, agent/tickets.php, agent/ticket.php, agent/problems.php,
// agent/changes.php, agent/service_catalog_approvals.php, client/tickets.php, client/my_approvals.php). It has only been
// syntax-checked, never run against a live instance: expect to adjust a selector or two on the first real run.
const { test, expect } = require('@playwright/test');
const { need, assertScratchTarget, watchPage, expectNoServerError } = require('./helpers');

test.describe.configure({ mode: 'serial' });

let agentPage;
let ticketUrl;
const subject = `Smoke test ${Date.now()}`;

test.beforeAll(() => assertScratchTarget());

async function signIn(page, email, password, expectedPath) {
  await page.goto('/login.php');
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('button[name="login"]').click();

  // A dual-role account is asked which side to enter (role_choice buttons); pick the one the caller wants.
  const choice = page.locator(`button[name="role_choice"][value="${expectedPath.startsWith('/client') ? 'client' : 'agent'}"]`);
  if (await choice.count()) {
    await choice.click();
  }
  await page.waitForURL((u) => u.pathname.includes(expectedPath.split('/')[1]), { timeout: 20_000 });
}

test.describe('agent', () => {
  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext({ baseURL: process.env.SMOKE_BASE_URL, ignoreHTTPSErrors: process.env.SMOKE_IGNORE_TLS === '1' });
    agentPage = await context.newPage();
  });

  test.afterAll(async () => {
    await agentPage?.context().close();
  });

  test('login page renders with the email and password fields', async ({ page }) => {
    await page.goto('/login.php');
    await expect(page.locator('input[name="email"]')).toBeVisible();
    await expect(page.locator('input[name="password"]')).toBeVisible();
    await expectNoServerError(page);
  });

  test('a wrong password does not sign in', async ({ page }) => {
    await page.goto('/login.php');
    await page.locator('input[name="email"]').fill(need('SMOKE_AGENT_EMAIL'));
    await page.locator('input[name="password"]').fill('definitely-not-the-password');
    await page.locator('button[name="login"]').click();
    await expect(page).toHaveURL(/login/);
    await expect(page.locator('input[name="password"]')).toBeVisible();
  });

  test('agent signs in and lands on an agent page', async () => {
    const problems = watchPage(agentPage);
    await signIn(agentPage, need('SMOKE_AGENT_EMAIL'), need('SMOKE_AGENT_PASSWORD'), '/agent/');
    await expect(agentPage).toHaveURL(/\/agent\//);
    await expectNoServerError(agentPage);
    expect(problems).toEqual([]);
  });

  for (const [name, path] of [
    ['dashboard', '/agent/dashboard.php'],
    ['ticket list', '/agent/tickets.php'],
    ['problems', '/agent/problems.php'],
    ['changes', '/agent/changes.php'],
    ['service catalog approvals', '/agent/service_catalog_approvals.php'],
  ]) {
    test(`agent page loads: ${name}`, async () => {
      const problems = watchPage(agentPage);
      const res = await agentPage.goto(path);
      expect(res.status()).toBeLessThan(400);
      await expect(agentPage).not.toHaveURL(/login/);
      await expectNoServerError(agentPage);
      expect(problems).toEqual([]);
    });
  }

  test('create a ticket and open it', async () => {
    await agentPage.goto('/agent/tickets.php');
    // The "new ticket" control opens a modal in RivetIT; the link text is the most stable handle.
    await agentPage.getByRole('link', { name: /new ticket|add ticket/i }).first().click();
    await agentPage.locator('input[name="subject"]').fill(subject);
    await agentPage.locator('textarea[name="details"], .tox-edit-area iframe').first().waitFor({ state: 'attached' });
    const details = agentPage.locator('textarea[name="details"]');
    if (await details.count()) {
      await details.fill('Created by the RivetCore smoke suite. Safe to delete.');
    }
    await agentPage.locator('button[name="add_ticket"], button[type="submit"]:has-text("Create")').first().click();
    await agentPage.waitForURL(/ticket\.php\?.*ticket_id=\d+/, { timeout: 20_000 });
    ticketUrl = agentPage.url();
    await expect(agentPage.locator('body')).toContainText(subject);
    await expectNoServerError(agentPage);
  });

  test('the new ticket shows in the list', async () => {
    test.skip(!ticketUrl, 'ticket creation did not run');
    await agentPage.goto('/agent/tickets.php');
    await expect(agentPage.locator('body')).toContainText(subject);
  });

  test('global search finds the ticket', async () => {
    test.skip(!ticketUrl, 'ticket creation did not run');
    await agentPage.goto('/agent/global_search.php?query=' + encodeURIComponent(subject));
    await expect(agentPage.locator('body')).toContainText(subject);
  });

  test('sign out ends the session', async () => {
    await agentPage.goto('/agent/ticket.php?ticket_id=1').catch(() => {});
    const logout = agentPage.locator('a[href*="logout"]').first();
    await logout.click({ force: true });
    await agentPage.goto('/agent/tickets.php');
    await expect(agentPage).toHaveURL(/login/);
  });
});

test.describe('client portal', () => {
  test.skip(!process.env.SMOKE_CLIENT_EMAIL, 'SMOKE_CLIENT_EMAIL not set');

  test('portal contact signs in and reaches tickets and approvals', async ({ page }) => {
    const problems = watchPage(page);
    await signIn(page, need('SMOKE_CLIENT_EMAIL'), need('SMOKE_CLIENT_PASSWORD'), '/client/');
    for (const path of ['/client/tickets.php', '/client/my_approvals.php', '/client/compliance.php']) {
      const res = await page.goto(path);
      expect(res.status(), path).toBeLessThan(400);
      await expect(page, path).not.toHaveURL(/login/);
      await expectNoServerError(page);
    }
    expect(problems).toEqual([]);
  });

  test('a portal contact cannot open an agent page', async ({ page }) => {
    await signIn(page, need('SMOKE_CLIENT_EMAIL'), need('SMOKE_CLIENT_PASSWORD'), '/client/');
    const res = await page.goto('/agent/dashboard.php');
    const onAgentDashboard = res.status() < 400 && /\/agent\/dashboard\.php/.test(page.url()) && !(await page.locator('input[name="password"]').count());
    expect(onAgentDashboard, 'portal user reached the agent dashboard').toBe(false);
  });
});
