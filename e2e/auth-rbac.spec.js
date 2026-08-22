import { test, expect, ACCOUNTS, login } from './helpers/fixtures.js';

const cases = [
  ['customer', ACCOUNTS.customer, '/account', /Welcome back/i],
  ['seller', ACCOUNTS.seller, '/vendor', /TechZone PK/i],
  ['support', ACCOUNTS.support, '/admin', 'Admin Control Center'],
  ['moderator', ACCOUNTS.moderator, '/admin', 'Admin Control Center'],
  ['finance', ACCOUNTS.finance, '/admin', 'Admin Control Center'],
  ['admin', ACCOUNTS.admin, '/admin', 'Admin Control Center'],
  ['super admin', ACCOUNTS.superAdmin, '/admin', 'Admin Control Center'],
];

for (const [label, email, path, heading] of cases) {
  test(`@smoke ${label} signs in and lands in the correct area`, /** Inline callback for this operation. */ async ({ page }) => {
    await login(page, email, path);
    await expect(page.getByRole('heading', { name: heading })).toBeVisible();
  });
}

test('customer cannot open admin or seller areas', /** Inline callback for this operation. */ async ({ page }) => {
  await login(page, ACCOUNTS.customer, '/account');
  await page.goto('/admin');
  await expect(page).toHaveURL(/\/access-denied|\/account/);
  await page.goto('/vendor');
  await expect(page).toHaveURL(/\/access-denied|\/account/);
});

test('support is read-only for order operations', /** Inline callback for this operation. */ async ({ page }) => {
  await login(page, ACCOUNTS.support, '/admin');
  await page.goto('/admin/orders');
  await expect(page.getByRole('heading', { name: 'Orders' })).toBeVisible();
  await page.getByRole('link', { name: 'Open' }).first().click();
  await expect(page.getByText('Order operation')).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Confirm COD collected' })).toHaveCount(0);
});
