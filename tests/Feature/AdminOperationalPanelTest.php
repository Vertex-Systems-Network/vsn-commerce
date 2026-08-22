<?php

namespace Tests\Feature;

use App\Domain\Settings\MarketplaceSettings;
use App\Models\MarketplaceNotification;
use App\Models\MarketplaceSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Defines the AdminOperationalPanelTest class and its project responsibilities. */
class AdminOperationalPanelTest extends TestCase
{
    use RefreshDatabase;

    /** Handles user for the admin operational panel test workflow. */
    private function user(string $role, string $email): User
    {
        return User::create(['name'=>ucwords(str_replace('_',' ',$role)),'email'=>$email,'password'=>'StrongPass123','role'=>$role]);
    }

    /** Verifies customer cannot access operational admin orders. */
    public function test_customer_cannot_access_operational_admin_orders(): void
    {
        $customer=$this->user('customer','ah-customer@example.test');
        $this->actingAs($customer)->getJson('/api/v1/admin/orders')->assertForbidden();
    }

    /** Verifies support can read orders but cannot edit marketplace settings. */
    public function test_support_can_read_orders_but_cannot_edit_marketplace_settings(): void
    {
        $support=$this->user('support','ah-support@example.test');
        $this->actingAs($support)->getJson('/api/v1/admin/orders')->assertOk()->assertJsonPath('data.meta.total',0);
        $this->actingAs($support)->putJson('/api/v1/admin/settings',['group'=>'orders','values'=>['orderingEnabled'=>false]])->assertForbidden();
    }

    /** Verifies admin can save operational settings and service reads them. */
    public function test_admin_can_save_operational_settings_and_service_reads_them(): void
    {
        $admin=$this->user('admin','ah-admin@example.test');
        $this->actingAs($admin)->putJson('/api/v1/admin/settings',[
            'group'=>'orders','values'=>['orderingEnabled'=>false,'returnsWindowDays'=>21,'autoCancelUnpaidHours'=>12],
        ])->assertOk()->assertJsonPath('data.groups.orders.orderingEnabled',false)->assertJsonPath('data.groups.orders.returnsWindowDays',21);
        $this->assertDatabaseHas('marketplace_settings',['group'=>'orders','key'=>'orderingEnabled','updated_by'=>$admin->id]);
        $settings=app(MarketplaceSettings::class);
        $this->assertFalse($settings->orderingEnabled());
        $this->assertSame(21,$settings->returnsWindowDays());
    }

    /** Verifies admin broadcast creates customer notification. */
    public function test_admin_broadcast_creates_customer_notification(): void
    {
        $admin=$this->user('admin','ah-broadcast-admin@example.test');
        $customer=$this->user('customer','ah-broadcast-customer@example.test');
        $this->actingAs($admin)->postJson('/api/v1/admin/notifications/broadcast',[
            'audience'=>'customers','title'=>'Service update','body'=>'Marketplace operations notice.','actionUrl'=>'/account/notifications',
        ])->assertOk()->assertJsonPath('data.recipients',1);
        $this->assertDatabaseHas('marketplace_notifications',['user_id'=>$customer->id,'type'=>'admin.broadcast','title'=>'Service update']);
    }

    /** Verifies admin return queue and finance queue are available. */
    public function test_admin_return_queue_and_finance_queue_are_available(): void
    {
        $admin=$this->user('admin','ah-queues@example.test');
        $this->actingAs($admin)->getJson('/api/v1/admin/returns')->assertOk()->assertJsonPath('data.meta.total',0);
        $this->actingAs($admin)->getJson('/api/v1/admin/finance/payouts')->assertOk()->assertJsonCount(0,'data');
    }
}
