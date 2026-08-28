import fs from 'node:fs';
import path from 'node:path';

const sourceRoot = 'resources/js';
const sourceFiles = [];
const pending = [sourceRoot];

while (pending.length) {
  const directory = pending.pop();
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const file = path.join(directory, entry.name);
    if (entry.isDirectory()) pending.push(file);
    else if (/\.(js|jsx|ts|tsx)$/.test(entry.name)) sourceFiles.push(file);
  }
}

const violations = [];
const record = (file, rule) => {
  const normalized = file.split(path.sep).join('/');
  const key = `${normalized} :: ${rule}`;
  if (!violations.includes(key)) violations.push(key);
};

const retiredAuthorityFiles = [
  'resources/js/platform/store.jsx',
  'resources/js/data/catalog.js',
];

for (const file of retiredAuthorityFiles) {
  if (fs.existsSync(file)) record(file, 'legacy authority file still exists');
}

const rules = [
  ['StoreProvider symbol', /\bStoreProvider\b/],
  ['useStore symbol', /\buseStore\b/],
  ['apiBackend mode symbol', /\bapiBackend\b/],
  ['legacy platform/store import', /(?:from\s+|import\s*\()\s*['"][^'"]*platform\/store(?:\.jsx)?['"]/],
  ['legacy data/catalog import', /(?:from\s+|import\s*\()\s*['"][^'"]*data\/catalog(?:\.js)?['"]/],
];

for (const file of sourceFiles) {
  const text = fs.readFileSync(file, 'utf8');
  for (const [label, pattern] of rules) {
    if (pattern.test(text)) record(file, label);
  }
}

if (violations.length) {
  console.error(`Legacy client-authority inventory found ${violations.length} violation(s):`);
  for (const violation of violations.sort()) console.error(`[FAIL] ${violation}`);
  process.exit(1);
}

console.log(`[PASS] Legacy StoreProvider/static-catalog/client-mode authority absent across ${sourceFiles.length} source files.`);
