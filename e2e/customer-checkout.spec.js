import { test, expect, ACCOUNTS, login } from './helpers/fixtures.js';

test('customer completes COD checkout from product to order confirmation', /** Inline callback for this operation. */ async ({ page }) => {
  await login(page, ACCOUNTS.customer, '/account');
  await page.goto('/product/iphone-16-pro-max-titanium');
  await expect(page.getByRole('heading', { name: /iPhone 16 Pro Max Titanium/i })).toBeVisible();
  await page.getByRole('button', { name: 'Add to cart', exact: true }).click();
  await page.goto('/cart');
  await expect(page.getByRole('heading', { name: 'Your cart' })).toBeVisible();
  await expect(page.getByText('iPhone 16 Pro Max Titanium')).toBeVisible();
  await page.getByRole('link', { name: 'Proceed to checkout' }).click();

  await expect(page.getByRole('heading', { name: 'Delivery address' })).toBeVisible();
  const address = page.locator('input[name="address"]').first();
  if (!await address.isChecked()) await address.check();
  await page.getByRole('button', { name: 'Continue' }).click();

  await expect(page.getByRole('heading', { name: 'Delivery option' })).toBeVisible();
  const shipping = page.locator('input[name="ship"]').first();
  if (!await shipping.isChecked()) await shipping.check();
  await page.getByRole('button', { name: 'Continue' }).click();

  await expect(page.getByRole('heading', { name: 'Payment & adjustments' })).toBeVisible();
  const codRow = page.locator('label.choice-row').filter({ hasText: /Cash on Delivery|COD/i }).first();
  await codRow.locator('input[name="pay"]').check();
  await page.getByRole('button', { name: 'Reserve stock & review' }).click();

  await expect(page.getByRole('heading', { name: 'Review marketplace order' })).toBeVisible();
  await page.getByRole('button', { name: 'Place order' }).click();
  await expect(page.getByRole('heading', { name: 'Thank you for your order' })).toBeVisible();
  await expect(page.getByText(/split by seller/i)).toBeVisible();
});

test('@crossbrowser public storefront and login render without runtime errors', /** Inline callback for this operation. */ async ({ page }) => {
  await page.goto('/');
  await expect(page.getByRole('heading', { name: /Big marketplace choice/i })).toBeVisible();
  await page.goto('/login');
  await expect(page.getByRole('heading', { name: /Sign in to VSN Ecommerce/i })).toBeVisible();
});
