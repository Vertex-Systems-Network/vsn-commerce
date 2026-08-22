# VSN Ecommerce Performance & Security Runbook

## Production runtime baseline

Use Redis for the Laravel cache, rate limiter and queue. Keep `APP_DEBUG=false`, encrypted sessions enabled and secure cookies enabled behind HTTPS. Configure trusted proxies at the deployment layer so Laravel sees the real HTTPS scheme and client IP only from infrastructure you control.

Recommended production settings are already documented in `.env.production.example`; provider credentials and production domains must remain environment-specific and are not hard-coded by the application.

## Performance telemetry

Milestone AV records request-level query and memory metrics and emits a structured `http.performance_budget_exceeded` warning when a configured budget is crossed.

Key environment settings:

```dotenv
VSN_QUERY_TELEMETRY_ENABLED=true
VSN_PERFORMANCE_REQUEST_BUDGET_MS=1500
VSN_PERFORMANCE_QUERY_BUDGET=80
VSN_PERFORMANCE_DUPLICATE_QUERY_BUDGET=8
VSN_PERFORMANCE_MEMORY_BUDGET_MB=96
VSN_EXPOSE_SERVER_TIMING=false
```

Do not expose `Server-Timing` in production unless deliberately required for diagnostics.

## Catalog caching

Categories, search dimensions, suggestions and trending queries use versioned cache keys. Product/category/stock/media mutations bump the catalogue version instead of relying on cache-tag support, so the same implementation works with file cache locally and Redis in production.

Typical TTL controls:

```dotenv
VSN_CATALOG_CACHE_SECONDS=120
VSN_SEARCH_SUGGESTION_CACHE_SECONDS=30
VSN_TRENDING_SEARCH_CACHE_SECONDS=60
```

## Rate limiting

AV separates traffic classes instead of applying one global budget to every endpoint:

- catalogue reads
- commerce writes
- uploads
- provider webhooks
- sensitive account/payment actions

Tune limits from environment variables based on production traffic and provider webhook burst behavior. Do not disable authentication/ownership checks just because an endpoint is rate-limited.

## Request and upload limits

Application defaults:

- ordinary API request body: 2 MB
- aggregate upload request: 50 MB
- individual uploaded file: 10 MB
- image maximum dimension: 12,000 px
- image maximum pixels: 50,000,000

Your web server and PHP limits must be at least as large as the application-level aggregate cap or the request will be rejected before Laravel can return its structured error. For example, set PHP `post_max_size` and the reverse-proxy request-body limit slightly above 50 MB while keeping the application limits authoritative. File validation still applies per file.

## Content Security Policy

Production CSP defaults deny third-party scripts/frames except resources needed by the current storefront. Stripe.js / Payment Element browser resources are explicitly allowed from Stripe browser domains used by the application. Review the CSP whenever a new browser-side payment, analytics or media provider is introduced; never solve CSP failures by changing `script-src` or `frame-src` to unrestricted wildcards.

## Database performance

Milestone AV adds composite indexes for high-frequency catalogue, order and moderation paths. Run the production readiness/index audit after every schema deploy.

For a slow query discovered through telemetry, reproduce it against a representative staging dataset and inspect `EXPLAIN`/`EXPLAIN ANALYZE` using the target database engine. Add an index only when the query plan and selectivity justify it; avoid speculative duplicate indexes.

## Release gate

```bash
composer audit:performance-security
php scripts/audit-mysql-migrations.php
php scripts/audit-database-portability.php
php scripts/audit-auth-admin-ui.php
php scripts/audit-test-suite.php
php artisan test
npm ci
npm run build
```

For MySQL release validation, use the dedicated test database and AR runtime preflight before running `composer test:mysql`.
