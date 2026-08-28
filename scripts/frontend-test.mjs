import fs from 'node:fs';
import path from 'node:path';

const failures = [];
const pass = /** Inline callback for this operation. */ (label) => console.log(`[PASS] ${label}`);
const fail = /** Inline callback for this operation. */ (label) => { failures.push(label); console.error(`[FAIL] ${label}`); };

const packageJson = JSON.parse(fs.readFileSync('package.json', 'utf8'));
const lockJson = JSON.parse(fs.readFileSync('package-lock.json', 'utf8'));
const rootLock = lockJson.packages?.[''] || {};
(JSON.stringify(packageJson.dependencies || {}) === JSON.stringify(rootLock.dependencies || {}) ? pass : fail)('package-lock dependencies align with package.json');
(JSON.stringify(packageJson.devDependencies || {}) === JSON.stringify(rootLock.devDependencies || {}) ? pass : fail)('package-lock devDependencies align with package.json');
(packageJson.scripts?.test?.includes('node scripts/frontend-test.mjs') && packageJson.scripts?.test?.includes('audit-routing-deployment.php') ? pass : fail)('npm test script is explicitly defined with routing audit');
(packageJson.scripts?.build?.startsWith('vite build') && packageJson.scripts?.build?.includes('verify-built-assets.mjs') ? pass : fail)('npm build script is explicitly defined with built-asset verification');
(packageJson.scripts?.['e2e:bootstrap']?.includes('@axe-core/playwright') && packageJson.scripts?.['e2e:bootstrap']?.includes('vnu-jar') ? pass : fail)('E2E bootstrap installs axe and W3C Nu tooling');
(packageJson.scripts?.['audit:wave']==='node scripts/wave-audit.mjs' ? pass : fail)('WAVE audit script is explicitly defined');

for (const required of ['resources/js/components/SkipLink.jsx','resources/js/accessibility.scss','e2e/accessibility.spec.js','e2e/w3c.spec.js','e2e/interaction-quality.spec.js','scripts/wave-audit.mjs','docs/UI-ACCESSIBILITY-QUALITY-PLAN.md']) {
  (fs.existsSync(required) ? pass : fail)(`UI quality file exists: ${required}`);
}

const sourceFiles = [];
const stack = ['resources/js'];
while (stack.length) {
  const directory = stack.pop();
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const file = path.join(directory, entry.name);
    if (entry.isDirectory()) stack.push(file);
    else if (/\.(js|jsx|ts|tsx)$/.test(entry.name)) sourceFiles.push(file);
  }
}

let relativeImports = 0;
let missingImports = 0;
let placeholderLinks = 0;
for (const file of sourceFiles) {
  const text = fs.readFileSync(file, 'utf8');
  for (const match of text.matchAll(/(?:from\s+|import\s*\()\s*['"](\.{1,2}\/[^'"]+)['"]/g)) {
    relativeImports++;
    const base = path.resolve(path.dirname(file), match[1]);
    const candidates = [base, ...['.js','.jsx','.ts','.tsx'].map(/** Inline callback for this operation. */ (extension) => base + extension), ...['index.js','index.jsx','index.ts','index.tsx'].map(/** Inline callback for this operation. */ (name) => path.join(base, name))];
    if (!candidates.some(/** Inline callback for this operation. */ (candidate) => fs.existsSync(candidate))) {
      missingImports++;
      console.error(`[FAIL] missing import ${file} -> ${match[1]}`);
    }
  }

  const deadPatterns = [
    /(?:href|to)\s*=\s*["']#["']/g,
    /(?:href|to)\s*=\s*["']["']/g,
    /href\s*=\s*["']javascript:/gi,
    /\bto\s*=\s*\{[^}]*\|\|\s*["']#["'][^}]*\}/g,
  ];
  for (const pattern of deadPatterns) {
    for (const match of text.matchAll(pattern)) {
      placeholderLinks++;
      console.error(`[FAIL] placeholder/dead navigation in ${file}: ${match[0]}`);
    }
  }
}
(missingImports === 0 ? pass : fail)(`frontend relative imports resolve (${relativeImports} checked)`);
(placeholderLinks === 0 ? pass : fail)(`frontend placeholder/dead navigation absent (${placeholderLinks})`);

const searchPage = fs.readFileSync('resources/js/pages/Search.jsx', 'utf8');
(!/(?:from\s+|import\s*\()\s*['"][^'"]*data\/catalog(?:\.js)?['"]/.test(searchPage) ? pass : fail)('Search live route does not import the legacy static catalog fixture');

const toolkit = fs.readFileSync('resources/js/components/Toolkit.jsx','utf8');
(!toolkit.includes("to||'#'") && toolkit.includes('onAction') ? pass : fail)('SectionHeader cannot create a fallback # link');
const shellFiles=['resources/js/layout/Shell.jsx','resources/js/layout/AdminShell.jsx','resources/js/layout/VendorShell.jsx','resources/js/layout/AccountShell.jsx'];
for(const file of shellFiles){
  const text=fs.readFileSync(file,'utf8');
  (text.includes('SkipLink') && text.includes('tabIndex="-1"') ? pass : fail)(`keyboard skip landmark present: ${file}`);
}

const forbiddenDirectories = ['.figma','wordpress','HOTFIX','backend'];
for (const directory of forbiddenDirectories) (!fs.existsSync(directory) ? pass : fail)(`forbidden directory absent: ${directory}`);

const historicalRoot = fs.readdirSync('.').filter(/** Inline callback for this operation. */ (name) => /^(MILESTONE-|VALIDATION-|SOURCE-)|HOTFIX|REBRAND/i.test(name));
(historicalRoot.length === 0 ? pass : fail)(`historical root artifacts absent (${historicalRoot.length})`);

const forbiddenTerms = [/Workforce Intelligence/i,/WorkspaceAccessSession/i,/VSN Builder/i,/Pella CRM/i,/Pella Force/i,/Pella Nova/i,/\bWordPress\b/i,/WooCommerce/i,/\/wp-json/i,/LegacyMigration/i];
let contamination = 0;
const scanRoots = ['app','bootstrap','config','database','routes','resources/js','tests'];
for (const root of scanRoots) {
  if (!fs.existsSync(root)) continue;
  const pending = [root];
  while (pending.length) {
    const item = pending.pop();
    for (const entry of fs.readdirSync(item, { withFileTypes: true })) {
      const file = path.join(item, entry.name);
      if (entry.isDirectory()) pending.push(file);
      else {
        const text = fs.readFileSync(file, 'utf8');
        for (const pattern of forbiddenTerms) if (pattern.test(text)) { contamination++; console.error(`[FAIL] contamination ${pattern} in ${file}`); }
      }
    }
  }
}
(contamination === 0 ? pass : fail)(`cross-project/legacy contamination absent (${contamination})`);

const credentials = fs.readFileSync('LOGIN-CREDENTIALS.md', 'utf8');
for (const email of ['admin@example.test','ops-admin@example.test','seller@example.test','customer@example.test']) (credentials.includes(email) ? pass : fail)(`login credential documented: ${email}`);
(credentials.includes('ChangeMe12345') ? pass : fail)('local demo password documented');

console.log(`Frontend source files checked: ${sourceFiles.length}`);
console.log(`npm test failures: ${failures.length}`);
process.exit(failures.length === 0 ? 0 : 1);
