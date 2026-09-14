import assert from 'node:assert/strict';
import {safeLocalRedirect, sanitizeRedirectSearch} from '../resources/js/platform/navigation.js';

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
  assert.equal(safeLocalRedirect(candidate), '/dashboard', `unsafe redirect accepted: ${String(candidate)}`);
}

assert.equal(sanitizeRedirectSearch('?auth=success&next=%2Forders'), '?auth=success&next=%2Forders');
assert.equal(sanitizeRedirectSearch('?auth=success&next=%2F%2Fevil.example'), '?auth=success');
assert.equal(sanitizeRedirectSearch('?next=https%3A%2F%2Fevil.example'), '');

console.log('Auth redirect security regression tests passed.');
