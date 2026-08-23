import { test, expect, ACCOUNTS, loginAs } from './helpers/fixtures.js';

/** Verifies the current mobile document does not overflow the viewport. */
async function expectNoPageOverflow(page){
  const overflow=await page.evaluate(()=>document.documentElement.scrollWidth-document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(2);
}

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
  const accountNav=page.getByRole('complementary',{name:'Account navigation'});
  await expect(accountNav).toBeVisible();
  await accountNav.getByRole('link',{name:'Orders',exact:true}).click();
  await expect(page.getByRole('heading', { name: /orders/i }).first()).toBeVisible();
  await expectNoPageOverflow(page);
});

test('@mobile seller can open the seller drawer and navigate without page overflow',async({page})=>{
  await loginAs(page,'seller');
  await page.getByRole('button',{name:'Open seller navigation'}).click();
  const sellerNav=page.getByRole('complementary',{name:'Seller navigation'});
  await expect(sellerNav).toBeVisible();
  await sellerNav.getByRole('link',{name:'Media Library',exact:true}).click();
  await expect(page).toHaveURL(/\/vendor\/media$/);
  await expect(page.locator('#main-content')).toBeVisible();
  await expectNoPageOverflow(page);
});

test('@mobile admin can open the admin drawer and navigate without page overflow',async({page})=>{
  await loginAs(page,'superAdmin');
  await page.getByRole('button',{name:'Open navigation'}).click();
  const adminNav=page.getByRole('complementary',{name:'Admin navigation'});
  await expect(adminNav).toBeVisible();
  await adminNav.getByRole('link',{name:'Catalog',exact:true}).click();
  await expect(page).toHaveURL(/\/admin\/catalog$/);
  await expect(page.locator('#main-content')).toBeVisible();
  await expectNoPageOverflow(page);
});
