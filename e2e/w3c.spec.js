import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {createRequire} from 'node:module';
import {test,expect,loginAs} from './helpers/fixtures.js';

const require=createRequire(import.meta.url);
const {vnu}=require('vnu-jar');

/** Validates one rendered React document with the Nu HTML Checker. */
async function validateRenderedHtml(page,route,label){
  await page.goto(route);
  await page.locator('body').waitFor({state:'visible'});
  const routeState=page.locator('.route-state');
  if(await routeState.count())await expect(routeState).toHaveCount(0);
  const html=await page.content();
  const tempDir=fs.mkdtempSync(path.join(os.tmpdir(),'vsn-w3c-'));
  const file=path.join(tempDir,`${label.replace(/[^a-z0-9]+/gi,'-')||'page'}.html`);
  fs.writeFileSync(file,html,'utf8');
  try {
    let output='';
    try {
      output=String(await vnu.check(['--errors-only',file])||'').trim();
    } catch(error) {
      output=String(error?.message||error).trim();
    }
    expect(output,`W3C Nu HTML errors on ${route}`).toBe('');
  } finally {
    fs.rmSync(tempDir,{recursive:true,force:true});
  }
}

test.describe('W3C rendered HTML conformance',()=>{
  test('public shell and authentication HTML are conforming',async({page})=>{
    await validateRenderedHtml(page,'/','home');
    await validateRenderedHtml(page,'/login','login');
    await validateRenderedHtml(page,'/search','search');
  });

  test('authenticated workspace shells produce conforming HTML',async({page})=>{
    await loginAs(page,'customer');
    await validateRenderedHtml(page,'/account','account');
  });

  test('seller workspace produces conforming HTML',async({page})=>{
    await loginAs(page,'seller');
    await validateRenderedHtml(page,'/vendor','seller');
  });

  test('admin workspace produces conforming HTML',async({page})=>{
    await loginAs(page,'superAdmin');
    await validateRenderedHtml(page,'/admin','admin');
  });
});
