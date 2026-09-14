function hasControlCharacters(value) {
  for (let index = 0; index < value.length; index += 1) {
    const code = value.charCodeAt(index);
    if (code <= 0x1f || code === 0x7f) return true;
  }
  return false;
}

/** Returns a same-origin application path or the validated fallback. */
export function safeLocalRedirect(value, fallback = '/dashboard') {
  const safeFallback = validatedLocalPath(fallback) || '/dashboard';
  return validatedLocalPath(value) || safeFallback;
}

function validatedLocalPath(value) {
  if (typeof value !== 'string') return null;
  const candidate = value.trim();
  if (
    candidate === ''
    || !candidate.startsWith('/')
    || candidate.startsWith('//')
    || candidate.includes('\\')
    || hasControlCharacters(candidate)
  ) return null;

  try {
    const parsed = new URL(candidate, 'https://vsn.invalid');
    if (parsed.origin !== 'https://vsn.invalid') return null;
    const normalized = `${parsed.pathname}${parsed.search}${parsed.hash}`;
    if (!normalized.startsWith('/') || normalized.startsWith('//') || normalized.includes('\\')) return null;
    return normalized;
  } catch {
    return null;
  }
}

/** Removes an unsafe next parameter before the router consumes the current URL. */
export function sanitizeRedirectSearch(search) {
  const params = new URLSearchParams(search || '');
  if (!params.has('next')) return search || '';

  const requested = params.get('next');
  if (safeLocalRedirect(requested, '') !== requested) {
    params.delete('next');
  }

  const encoded = params.toString();
  return encoded ? `?${encoded}` : '';
}
