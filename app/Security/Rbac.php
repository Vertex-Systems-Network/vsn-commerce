<?php

namespace App\Security;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;

/** Defines the Rbac class and its project responsibilities. */
final class Rbac
{
    /** Handles role value for the rbac workflow. */
    public static function roleValue(UserRole|string|null $role): string
    {
        return $role instanceof UserRole ? $role->value : (string) $role;
    }

    /** Handles permissions for role for the rbac workflow. */
    public static function permissionsForRole(UserRole|string|null $role): array
    {
        $value = self::roleValue($role);
        $permissions = (array) config("rbac.roles.{$value}", []);
        if (in_array('*', $permissions, true)) {
            return array_values(array_unique(array_merge(self::allPermissions(), ['*'])));
        }
        sort($permissions);
        return array_values(array_unique($permissions));
    }

    /** Handles allows for the rbac workflow. */
    public static function allows(?User $user, string $permission): bool
    {
        if (! $user) return false;
        $permissions = self::permissionsForRole($user->role);
        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    /** Handles all permissions for the rbac workflow. */
    public static function allPermissions(): array
    {
        $all = [];
        foreach ((array) config('rbac.roles', []) as $permissions) {
            foreach ((array) $permissions as $permission) if ($permission !== '*') $all[$permission] = true;
        }
        $permissions = array_keys($all);
        sort($permissions);
        return $permissions;
    }

    /** Handles required for area request for the rbac workflow. */
    public static function requiredForAreaRequest(Request $request): ?string
    {
        $path = ltrim($request->path(), '/');
        $method = strtoupper($request->method());
        if (str_starts_with($path, 'api/v1/admin/')) return self::adminPermission($path, $method);
        if (str_starts_with($path, 'api/v1/vendor/')) return self::sellerPermission($path, $method);
        return null;
    }

    /** Handles enforce area request for the rbac workflow. */
    public static function enforceAreaRequest(Request $request): void
    {
        $path = ltrim($request->path(), '/');
        if (! str_starts_with($path, 'api/v1/admin/') && ! str_starts_with($path, 'api/v1/vendor/')) return;
        $required = self::requiredForAreaRequest($request);
        abort_unless($required !== null, 403, 'This route has no RBAC permission mapping.');
        abort_unless(self::allows($request->user(), $required), 403, "Permission required: {$required}");
    }

    /** Handles admin permission for the rbac workflow. */
    private static function adminPermission(string $path, string $method): ?string
    {
        $read = in_array($method, ['GET','HEAD'], true);
        $rules = [
            ['#^api/v1/admin/users(?:/|$)#', $read ? 'users.view' : 'users.manage'],
            ['#^api/v1/admin/rbac(?:/|$)#', 'users.view'],
            ['#^api/v1/admin/vendors(?:/|$)#', $read ? 'vendors.view' : 'vendors.manage'],
            ['#^api/v1/admin/(?:catalog|products|variants|categories)(?:/|$)#', $read ? 'catalog.view' : 'catalog.manage'],
            ['#^api/v1/admin/promotions(?:/|$)#', $read ? 'promotions.view' : 'promotions.manage'],
            ['#^api/v1/admin/games(?:/|$)#', $read ? 'games.view' : 'games.manage'],
            ['#^api/v1/admin/engagement/games(?:/|$)#', $read ? 'games.view' : 'games.manage'],
            ['#^api/v1/admin/engagement/(?:summary|wallets|affiliate)(?:/|$)#', $read ? 'loyalty.view' : 'loyalty.manage'],
            ['#^api/v1/admin/tax(?:/|$)#', $read ? 'tax.view' : 'tax.manage'],
            ['#^api/v1/admin/compliance(?:/|$)#', $read ? 'compliance.view' : 'compliance.review'],
            ['#^api/v1/admin/security/events(?:/|$)#', 'security.events.view'],
            ['#^api/v1/admin/audit-logs(?:/|$)#', 'audit.view'],
            ['#^api/v1/admin/shipping(?:/|$)#', $read ? 'shipping.view' : 'shipping.manage'],
            ['#^api/v1/admin/payments(?:/|$)#', $read ? 'payments.view' : 'payments.manage'],
            ['#^api/v1/admin/reviews(?:/|$)#', $read ? 'reviews.view' : 'reviews.moderate'],
            ['#^api/v1/admin/(?:media|media-library)(?:/|$)#', $read ? 'media.view' : 'media.manage'],
            ['#^api/v1/admin/finance(?:/|$)#', $read ? 'finance.view' : 'finance.manage'],
            ['#^api/v1/admin/risk(?:/|$)#', $read ? 'risk.view' : 'risk.manage'],
            ['#^api/v1/admin/orders(?:/|$)#', $read ? 'orders.view' : 'orders.manage'],
            ['#^api/v1/admin/(?:returns|refunds|disputes)(?:/|$)#', $read ? 'returns.view' : 'returns.manage'],
            ['#^api/v1/admin/analytics(?:/|$)#', $read ? 'analytics.view' : 'analytics.manage'],
            ['#^api/v1/admin/notifications(?:/|$)#', $read ? 'notifications.view' : 'notifications.manage'],
            ['#^api/v1/admin/settings(?:/|$)#', $read ? 'settings.view' : 'settings.manage'],
            ['#^api/v1/admin/system/(?:operations|backups|launch-gate|providers)(?:/|$)#', $read ? 'operations.view' : 'operations.manage'],
            ['#^api/v1/admin/system/migration(?:/|$)#', 'migration.manage'],
            ['#^api/v1/admin/system/acceptance/[^/]+/seal(?:/|$)#', 'acceptance.seal'],
            ['#^api/v1/admin/system/acceptance/[^/]+/signoff(?:/|$)#', 'acceptance.sign'],
            ['#^api/v1/admin/system/acceptance(?:/|$)#', $read ? 'acceptance.view' : 'acceptance.manage'],
            ['#^api/v1/admin/system/go-live/[^/]+/signoff(?:/|$)#', 'acceptance.sign'],
            ['#^api/v1/admin/system/go-live(?:/|$)#', $read ? 'acceptance.view' : 'acceptance.manage'],
            ['#^api/v1/admin/system/(?:dr-drills|incidents|legacy-decommission)(?:/|$)#', 'acceptance.manage'],
        ];
        foreach ($rules as [$pattern, $permission]) if (preg_match($pattern, $path)) return $permission;
        return null;
    }

    /** Handles seller permission for the rbac workflow. */
    private static function sellerPermission(string $path, string $method): ?string
    {
        $read = in_array($method, ['GET','HEAD'], true);
        $rules = [
            ['#^api/v1/vendor/overview(?:/|$)#', 'seller.overview.view'],
            ['#^api/v1/vendor/orders(?:/|$)#', $read ? 'seller.orders.view' : 'seller.orders.manage'],
            ['#^api/v1/vendor/shipping(?:/|$)#', $read ? 'seller.shipping.view' : 'seller.shipping.manage'],
            ['#^api/v1/vendor/shipments(?:/|$)#', $read ? 'seller.shipping.view' : 'seller.shipping.manage'],
            ['#^api/v1/vendor/returns(?:/|$)#', $read ? 'seller.returns.view' : 'seller.returns.manage'],
            ['#^api/v1/vendor/(?:catalog|products|variants)(?:/|$)#', $read ? 'seller.catalog.view' : 'seller.catalog.manage'],
            ['#^api/v1/vendor/media-library(?:/|$)#', $read ? 'seller.catalog.view' : 'seller.catalog.manage'],
            ['#^api/v1/vendor/promotions(?:/|$)#', $read ? 'seller.promotions.view' : 'seller.promotions.manage'],
            ['#^api/v1/vendor/reviews(?:/|$)#', $read ? 'seller.reviews.view' : 'seller.reviews.reply'],
            ['#^api/v1/vendor/finance(?:/|$)#', 'seller.finance.view'],
            ['#^api/v1/vendor/payout-methods(?:/|$)#', $read ? 'seller.payouts.view' : 'seller.payouts.manage'],
            ['#^api/v1/vendor/payouts(?:/|$)#', $read ? 'seller.payouts.view' : 'seller.payouts.manage'],
            ['#^api/v1/vendor/analytics(?:/|$)#', 'seller.analytics.view'],
            ['#^api/v1/vendor/invoices(?:/|$)#', 'seller.tax.view'],
            ['#^api/v1/vendor/tax-profile(?:/|$)#', $read ? 'seller.tax.view' : 'seller.tax.manage'],
            ['#^api/v1/vendor/settings(?:/|$)#', $read ? 'seller.settings.view' : 'seller.settings.manage'],
        ];
        foreach ($rules as [$pattern, $permission]) if (preg_match($pattern, $path)) return $permission;
        return null;
    }
}
