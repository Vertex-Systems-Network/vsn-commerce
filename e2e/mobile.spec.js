import { test, expect, ACCOUNTS, login } from './helpers/fixtures.js';

test('@mobile customer can use storefront and account drawers on mobile', /** Inline callback for this operation. */ async ({ page }) => {
  await page.goto('/');
  await page.getByRole('button', { name: 'Open menu' }).click();
  await expect(page.getByLabel('Mobile navigation')).toHaveClass(/open/);
  await page.getByRole('link', { name: 'Sign in' }).click();
  await page.getByLabel('Email address').fill(ACCOUNTS.customer);
  await page.getByLabel('Password').fill('ChangeMe12345');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page).toHaveURL(/\/account/);
  await page.getByRole('button', { name: 'Open account navigation' }).click();
  await expect(page.getByRole('link', { name: 'Orders' }).last()).toBeVisible();
  await page.getByRole('link', { name: 'Orders' }).last().click();
  await expect(page.getByRole('heading', { name: /orders/i }).first()).toBeVisible();
  const overflow = await page.evaluate(/** Inline callback for this operation. */ () => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(2);
});
