import AxeBuilder from '@axe-core/playwright';
import {test,expect,loginAs} from './helpers/fixtures.js';

const tags=['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa'];

/** Formats only actionable high-impact axe findings for CI output. */
function summarize(violations){
  return violations.map(violation=>({
    id:violation.id,
    impact:violation.impact,
    help:violation.help,
    nodes:violation.nodes.slice(0,8).map(node=>({target:node.target,summary:node.failureSummary})),
  }));
}

/** Scans one fully rendered page for serious/critical WCAG regressions. */
async function scan(page,path){
  await page.goto(path);
  await page.locator('body').waitFor({state:'visible'});
  const results=await new AxeBuilder({page}).withTags(tags).analyze();
  const blocking=results.violations.filter(violation=>['serious','critical'].includes(violation.impact));
  expect(summarize(blocking),`Blocking accessibility violations on ${path}`).toEqual([]);
}

test.describe('WCAG 2.2 automated accessibility',()=>{
  test('public critical pages have no serious or critical axe violations',async({page})=>{
    for(const path of ['/','/search','/vendors','/login']) await scan(page,path);
  });

  test('customer account critical pages have no serious or critical axe violations',async({page})=>{
    await loginAs(page,'customer');
    for(const path of ['/account','/account/orders','/account/wallet','/account/security']) await scan(page,path);
  });

  test('seller critical pages have no serious or critical axe violations',async({page})=>{
    await loginAs(page,'seller');
    for(const path of ['/vendor','/vendor/products','/vendor/media','/vendor/settings']) await scan(page,path);
  });

  test('admin critical pages have no serious or critical axe violations',async({page})=>{
    await loginAs(page,'superAdmin');
    for(const path of ['/admin','/admin/users','/admin/catalog','/admin/settings','/admin/production-readiness']) await scan(page,path);
  });
});
