import { execFileSync } from 'node:child_process';
import { test as base, expect } from '@playwright/test';

export const DEMO_PASSWORD = 'ChangeMe12345';
export const ACCOUNTS = {
  customer: 'customer@example.test',
  seller: 'seller@example.test',
  support: 'support@example.test',
  moderator: 'moderator@example.test',
  finance: 'finance@example.test',
  admin: 'ops-admin@example.test',
  superAdmin: 'admin@example.test',
};

/** Documents reset database for this project module. */
function resetDatabase() {
  execFileSync(process.env.PHP_BINARY || 'php', ['scripts/e2e-reset.php'], {
    cwd: process.cwd(),
    env: process.env,
    stdio: process.env.E2E_DEBUG_RESET === '1' ? 'inherit' : 'pipe',
    timeout: 120_000,
  });
}

/** Returns true when a browser request belongs to the local E2E application. */
function isApplicationUrl(value) {
  try {
    const url = new URL(value);
    return ['127.0.0.1', 'localhost'].includes(url.hostname);
  } catch {
    return false;
  }
}

export const test = base.extend({
  dbReset: [/** Inline callback for this operation. */ async ({}, use) => {
    resetDatabase();
    await use(true);
  }, { auto: true }],
  page: /** Inline callback for this operation. */ async ({ page }, use) => {
    const runtimeErrors = [];
    page.on('pageerror', /** Inline callback for this operation. */ error => runtimeErrors.push(`pageerror: ${error.message}`));
    page.on('console', /** Inline callback for this operation. */ message => {
      if (message.type() === 'error' && !/favicon|ERR_BLOCKED_BY_CLIENT/i.test(message.text())) {
        runtimeErrors.push(`console.error: ${message.text()}`);
      }
    });
    page.on('requestfailed', /** Treats failed first-party network requests as interaction regressions. */ request => {
      if (!isApplicationUrl(request.url())) return;
      runtimeErrors.push(`requestfailed: ${request.method()} ${request.url()} — ${request.failure()?.errorText || 'unknown error'}`);
    });
    page.on('response', /** Treats first-party server errors as browser-flow failures. */ response => {
      if (!isApplicationUrl(response.url()) || response.status() < 500) return;
      runtimeErrors.push(`http ${response.status()}: ${response.request().method()} ${response.url()}`);
    });
    await page.route('**/*', /** Inline callback for this operation. */ async route => {
      const url = new URL(route.request().url());
      if (['127.0.0.1', 'localhost'].includes(url.hostname) || ['data:', 'blob:'].includes(url.protocol)) return route.continue();
      return route.abort('blockedbyclient');
    });
    await use(page);
    expect(runtimeErrors, `Browser runtime errors:\n${runtimeErrors.join('\n')}`).toEqual([]);
  },
});

export { expect };

/** Documents login for this project module. */
export async function login(page, email, expectedPath) {
  await page.goto('/login');
  await page.getByLabel('Email address').fill(email);
  await page.getByLabel('Password').fill(DEMO_PASSWORD);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page).toHaveURL(new RegExp(`${expectedPath.replaceAll('/', '\\/')}(?:$|\\?)`));
}

/** Documents login as for this project module. */
export async function loginAs(page, role) {
  const expected = role === 'customer' ? '/account' : role === 'seller' ? '/vendor' : '/admin';
  await login(page, ACCOUNTS[role], expected);
}
