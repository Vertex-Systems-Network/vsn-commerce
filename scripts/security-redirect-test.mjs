import assert from 'node:assert/strict';
import fs from 'node:fs';
import {safeLocalRedirect} from '../resources/js/platform/navigation.js';

assert.equal(safeLocalRedirect('/dashboard'), '/dashboard');
assert.equal(safeLocalRedirect('/orders?tab=open#latest'), '/orders?tab=open#latest');

for (const candidate of [
  null,
  '',
  'https://attacker.example/phish',
  '//attacker.example/phish',
  '/\\attacker.example/phish',
  'javascript:alert(1)',
  '/dashboard\r\nhttps://attacker.example',
]) {
  assert.equal(safeLocalRedirect(candidate), null, `unsafe redirect accepted: ${String(candidate)}`);
}

const bootstrap = fs.readFileSync('resources/js/main.jsx', 'utf8');
assert.match(bootstrap, /safeLocalRedirect\(requestedNext\)/, 'auth bootstrap must sanitize next before React mounts');
assert.match(bootstrap, /searchParams\.delete\(['"]next['"]\)/, 'unsafe next must be removed from the browser URL');

console.log('Auth redirect security regression tests passed.');
