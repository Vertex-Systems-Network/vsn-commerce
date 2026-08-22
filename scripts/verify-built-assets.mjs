import fs from 'node:fs';
import path from 'node:path';

/** Fail the built-asset verification with an actionable message. */
function fail(message) {
  console.error(`Built asset verification: FAIL - ${message}`);
  process.exit(1);
}

const root = process.cwd();
const build = path.join(root, 'public', 'build');
const manifest = path.join(build, 'manifest.json');
if (!fs.existsSync(manifest)) fail('public/build/manifest.json is missing. Run npm run build.');

const parsed = JSON.parse(fs.readFileSync(manifest, 'utf8'));
const entry = parsed['resources/js/main.jsx'];
if (!entry?.file) fail('Vite manifest does not contain resources/js/main.jsx.');

const assetsDir = path.join(build, 'assets');
if (!fs.existsSync(assetsDir)) fail('public/build/assets is missing.');

let jsCount = 0;
let bareAssetReferences = 0;
for (const name of fs.readdirSync(assetsDir)) {
  if (!name.endsWith('.js')) continue;
  jsCount++;
  const source = fs.readFileSync(path.join(assetsDir, name), 'utf8');
  // Production chunks are served from /build/assets. A bare /assets URL is the
  // exact regression that caused lazy routes such as /login to fail after build.
  if (/(["'`])\/assets\//.test(source)) bareAssetReferences++;
}

if (jsCount === 0) fail('No built JavaScript chunks were found.');
if (bareAssetReferences > 0) fail(`${bareAssetReferences} built chunks still contain bare /assets/ URLs.`);

console.log(`Built asset verification: PASS (${jsCount} JS chunks, 0 bare /assets/ URLs)`);
