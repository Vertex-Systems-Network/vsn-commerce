import {test,expect,loginAs} from './helpers/fixtures.js';

/** Finds obvious dead/unnamed interactive controls that should never ship. */
async function assertInteractiveContract(page,label){
  const issues=await page.locator('body').evaluate(()=>{
    const visible=node=>{
      const style=getComputedStyle(node);
      const rect=node.getBoundingClientRect();
      return style.display!=='none'&&style.visibility!=='hidden'&&rect.width>0&&rect.height>0;
    };
    const controls=[...document.querySelectorAll('button,a[href],[role="button"]')].filter(visible);
    const unnamed=controls.filter(node=>{
      const name=(node.getAttribute('aria-label')||node.getAttribute('title')||node.innerText||node.textContent||'').trim();
      const imageAlt=[...node.querySelectorAll('img[alt]')].map(img=>img.getAttribute('alt')||'').join(' ').trim();
      return !name&&!imageAlt;
    }).map(node=>node.outerHTML.slice(0,220));
    const deadLinks=[...document.querySelectorAll('a[href]')].filter(visible).filter(node=>{
      const href=(node.getAttribute('href')||'').trim();
      return href===''||href==='#'||href.toLowerCase().startsWith('javascript:');
    }).map(node=>node.outerHTML.slice(0,220));
    return {unnamed,deadLinks};
  });
  expect(issues.unnamed,`${label}: unnamed visible interactive controls`).toEqual([]);
  expect(issues.deadLinks,`${label}: placeholder/dead links`).toEqual([]);
}

/** Clicks every currently available workspace navigation link and verifies a live route renders. */
async function clickWorkspaceNavigation(page,home,sidebarSelector,contentSelector='#main-content'){
  await page.goto(home);
  const hrefs=await page.locator(`${sidebarSelector} a[href]`).evaluateAll(nodes=>[...new Set(nodes.map(node=>node.getAttribute('href')).filter(href=>href&&href.startsWith('/')))]);
  expect(hrefs.length,`${home} should expose workspace navigation`).toBeGreaterThan(0);

  for(const href of hrefs){
    await page.goto(home);
    const link=page.locator(`${sidebarSelector} a[href="${href}"]`).first();
    await expect(link,`Navigation link ${href} should remain available`).toBeVisible();
    await link.click();
    await expect.poll(()=>new URL(page.url()).pathname,{message:`Clicking ${href} should navigate to the intended route`}).toBe(href);
    await expect(page.locator(contentSelector)).toBeVisible();
    await expect(page.locator('body')).not.toContainText(/page not found|404 not found/i);
    await assertInteractiveContract(page,href);
  }
}

test.describe('interaction quality and click safety',()=>{
  test('keyboard user can skip repeated storefront navigation',async({page})=>{
    await page.goto('/');
    await page.keyboard.press('Tab');
    const skip=page.getByRole('link',{name:'Skip to main content'});
    await expect(skip).toBeFocused();
    await expect(skip).toBeVisible();
    await page.keyboard.press('Enter');
    await expect(page.locator('#main-content')).toBeFocused();
  });

  test('public shell primary navigation and search clicks work',async({page})=>{
    await page.goto('/');
    await assertInteractiveContract(page,'public home');

    await page.getByRole('link',{name:'All stores'}).first().click();
    await expect(page).toHaveURL(/\/vendors$/);
    await expect(page.locator('#main-content')).toBeVisible();

    await page.goto('/');
    await page.getByLabel('Search catalog').fill('phone');
    await page.getByRole('button',{name:'Search',exact:true}).click();
    await expect(page).toHaveURL(/\/search\?q=phone$/);
    await expect(page.locator('#main-content')).toBeVisible();
  });

  test('customer workspace navigation clicks render without runtime errors',async({page})=>{
    test.setTimeout(120_000);
    await loginAs(page,'customer');
    await clickWorkspaceNavigation(page,'/account','.account-sidebar','#account-content');
  });

  test('seller workspace navigation clicks render without runtime errors',async({page})=>{
    test.setTimeout(150_000);
    await loginAs(page,'seller');
    await clickWorkspaceNavigation(page,'/vendor','.vendor-sidebar');
  });

  test('admin workspace navigation clicks render without runtime errors',async({page})=>{
    test.setTimeout(210_000);
    await loginAs(page,'superAdmin');
    await clickWorkspaceNavigation(page,'/admin','.admin-sidebar');
  });
});
