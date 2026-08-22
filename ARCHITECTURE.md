# VSN Ecommerce Architecture — Unified Application

VSN Ecommerce is a **single Laravel + React application**.

## Repository structure

- Laravel/domain/backend code: `app/`, `routes/`, `database/`, `config/`
- React storefront/vendor/admin UI: `resources/js/`
- React shell: `resources/views/app.blade.php`
- Public web root: `public/`
- API: `/api/v1`
- Android/mobile lifecycle API: `/api/mobile/v1`
- Queue worker + scheduler: same application/image

There is no separate server subproject and no separately deployed frontend application.

## Request flow

```text
Browser
  ↓
Laravel public/index.php
  ├─ /api/v1/*        → Laravel APIs
  ├─ /api/mobile/v1/* → Android APIs
  └─ all UI routes    → Blade shell → React Router
```

React uses same-origin `/api/v1` calls by default (`VITE_VSN_API_BASE=`), so deployment requires one domain, one public root and one application release.

See `MERGED-APPLICATION.md` and `README.md` for setup/deployment.
