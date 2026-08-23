<?php

namespace App\Domain\Operations\Services;

use Illuminate\Support\Facades\DB;

/** Defines the DatabaseIndexAuditService class and its project responsibilities. */
class DatabaseIndexAuditService
{
    public const REQUIRED = [
        'ops_orders_payment_status_placed_idx',
        'ops_vendor_orders_status_created_idx',
        'ops_inventory_reservations_expiry_idx',
        'ops_notification_delivery_ready_idx',
        'ops_risk_holds_expiry_idx',
        'av_products_status_recent_idx',
        'av_products_price_idx',
        'av_products_rating_idx',
        'av_products_popular_idx',
        'av_categories_active_sort_idx',
        'av_variants_product_active_idx',
        'av_orders_user_status_idx',
        'av_reviews_moderation_idx',
    ];

    public const MYSQL_REQUIRED = [
        'carts_one_active_user_mysql_uq',
        'checkout_one_reserved_cart_mysql_uq',
        'saved_payment_default_user_mysql_uq',
        'tax_jurisdiction_region_mysql_uq',
        'tax_class_one_default_mysql_uq',
    ];

    /** Executes the database index audit service operation. */
    public function execute(): array
    {
        $driver = DB::getDriverName();
        $required = self::REQUIRED;

        if ($driver === 'pgsql') {
            $names = collect(DB::select('select indexname from pg_indexes where schemaname=current_schema()'))->pluck('indexname');
        } elseif ($driver === 'sqlite') {
            $names = collect();
            foreach ([
                'orders',
                'vendor_orders',
                'inventory_reservations',
                'notification_deliveries',
                'risk_holds',
                'products',
                'categories',
                'product_variants',
                'reviews',
            ] as $table) {
                foreach (DB::select("pragma index_list('$table')") as $row) {
                    $names->push($row->name);
                }
            }
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            $required = array_merge($required, self::MYSQL_REQUIRED);
            $names = collect(DB::select(
                'select distinct index_name as index_identifier from information_schema.statistics where table_schema = database()'
            ))->pluck('index_identifier');
        } else {
            return ['driver' => $driver, 'supported' => false, 'missing' => $required];
        }

        $missing = collect($required)->reject(/** Inline callback for this operation. */ fn ($name) => $names->contains($name))->values()->all();

        return [
            'driver' => $driver,
            'supported' => true,
            'required' => count($required),
            'missing' => $missing,
            'ok' => $missing === [],
        ];
    }
}
