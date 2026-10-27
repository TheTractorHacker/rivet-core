# Edition browser smoke tests (issue #37)

The editions own the UI, so the browser suite lives with them in spirit; this directory is the shared scaffold plus a RivetIT-ready
spec that can be run against a scratch RivetIT instance. It is deliberately outside the Composer package (`tests/` is
`export-ignore`d) and has its own `package.json`.

**Status, stated plainly:** the specs were written against RivetIT's routes and login form and have only been syntax-checked
(`npm run check`, which is `node --check` on each file). They have NOT been run against any live instance, so the first real run will
probably need a selector or two adjusted. Treat a first failure as "fix the spec", not "fix the product".

## Run it

```bash
cd tests/smoke
npm install                       # installs @playwright/test
npx playwright install chromium   # one-off browser download

export SMOKE_BASE_URL=https://scratch.example.test
export SMOKE_I_AM_ON_SCRATCH=1                       # required: the suite creates a ticket
export SMOKE_AGENT_EMAIL=smoke-agent@example.test
export SMOKE_AGENT_PASSWORD='...'
# optional
export SMOKE_CLIENT_EMAIL=smoke-contact@example.test SMOKE_CLIENT_PASSWORD='...'   # enables the portal and approvals tests
export SMOKE_IGNORE_TLS=1                                                          # self-signed scratch certificate
export SMOKE_BLOCKED_HOSTS=rivet.example.com,itflow.example.com                    # hosts that must never be tested

npm test
```

## What RivetIT's spec covers

Login form renders; a wrong password does not sign in; an agent signs in; the dashboard, ticket list, problems, changes and service
catalog approvals pages load with no server error and no HTTP 5xx; a ticket can be created, appears in the list and in global
search; sign out ends the session; and, when a portal contact is configured, the portal contact reaches tickets, **my approvals** and
compliance, and cannot open an agent page.

## Safety rails

- `SMOKE_I_AM_ON_SCRATCH=1` must be set explicitly, and `SMOKE_BLOCKED_HOSTS` lets CI refuse known production host names.
- Use accounts that exist only on the scratch instance. Never put a real password in a file; pass it through the CI secret store.
- MFA accounts: the suite does not drive TOTP. Use a smoke account without MFA on the scratch instance (or add `otplib` and fill the
  code field where `signIn()` waits for the next page).

## Another edition

Copy `edition-template.smoke.spec.js`, set `SMOKE_TEMPLATE_PAGES` or replace it with the edition's flows. The helper functions and the
env-var contract (`SMOKE_*`) stay the same, so one CI job can run any edition.

## Running it in CI

RivetIT's own repository is the right home for the job (it owns the instance and the data). A GitHub Actions sketch:

```yaml
smoke:
  runs-on: ubuntu-latest
  steps:
    - uses: actions/checkout@v7
    # ... bring up RivetIT on a scratch database (docker compose) and wait for /health ...
    - uses: actions/setup-node@v4
      with: { node-version: 22 }
    - run: cd tests/smoke && npm ci && npx playwright install --with-deps chromium && npm test
      env:
        SMOKE_BASE_URL: http://localhost:8080
        SMOKE_I_AM_ON_SCRATCH: '1'
        SMOKE_AGENT_EMAIL: ${{ secrets.SMOKE_AGENT_EMAIL }}
        SMOKE_AGENT_PASSWORD: ${{ secrets.SMOKE_AGENT_PASSWORD }}
```
