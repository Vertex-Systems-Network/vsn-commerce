# VSN Ecommerce — RBAC & Demo Environment

Milestone AP centralizes effective access control in `config/rbac.php` and `App\Security\Rbac`.

## Authorization model

The authenticated `/api/v1/auth/me` response includes `permissions`. React route guards, Admin navigation and backend `area.role` middleware use the same effective permission vocabulary. Controller-level ownership/financial checks remain as defense in depth.

Core staff roles:
- Support: read orders, shipping and reviews.
- Moderator: review moderation and KYC review.
- Finance: payments, finance, payouts, tax, analytics and order read/COD finance actions.
- Admin: marketplace operations excluding legacy migration and production acceptance.
- Super Admin: every published permission.
- Seller: seller-owner operational permissions.
- Seller Staff: intentionally does **not** inherit seller-owner permissions until a vendor staff membership model exists.

Admin can inspect the effective matrix at `/admin/access`.

## Demo data safety

Local/testing environments enable demo seed data by default. Production does not.

```env
VSN_DEMO_SEED_ENABLED=false
```

To explicitly seed demo data in a non-production sandbox, set it to `true` before `php artisan migrate:fresh --seed`.

Never enable demo seeding in a real production database.

## Demo accounts

All demo accounts use `ChangeMe12345` and `.test` email addresses. The login screen fetches `/api/v1/demo/accounts` only when demo mode is enabled.

- `customer@example.test` — Customer
- `seller@example.test` — Seller
- `support@example.test` — Support
- `moderator@example.test` — Moderator
- `finance@example.test` — Finance
- `ops-admin@example.test` — Admin
- `admin@example.test` — Super Admin

Additional seeded customers/sellers provide marketplace data for dashboards.

## Seeded workflow evidence

The demo environment adds representative data for products/inventory, multiple order statuses, return queue, review + abuse report, KYC states, payout requests, risk case/hold, affiliate relationship/commission, Game Win entry, VSN Coin wallet balance and notification inboxes.
