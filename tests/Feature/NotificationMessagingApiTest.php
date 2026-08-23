<?php

namespace Tests\Feature;

use App\Domain\Notifications\Actions\DispatchNotificationDeliveries;
use App\Domain\Notifications\Actions\PublishMarketplaceNotification;
use App\Domain\Notifications\Actions\ReconcileMarketplaceNotifications;
use App\Enums\CartStatus;
use App\Enums\CheckoutStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Cart;
use App\Models\CheckoutSession;
use App\Models\ConversationMessage;
use App\Models\MarketplaceNotification;
use App\Models\NotificationPreference;
use App\Models\Order;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Defines the NotificationMessagingApiTest class and its project responsibilities. */
class NotificationMessagingApiTest extends TestCase
{
    use RefreshDatabase;

    /** Verifies notification publish is deduplicated and visible in activity. */
    public function test_notification_publish_is_deduplicated_and_visible_in_activity(): void
    {
        $user=User::factory()->create();
        $action=app(PublishMarketplaceNotification::class);
        $first=$action->execute($user,'orders','order.placed','Order received','Order placed.','test-order-1','/orders','order','NX-1');
        $second=$action->execute($user,'orders','order.placed','Order received','Order placed.','test-order-1','/orders','order','NX-1');
        $this->assertSame($first?->id,$second?->id);
        $this->assertDatabaseCount('marketplace_notifications',1);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/activity')->assertOk()->assertJsonPath('data.notificationsUnread',1);
    }

    /** Verifies in app preference can hide event while email delivery stays queued. */
    public function test_in_app_preference_can_hide_event_while_email_delivery_stays_queued(): void
    {
        $user=User::factory()->create();
        NotificationPreference::create(['user_id'=>$user->id,'category'=>'orders','channel'=>'in_app','enabled'=>false]);
        NotificationPreference::create(['user_id'=>$user->id,'category'=>'orders','channel'=>'email','enabled'=>true]);
        $row=app(PublishMarketplaceNotification::class)->execute($user,'orders','order.test','Order update','Email only event.','email-only-1');
        $this->assertFalse((bool)$row?->in_app_visible);
        $this->assertDatabaseHas('notification_deliveries',['marketplace_notification_id'=>$row->id,'channel'=>'email','status'=>'pending']);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(0,'data.items');
    }

    /** Verifies notification reconciliation is safe to run repeatedly. */
    public function test_notification_reconciliation_is_safe_to_run_repeatedly(): void
    {
        [,,$order]=$this->sellerOrder();
        app(ReconcileMarketplaceNotifications::class)->execute();
        app(ReconcileMarketplaceNotifications::class)->execute();
        $this->assertSame(1,MarketplaceNotification::query()->where('reference_type','order')->where('reference_id',$order->public_id)->count());
    }

    /** Verifies notification can be read and read all is user scoped. */
    public function test_notification_can_be_read_and_read_all_is_user_scoped(): void
    {
        $a=User::factory()->create();$b=User::factory()->create();$publish=app(PublishMarketplaceNotification::class);
        $n=$publish->execute($a,'orders','a','A','A body','a-1');$publish->execute($a,'orders','a2','A2','A2 body','a-2');$publish->execute($b,'orders','b','B','B body','b-1');
        Sanctum::actingAs($a);$this->postJson("/api/v1/notifications/{$n->public_id}/read")->assertOk()->assertJsonPath('data.read',true);$this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.unreadCount',0);
        $this->assertSame(1,MarketplaceNotification::query()->where('user_id',$b->id)->whereNull('read_at')->count());
    }

    /** Verifies email outbox dispatches once. */
    public function test_email_outbox_dispatches_once(): void
    {
        Mail::spy();$user=User::factory()->create();$row=app(PublishMarketplaceNotification::class)->execute($user,'orders','mail.test','Mail test','Body','mail-test-1');
        app(DispatchNotificationDeliveries::class)->execute();app(DispatchNotificationDeliveries::class)->execute();
        $this->assertDatabaseHas('notification_deliveries',['marketplace_notification_id'=>$row->id,'channel'=>'email','status'=>'sent','attempts'=>1]);
        Mail::shouldHaveReceived('raw')->once();
    }

    /** Verifies customer support conversation and message send are idempotent. */
    public function test_customer_support_conversation_and_message_send_are_idempotent(): void
    {
        $user=User::factory()->create();Sanctum::actingAs($user);
        $conversation=$this->postJson('/api/v1/messages/conversations',['kind'=>'support'])->assertOk()->json('data');
        $same=$this->postJson('/api/v1/messages/conversations',['kind'=>'support'])->assertOk()->json('data');$this->assertSame($conversation['id'],$same['id']);
        $payload=['body'=>'I need help','clientId'=>'client-message-001'];
        $a=$this->postJson("/api/v1/messages/conversations/{$conversation['id']}/messages",$payload)->assertOk()->json('data.id');
        $b=$this->postJson("/api/v1/messages/conversations/{$conversation['id']}/messages",$payload)->assertOk()->json('data.id');
        $this->assertSame($a,$b);$this->assertDatabaseCount('conversation_messages',1);
    }

    /** Verifies order chat is limited to buyer and that specific seller. */
    public function test_order_chat_is_limited_to_buyer_and_that_specific_seller(): void
    {
        [$buyer,$owner,$order,$vendorOrder]=$this->sellerOrder();$stranger=User::factory()->create();
        Sanctum::actingAs($buyer);$conversation=$this->postJson('/api/v1/messages/conversations',['kind'=>'order','vendorOrderId'=>$vendorOrder->public_id])->assertOk()->json('data');
        Sanctum::actingAs($owner);$this->getJson("/api/v1/messages/conversations/{$conversation['id']}")->assertOk();
        Sanctum::actingAs($stranger);$this->getJson("/api/v1/messages/conversations/{$conversation['id']}")->assertNotFound();
        $this->postJson('/api/v1/messages/conversations',['kind'=>'order','vendorOrderId'=>$vendorOrder->public_id])->assertStatus(422);
    }

    /** Verifies unread cursor increments and clears when recipient opens thread. */
    public function test_unread_cursor_increments_and_clears_when_recipient_opens_thread(): void
    {
        [$buyer,$owner,,$vendorOrder]=$this->sellerOrder();Sanctum::actingAs($buyer);$conversation=$this->postJson('/api/v1/messages/conversations',['kind'=>'order','vendorOrderId'=>$vendorOrder->public_id])->assertOk()->json('data');
        $this->postJson("/api/v1/messages/conversations/{$conversation['id']}/messages",['body'=>'Seller question','clientId'=>'buyer-msg-1'])->assertOk();
        Sanctum::actingAs($owner);$this->getJson('/api/v1/activity')->assertOk()->assertJsonPath('data.messagesUnread',1);$this->getJson("/api/v1/messages/conversations/{$conversation['id']}")->assertOk();$this->getJson('/api/v1/activity')->assertOk()->assertJsonPath('data.messagesUnread',0);
    }

    /** Verifies message attachment is private to conversation participants. */
    public function test_message_attachment_is_private_to_conversation_participants(): void
    {
        Storage::fake('local');[$buyer,$owner,,$vendorOrder]=$this->sellerOrder();Sanctum::actingAs($buyer);$conversation=$this->postJson('/api/v1/messages/conversations',['kind'=>'order','vendorOrderId'=>$vendorOrder->public_id])->assertOk()->json('data');
        $response=$this->post("/api/v1/messages/conversations/{$conversation['id']}/messages",['body'=>'Invoice image','clientId'=>'attachment-msg-1','attachments'=>[UploadedFile::fake()->image('proof.jpg')]],['Accept'=>'application/json'])->assertOk();
        $attachmentId=$response->json('data.attachments.0.id');
        Sanctum::actingAs($owner);$this->get("/api/v1/messages/attachments/{$attachmentId}")->assertOk();
        Sanctum::actingAs(User::factory()->create());$this->get("/api/v1/messages/attachments/{$attachmentId}")->assertNotFound();
    }

    /** Verifies more than four attachments are rejected. */
    public function test_more_than_four_attachments_are_rejected(): void
    {
        Storage::fake('local');$user=User::factory()->create();Sanctum::actingAs($user);$conversation=$this->postJson('/api/v1/messages/conversations',['kind'=>'support'])->assertOk()->json('data');
        $files=[];for($i=0;$i<5;$i++)$files[]=UploadedFile::fake()->image("{$i}.jpg");
        $this->post("/api/v1/messages/conversations/{$conversation['id']}/messages",['clientId'=>'too-many-files','attachments'=>$files],['Accept'=>'application/json'])->assertStatus(422);
    }

    /** Verifies support agent can join customer support thread and reply. */
    public function test_support_agent_can_join_customer_support_thread_and_reply(): void
    {
        $customer=User::factory()->create();Sanctum::actingAs($customer);$conversation=$this->postJson('/api/v1/messages/conversations',['kind'=>'support'])->assertOk()->json('data');
        $support=User::factory()->create(['role'=>UserRole::Support]);Sanctum::actingAs($support);$this->getJson('/api/v1/messages/conversations')->assertOk()->assertJsonPath('data.items.0.id',$conversation['id']);
        $this->postJson("/api/v1/messages/conversations/{$conversation['id']}/messages",['body'=>'How can I help?','clientId'=>'support-reply-1'])->assertOk();
        $this->assertDatabaseHas('conversation_participants',['conversation_id'=>\App\Models\Conversation::where('public_id',$conversation['id'])->value('id'),'user_id'=>$support->id,'participant_role'=>'support']);
    }

    /** Verifies chat message facts are immutable at model layer. */
    public function test_chat_message_facts_are_immutable_at_model_layer(): void
    {
        $user=User::factory()->create();Sanctum::actingAs($user);$conversation=$this->postJson('/api/v1/messages/conversations',['kind'=>'support'])->assertOk()->json('data');$this->postJson("/api/v1/messages/conversations/{$conversation['id']}/messages",['body'=>'Immutable','clientId'=>'immutable-1'])->assertOk();
        $message=ConversationMessage::firstOrFail();$this->expectException(\LogicException::class);$message->update(['body'=>'tampered']);
    }

    /** Handles seller order for the notification messaging api test workflow. */
    private function sellerOrder(): array
    {
        $buyer=User::factory()->create();$owner=User::factory()->create(['role'=>UserRole::Seller]);$vendor=Vendor::create(['owner_user_id'=>$owner->id,'name'=>'Message Seller','slug'=>'message-seller-'.Str::lower((string)Str::ulid()),'status'=>'active','commission_bps'=>1000]);
        $cart=Cart::create(['public_id'=>(string)Str::ulid(),'user_id'=>$buyer->id,'status'=>CartStatus::Converted,'currency'=>'PKR']);
        $session=CheckoutSession::create(['public_id'=>(string)Str::ulid(),'user_id'=>$buyer->id,'cart_id'=>$cart->id,'idempotency_key'=>'message-checkout-'.Str::uuid(),'status'=>CheckoutStatus::Converted,'currency'=>'PKR','address_snapshot'=>['recipient_name'=>$buyer->name,'phone'=>'0300','line1'=>'Test','city'=>'Lahore','country_code'=>'PK'],'shipping_method'=>'standard','payment_method'=>'card','subtotal_minor'=>100_000,'shipping_minor'=>10_000,'discount_minor'=>0,'coin_redemption_coins'=>0,'coin_redemption_minor'=>0,'total_minor'=>110_000,'expires_at'=>now()->addMinute(),'converted_at'=>now()]);
        $order=Order::create(['public_id'=>(string)Str::ulid(),'user_id'=>$buyer->id,'checkout_session_id'=>$session->id,'status'=>OrderStatus::Confirmed,'payment_status'=>PaymentStatus::Paid,'payment_method'=>'card','currency'=>'PKR','subtotal_minor'=>100_000,'shipping_minor'=>10_000,'discount_minor'=>0,'coin_redemption_coins'=>0,'coin_redemption_minor'=>0,'total_minor'=>110_000,'placed_at'=>now()]);
        $vendorOrder=$order->vendorOrders()->create(['public_id'=>(string)Str::ulid(),'vendor_id'=>$vendor->id,'status'=>OrderStatus::Confirmed,'currency'=>'PKR','subtotal_minor'=>100_000,'shipping_minor'=>10_000,'discount_minor'=>0,'total_minor'=>110_000,'commission_bps'=>1000,'platform_commission_minor'=>10_000,'seller_payable_minor'=>100_000]);
        return [$buyer,$owner,$order,$vendorOrder,$vendor];
    }
}
