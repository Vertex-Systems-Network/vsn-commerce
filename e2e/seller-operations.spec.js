import { test, expect, ACCOUNTS, login } from './helpers/fixtures.js';

test('seller processes a paid order into a shipment', /** Inline callback for this operation. */ async ({ page }) => {
  await login(page, ACCOUNTS.seller, '/vendor');
  await page.goto('/vendor/shipping');
  await expect(page.getByRole('heading', { name: 'Shipping' })).toBeVisible();

  const processing = page.locator('.simple-list > div').filter({ hasText: /processing.*paid|paid.*processing/i }).first();
  await expect(processing).toBeVisible();
  const pack = processing.getByRole('button', { name: 'Pack' });
  if (await pack.count()) await pack.click();
  await expect(page.getByText('Order marked packed.')).toBeVisible();

  const fulfillable = page.locator('.simple-list > div').filter({ hasText: /processing|packed|ready to ship/i }).first();
  await fulfillable.getByRole('button', { name: 'Create shipment' }).click();
  await expect(page.getByText('Shipment label created.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Ready for pickup' }).first()).toBeVisible();
});

test('seller submits return feedback without approving the refund', /** Inline callback for this operation. */ async ({ page }) => {
  await login(page, ACCOUNTS.seller, '/vendor');
  await page.goto('/vendor/returns');
  await expect(page.getByRole('heading', { name: 'Returns' })).toBeVisible();
  const card = page.locator('.return-list .ui-card').first();
  await expect(card).toContainText('not_as_expected');
  await card.getByLabel('Recommendation').selectOption('accept');
  await card.getByLabel('Seller note').fill('E2E seller accepts the return for marketplace inspection.');
  await card.getByRole('button', { name: 'Save feedback' }).click();
  await expect(page.getByText('Seller feedback saved for marketplace review.')).toBeVisible();
});

test('seller can update store settings', /** Inline callback for this operation. */ async ({ page }) => {
  await login(page, ACCOUNTS.seller, '/vendor');
  await page.goto('/vendor/settings');
  await page.getByLabel('Support phone').fill('+92 300 1112233');
  await page.getByRole('button', { name: 'Save settings' }).click();
  await expect(page.getByText('Store settings saved.')).toBeVisible();
});
