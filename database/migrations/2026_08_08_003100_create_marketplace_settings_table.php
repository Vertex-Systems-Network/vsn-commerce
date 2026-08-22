<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new /** Defines an anonymous class for this operation. */ class extends Migration {
    /** Applies this database migration. */
    public function up(): void
    {
        Schema::create('marketplace_settings', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->string('group',50);
            $table->string('key',100);
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['group','key'],'marketplace_settings_group_key_uq');
        });
    }
    /** Reverts this database migration. */
    public function down(): void { Schema::dropIfExists('marketplace_settings'); }
};
