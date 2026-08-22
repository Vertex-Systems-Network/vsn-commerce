import crypto from 'node:crypto';
import path from 'node:path';
import { defineConfig, devices } from '@playwright/test';

const root = process.cwd();
const baseURL = process.env.E2E_BASE_URL || 'http://127.0.0.1:8010';
const defaultDb = path.join(root, 'database', 'e2e.sqlite');

// The config process, webServer and test workers inherit the same safe E2E environment.
process.env.APP_ENV = process.env.APP_ENV || 'e2e';
process.env.APP_DEBUG = process.env.APP_DEBUG || 'false';
process.env.APP_URL = process.env.APP_URL || baseURL;
process.env.APP_KEY = process.env.APP_KEY || `base64:${crypto.randomBytes(32).toString('base64')}`;
process.env.CACHE_STORE = process.env.CACHE_STORE || 'array';
process.env.SESSION_DRIVER = process.env.SESSION_DRIVER || 'file';
process.env.QUEUE_CONNECTION = process.env.QUEUE_CONNECTION || 'sync';
process.env.MAIL_MAILER = process.env.MAIL_MAILER || 'array';
process.env.VSN_DEMO_SEED_ENABLED = process.env.VSN_DEMO_SEED_ENABLED || 'true';
process.env.VSN_CARD_PAYMENT_PROVIDER = process.env.VSN_CARD_PAYMENT_PROVIDER || 'sandbox';
process.env.VSN_CARD_PAYMENTS_ENABLED = process.env.VSN_CARD_PAYMENTS_ENABLED || 'true';
process.env.VSN_SANDBOX_PAYMENT_SIMULATOR_ENABLED = process.env.VSN_SANDBOX_PAYMENT_SIMULATOR_ENABLED || 'true';
process.env.VSN_STANDARD_SHIPPING_PROVIDER = process.env.VSN_STANDARD_SHIPPING_PROVIDER || 'sandbox';
process.env.VSN_EXPRESS_SHIPPING_PROVIDER = process.env.VSN_EXPRESS_SHIPPING_PROVIDER || 'sandbox';
process.env.VSN_SANDBOX_SHIPPING_SIMULATOR_ENABLED = process.env.VSN_SANDBOX_SHIPPING_SIMULATOR_ENABLED || 'true';
process.env.VSN_KYC_PROVIDER = process.env.VSN_KYC_PROVIDER || 'manual';
process.env.VSN_SMS_PROVIDER = process.env.VSN_SMS_PROVIDER || 'sandbox';
process.env.VSN_SANDBOX_SMS_ENABLED = process.env.VSN_SANDBOX_SMS_ENABLED || 'true';
process.env.DB_CONNECTION = process.env.E2E_DB_CONNECTION || 'sqlite';
process.env.DB_DATABASE = process.env.E2E_DB_DATABASE || defaultDb;

export default defineConfig({
  testDir: './e2e',
  outputDir: 'runtime-artifacts/playwright-results',
  timeout: 45_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  workers: 1,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI
    ? [['dot'], ['html', { outputFolder: 'playwright-report', open: 'never' }], ['junit', { outputFile: 'runtime-artifacts/playwright-junit.xml' }]]
    : [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],
  use: {
    baseURL,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    actionTimeout: 10_000,
    navigationTimeout: 20_000,
  },
  webServer: process.env.E2E_EXTERNAL_SERVER === '1' ? undefined : {
    command: 'php scripts/e2e-server.php',
    url: `${baseURL}/up`,
    reuseExistingServer: !process.env.CI,
    timeout: 180_000,
    stdout: 'pipe',
    stderr: 'pipe',
  },
  projects: [
    {
      name: 'chromium',
      grepInvert: /@mobile|@crossbrowser/,
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'mobile-chromium',
      grep: /@mobile/,
      use: { ...devices['Pixel 7'] },
    },
    {
      name: 'firefox-smoke',
      grep: /@crossbrowser/,
      use: { ...devices['Desktop Firefox'] },
    },
    {
      name: 'webkit-smoke',
      grep: /@crossbrowser/,
      use: { ...devices['Desktop Safari'] },
    },
  ],
});
