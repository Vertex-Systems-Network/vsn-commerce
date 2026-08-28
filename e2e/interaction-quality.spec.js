import {test,expect,loginAs} from './helpers/fixtures.js';

/** Finds obvious dead/unnamed interactive controls that should never ship. */
async function assertInteractiveContract(page,label){
  const issues=await page.locator('body').evaluate(()=>{
    const visible=node=>{
      const style=getComputedStyle(node);
      const rect=node.getBoundingClientRect();
      return style.display!=='none'&&style.visibility!=='hidden'&&rect.width>0&&rect.height>0;
    };
    const labelledByText=node=>{
      const ids=(node.getAttribute('aria-labelledby')||'').trim().split(/\s+/).filter(Boolean);
      return ids.map(id=>document.getElementById(id)?.textContent||'').join(' ').trim();
    };
    const controls=[...document.querySelectorAll('button,a[href],[role="button"]')].filter(visible);
    const unnamed=controls.filter(node=>{
      const name=(node.getAttribute('aria-label')||labelledByText(node)||node.getAttribute('title')||node.innerText||node.textContent||'').trim();
      const imageAlt=[...node.querySelectorAll('img[alt]')].map(img=>img.getAttribute('alt')||'').join(' ').trim();
      return !name&&!imageAlt;
    }).map(node=>node.outerHTML.slice(0,220));
    const fields=[...document.querySelectorAll('input:not([type="hidden"]),select,textarea')].filter(visible);
    const unlabeledFields=fields.filter(node=>{
      const labels=[...(node.labels||[])].map(label=>label.textContent||'').join(' ').trim();
      const name=(node.getAttribute('aria-label')||labelledByText(node)||node.getAttribute('title')||labels).trim();
      return !name;
    }).map(node=>node.outerHTML.slice(0,220));
    const safeProtocols=new Set(['http:','https:','mailto:','tel:']);
    const deadLinks=[...document.querySelectorAll('a[href]')].filter(visible).filter(node=>{
      const href=(node.getAttribute('href')||'').trim();
      if(href===''||href==='#') return true;
      try {
        return !safeProtocols.has(new URL(href,document.baseURI).protocol.toLowerCase());
      } catch {
        return true;
      }
    }).map(node=>node.outerHTML.slice(0,220));
    return {unnamed,unlabeledFields,deadLinks};
  });
  expect(issues.unnamed,`${label}: unnamed visible interactive controls`).toEqual([]);
  expect(issues.unlabeledFields,`${label}: visible form controls without an accessible label`).toEqual([]);
  expect(issues.deadLinks,`${label}: placeholder/dead links`).toEqual([]);
}

/** Clicks every currently available workspace navigation link and verifies a live route renders. */
async function clickWorkspaceNavigation(page,home,sidebarSelector,contentSelector='#main-content'){
  const sidebar=page.locator(sidebarSelector);
  await page.goto(home);
  await expect(sidebar,`${home} workspace navigation should finish loading`).toBeVisible();
  const hrefs=await sidebar.locator('a[href]').evaluateAll(nodes=>[...new Set(nodes.map(node=>node.getAttribute('href')).filter(href=>href&&href.startsWith('/')))]);
  expect(hrefs.length,`${home} should expose workspace navigation`).toBeGreaterThan(0);

  for(const href of hrefs){
    await page.goto(home);
    await expect(sidebar,`${home} workspace navigation should finish loading`).toBeVisible();
    const link=sidebar.locator(`a[href="${href}"]`).first();
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
    const skip=page.getByRole('link',{name:'Skip to main content'});
    await expect(skip).toHaveCount(1);
    await expect(page.locator('#main-content')).toBeVisible();
    await page.evaluate(()=>document.activeElement instanceof HTMLElement&&document.activeElement.blur());
    await page.keyboard.press('Tab');
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
    await expect.poll(()=>new URL(page.url()).pathname,{message:'Search action should navigate to the search route'}).toBe('/search');
    await expect.poll(()=>new URL(page.url()).searchParams.get('q'),{message:'Search action should preserve the submitted query'}).toBe('phone');
    await expect(page.locator('#main-content')).toBeVisible();
  });

  test('search API failure fails closed without legacy catalog content',async({page})=>{
    await page.addInitScript(()=>{
      const nativeFetch=window.fetch.bind(window);
      window.fetch=async(input,init)=>{
        const rawUrl=typeof input==='string'?input:input?.url||String(input);
        const url=new URL(rawUrl,window.location.origin);
        if(url.pathname==='/api/v1/products'){
          return new Response(JSON.stringify({message:'Catalog temporarily unavailable'}),{
            status:503,
            statusText:'Service Unavailable',
            headers:{'Content-Type':'application/json'},
          });
        }
        return nativeFetch(input,init);
      };
    });
    await page.goto('/search?q=phone');
    await expect(page.getByText('Search unavailable')).toBeVisible();
    await expect(page.getByText('Catalog temporarily unavailable')).toBeVisible();
    await expect(page.locator('.product-grid')).toHaveCount(0);
  });

  test('product API failure fails closed without legacy product content',async({page})=>{
    await page.addInitScript(()=>{
      const nativeFetch=window.fetch.bind(window);
      window.fetch=async(input,init)=>{
        const rawUrl=typeof input==='string'?input:input?.url||String(input);
        const url=new URL(rawUrl,window.location.origin);
        if(/^\/api\/v1\/products\/[^/]+$/.test(url.pathname)){
          return new Response(JSON.stringify({message:'Product temporarily unavailable'}),{
            status:503,
            statusText:'Service Unavailable',
            headers:{'Content-Type':'application/json'},
          });
        }
        return nativeFetch(input,init);
      };
    });
    await page.goto('/product/iphone-16-pro-max-titanium');
    await expect(page.getByText('Product temporarily unavailable')).toBeVisible();
    await expect(page.locator('.pdp-page')).toHaveCount(0);
    await expect(page.getByRole('heading',{name:/iPhone 16 Pro Max Titanium/i})).toHaveCount(0);
  });

  test('Systems tracking API failure fails closed without legacy shipment content',async({page})=>{
    await loginAs(page,'customer');
    await page.addInitScript(()=>{
      const nativeFetch=window.fetch.bind(window);
      window.fetch=async(input,init)=>{
        const rawUrl=typeof input==='string'?input:input?.url||String(input);
        const url=new URL(rawUrl,window.location.origin);
        if(url.pathname==='/api/v1/shipments'){
          return new Response(JSON.stringify({message:'Shipment tracking temporarily unavailable'}),{
            status:503,
            statusText:'Service Unavailable',
            headers:{'Content-Type':'application/json'},
          });
        }
        return nativeFetch(input,init);
      };
    });
    await page.goto('/tracking');
    await expect(page.getByRole('heading',{name:'Track order'})).toBeVisible();
    await expect(page.getByText('Shipment tracking temporarily unavailable')).toBeVisible();
    await expect(page.getByText('Legacy demo tracking')).toHaveCount(0);
    await expect(page.locator('.tracking-card')).toHaveCount(0);
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
