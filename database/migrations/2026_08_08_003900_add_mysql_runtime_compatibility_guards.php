<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new /** Defines an anonymous class for this operation. */ class extends Migration
{
    /** Applies this database migration. */
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        // MySQL/MariaDB do not support PostgreSQL-style partial unique indexes.
        // Use VIRTUAL (not STORED) generated guards: some guarded expressions reference
        // foreign-key base columns that use ON DELETE SET NULL/CASCADE. MySQL forbids
        // those referential actions when the base column feeds a STORED generated column.
        // Indexed VIRTUAL guards preserve the partial-unique invariant without rebuilding
        // the FK relationship into an invalid definition.
        Schema::table('carts', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->unsignedBigInteger('mysql_active_user_guard')
                ->virtualAs("CASE WHEN user_id IS NOT NULL AND status = 'active' THEN user_id ELSE NULL END");
            $table->unique('mysql_active_user_guard', 'carts_one_active_user_mysql_uq');
        });

        Schema::table('checkout_sessions', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->unsignedBigInteger('mysql_reserved_cart_guard')
                ->virtualAs("CASE WHEN status = 'reserved' THEN cart_id ELSE NULL END");
            $table->unique('mysql_reserved_cart_guard', 'checkout_one_reserved_cart_mysql_uq');
        });

        Schema::table('saved_payment_methods', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->unsignedBigInteger('mysql_default_user_guard')
                ->virtualAs("CASE WHEN is_default = 1 AND status = 'active' THEN user_id ELSE NULL END");
            $table->unique('mysql_default_user_guard', 'saved_payment_default_user_mysql_uq');
        });

        Schema::table('tax_jurisdictions', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->string('mysql_region_guard', 100)
                ->virtualAs("COALESCE(region_code, '')");
            $table->unique(['country_code', 'mysql_region_guard'], 'tax_jurisdiction_region_mysql_uq');
        });

        Schema::table('tax_classes', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->unsignedTinyInteger('mysql_default_guard')
                ->virtualAs('CASE WHEN is_default = 1 THEN 1 ELSE NULL END');
            $table->unique('mysql_default_guard', 'tax_class_one_default_mysql_uq');
        });
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        Schema::table('tax_classes', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->dropUnique('tax_class_one_default_mysql_uq');
            $table->dropColumn('mysql_default_guard');
        });
        Schema::table('tax_jurisdictions', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->dropUnique('tax_jurisdiction_region_mysql_uq');
            $table->dropColumn('mysql_region_guard');
        });
        Schema::table('saved_payment_methods', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->dropUnique('saved_payment_default_user_mysql_uq');
            $table->dropColumn('mysql_default_user_guard');
        });
        Schema::table('checkout_sessions', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->dropUnique('checkout_one_reserved_cart_mysql_uq');
            $table->dropColumn('mysql_reserved_cart_guard');
        });
        Schema::table('carts', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->dropUnique('carts_one_active_user_mysql_uq');
            $table->dropColumn('mysql_active_user_guard');
        });
    }
};
