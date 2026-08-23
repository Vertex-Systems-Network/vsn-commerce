<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Guards the finance dashboard query against joined-column ambiguity across supported databases. */
class FinanceDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    /** Verifies the finance dashboard renders successfully on an empty migrated database. */
    public function test_finance_dashboard_returns_summary_without_ambiguous_join_columns(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Finance]));

        $this->getJson('/api/v1/admin/finance')
            ->assertOk()
            ->assertJsonPath('data.currency', config('vsn.currency', 'PKR'))
            ->assertJsonStructure([
                'data' => [
                    'ledger',
                    'operationalLiabilities',
                    'payouts',
                ],
            ]);
    }
}
