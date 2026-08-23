import { test, expect, ACCOUNTS, login } from './helpers/fixtures.js';

test('moderator resolves a reported review', /** Inline callback for this operation. */ async ({ page }) => {
  await login(page, ACCOUNTS.moderator, '/admin');
  await page.goto('/admin/reviews');
  await page.getByRole('button', { name: 'Reports' }).click();
  await expect(page.getByText('Seeded moderation queue example.')).toBeVisible();
  await page.getByRole('button', { name: 'Resolve', exact: true }).first().click();
  await expect(page.getByText('No pending reports')).toBeVisible();
});

test('finance marks an approved payout paid with provider reference', /** Inline callback for this operation. */ async ({ page }) => {
  await login(page, ACCOUNTS.finance, '/admin');
  await page.goto('/admin/payouts');
  await expect(page.getByRole('heading', { name: 'Payouts' })).toBeVisible();
  page.once('dialog', /** Inline callback for this operation. */ dialog => dialog.accept('E2E-PAYOUT-REF-001'));
  await page.getByRole('button', { name: 'Mark paid' }).first().click();
  await expect(page.getByText('Payout marked paid and ledger posted.')).toBeVisible();
  await expect(page.locator('tbody').getByText('paid').first()).toBeVisible();
});

test('admin approves and inspects a return using VSN Coins resolution', /** Inline callback for this operation. */ async ({ page }) => {
  await login(page, ACCOUNTS.admin, '/admin');
  await page.goto('/admin/returns');
  await expect(page.getByRole('heading', { name: 'Returns & refunds' })).toBeVisible();
  await page.getByRole('link', { name: 'Review', exact: true }).first().click();
  await page.getByLabel('Resolution').selectOption('coins');
  await page.getByRole('button', { name: 'Approve selected quantities' }).click();
  await expect(page.getByText('Return approved.')).toBeVisible();
  await page.getByLabel(/Received/).first().fill('1');
  await page.getByLabel('Accepted').first().fill('1');
  const restock=page.getByLabel('Return accepted qty to stock').first();
  if(await restock.isChecked())await restock.uncheck();
  await page.getByRole('button', { name: 'Complete inspection' }).click();
  await expect(page.getByText('Inspection saved and refund workflow advanced.')).toBeVisible();
  await expect(page.getByText(/Refund/i).first()).toBeVisible();
});

test('admin changes marketplace setting through UI', /** Inline callback for this operation. */ async ({ page }) => {
  await login(page, ACCOUNTS.admin, '/admin');
  await page.goto('/admin/settings');
  const field = page.getByLabel('Store name');
  await field.fill('VSN Ecommerce E2E');
  await page.getByRole('button', { name: 'Save store' }).click();
  await expect(page.getByText(/saved/i).first()).toBeVisible();
  await expect(field).toHaveValue('VSN Ecommerce E2E');
});
