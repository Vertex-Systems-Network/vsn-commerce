<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new /** Defines an anonymous class for this operation. */ class extends Migration
{
    /** Applies this database migration. */
    public function up(): void
    {
        Schema::create('user_profiles', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('phone', 40)->nullable()->index();
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('avatar_path')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('locale', 12)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('addresses', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 60)->nullable();
            $table->string('recipient_name', 120);
            $table->string('phone', 40);
            $table->string('line1', 190);
            $table->string('line2', 190)->nullable();
            $table->string('city', 100);
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 30)->nullable();
            $table->char('country_code', 2);
            $table->boolean('is_default')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('social_accounts', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('provider_user_id', 190);
            $table->string('provider_email', 190)->nullable();
            $table->text('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['user_id', 'provider']);
        });

        Schema::create('one_time_codes', /** Inline callback for this operation. */ function (Blueprint $table): void {
            $table->id();
            $table->string('purpose', 40)->index();
            $table->string('identifier', 190)->index();
            $table->string('code_hash');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['purpose', 'identifier', 'expires_at']);
        });
    }

    /** Reverts this database migration. */
    public function down(): void
    {
        Schema::dropIfExists('one_time_codes');
        Schema::dropIfExists('social_accounts');
        Schema::dropIfExists('addresses');
        Schema::dropIfExists('user_profiles');
    }
};
