import fs from 'node:fs';
import path from 'node:path';

const apiKey=(process.env.WAVE_API_KEY||'').trim();
const baseUrl=(process.env.WAVE_BASE_URL||'').trim().replace(/\/+$/,'');
const required=process.env.WAVE_REQUIRED==='1';
const configuredRoutes=(process.env.WAVE_ROUTES||'').split(',').map(value=>value.trim()).filter(Boolean);
const routes=configuredRoutes.length?configuredRoutes:['/','/search','/vendors','/login'];
const outputDir=path.resolve('runtime-artifacts');
const outputFile=path.join(outputDir,'wave-a11y.json');
fs.mkdirSync(outputDir,{recursive:true});

/** Writes auditable evidence and terminates with the requested process status. */
function finish(payload,exitCode=0){
  const result={schema:'vsn-wave-a11y-v1',generatedAt:new Date().toISOString(),...payload};
  fs.writeFileSync(outputFile,JSON.stringify(result,null,2)+'\n','utf8');
  console.log(JSON.stringify(result,null,2));
  process.exit(exitCode);
}

if(!apiKey||!baseUrl){
  const missing=[!apiKey?'WAVE_API_KEY':null,!baseUrl?'WAVE_BASE_URL':null].filter(Boolean);
  finish({passed:false,skipped:true,reason:`WAVE audit not configured: missing ${missing.join(' and ')}`,routes},required?2:0);
}

let parsedBase;
try {
  parsedBase=new URL(baseUrl);
  if(!['http:','https:'].includes(parsedBase.protocol)) throw new Error('WAVE_BASE_URL must use http or https');
} catch(error) {
  finish({passed:false,skipped:false,reason:`Invalid WAVE_BASE_URL: ${error.message}`,routes},2);
}

const results=[];
for(const route of routes){
  const target=new URL(route,`${parsedBase.origin}${parsedBase.pathname.replace(/\/$/,'')}/`).toString();
  const requestUrl=new URL('https://wave.webaim.org/api/request');
  requestUrl.searchParams.set('key',apiKey);
  requestUrl.searchParams.set('url',target);
  requestUrl.searchParams.set('format','json');
  requestUrl.searchParams.set('reporttype','1');

  try {
    const response=await fetch(requestUrl,{headers:{accept:'application/json'}});
    const body=await response.json().catch(()=>null);
    const waveSuccess=Boolean(body?.status?.success);
    const hostStatus=Number(body?.status?.httpstatuscode||0);
    const errors=Number(body?.categories?.error?.count||0);
    const contrast=Number(body?.categories?.contrast?.count||0);
    const alerts=Number(body?.categories?.alert?.count||0);
    const passed=response.ok&&waveSuccess&&hostStatus>0&&hostStatus<400&&errors===0&&contrast===0;
    results.push({
      route,
      target,
      passed,
      waveRequestStatus:response.status,
      hostStatus,
      errors,
      contrastErrors:contrast,
      alerts,
      aimScore:body?.statistics?.AIMscore??null,
      reportUrl:body?.statistics?.waveurl??null,
      message:body?.status?.message??null,
    });
  } catch(error) {
    results.push({route,target,passed:false,error:error.message});
  }
}

const passed=results.every(result=>result.passed);
finish({passed,skipped:false,baseUrl:parsedBase.toString(),routes,results},passed?0:1);
