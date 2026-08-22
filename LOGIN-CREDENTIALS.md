# VSN Ecommerce — Local / Demo Login Credentials

These accounts are created **only when** `VSN_DEMO_SEED_ENABLED=true`.

Default local `.env.example` enables demo seeding. Production `.env.production.example` disables it.

All primary demo accounts use this password:

```text
ChangeMe12345
```

| Access | Email | Landing | Purpose |
|---|---|---|---|
| Customer | `customer@example.test` | `/account` | Customer account, cart, checkout, orders, wallet |
| Seller | `seller@example.test` | `/vendor` | Seller Center data entry and operations |
| Support | `support@example.test` | `/admin` | Support/read-only operational access |
| Moderator | `moderator@example.test` | `/admin` | Reviews/moderation/compliance |
| Finance | `finance@example.test` | `/admin` | Finance, payouts, reconciliation, finance sign-off |
| Admin | `ops-admin@example.test` | `/admin` | Admin operations/data entry |
| **Super Admin** | **`admin@example.test`** | **`/admin`** | **Full admin access** |

## Recommended account for admin-panel data entry

Use:

```text
Email:    admin@example.test
Password: ChangeMe12345
```

For normal operational Admin testing (without Super Admin-only authority):

```text
Email:    ops-admin@example.test
Password: ChangeMe12345
```

## Seed locally

```bash
php artisan migrate:fresh --seed
```

If demo accounts are not shown, verify:

```env
VSN_DEMO_SEED_ENABLED=true
```

then run:

```bash
php artisan optimize:clear
php artisan db:seed
```

## Production

Do **not** enable demo seeding in production. Keep:

```env
VSN_DEMO_SEED_ENABLED=false
```

Create a real Super Admin with:

```bash
php artisan vsn:admin-create your-admin@example.com --name="Your Admin Name"
```

The command asks for the password securely instead of putting it in shell history.
