# Routing and deployment

VSN Ecommerce is one Laravel application with a React `BrowserRouter` frontend. Clean browser URLs such as `/login`, `/account/orders`, `/vendor/orders`, and `/admin/users` must first reach Laravel; Laravel then returns the same React shell for the client router.

## Apache / Laragon

Preferred virtual-host document root:

```text
<project>/public
```

The package contains `public/.htaccess`, which forwards every non-file/non-directory request to `public/index.php`. This is required for direct browser URLs, `/api/*`, and `/sanctum/*` routes under Apache.

A root `.htaccess` compatibility shim is also included for local Laragon hosts accidentally pointed at the repository root. Production hosts should still use the `public` directory as the document root.

Apache must have `mod_rewrite` enabled and `AllowOverride All` (or an equivalent configuration that permits the supplied rewrite rules).

## Vite build paths

The Vite production base is `/build/` because compiled output is written to `public/build`. This ensures lazy imports resolve as:

```text
/build/assets/<chunk>.js
```

and never as the invalid:

```text
/assets/<chunk>.js
```

`npm run build` now runs `scripts/verify-built-assets.mjs` after Vite and fails if any compiled JS chunk contains a bare `/assets/` URL.

## Same-origin API

The React API client defaults to the current origin:

```text
/api/v1/*
/sanctum/csrf-cookie
```

Do not set `VITE_VSN_API_BASE` unless the API is intentionally hosted on another origin.

## Local refresh after replacing an older package

```powershell
php artisan optimize:clear
npm ci
npm run build
```

Restart Apache/Laragon after replacing rewrite configuration if necessary. A stale `public/hot` file must not exist when using the production Vite build.

## Verification

Dependency-free routing source audit:

```powershell
php scripts/audit-routing-deployment.php
```

Frontend/source audit:

```powershell
npm test
```

Laravel runtime regression includes `tests/Feature/DirectUrlRoutingTest.php`, which loads all storefront/auth/account/vendor/admin direct entry URLs and verifies core API/Sanctum endpoints do not become web-server 404s.
