<?php

namespace Tests\Feature;

use App\Domain\Reviews\Actions\DispatchReviewReminders;
use App\Enums\CartStatus;
use App\Enums\CheckoutStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Enums\ReviewCouponStatus;
use App\Enums\ReviewStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CheckoutSession;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\ReviewRewardCoupon;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Defines the ReviewApiTest class and its project responsibilities. */
class ReviewApiTest extends TestCase
{
    use RefreshDatabase;

    /** Verifies only delivered non fully refunded order lines are pending for review. */
    public function test_only_delivered_non_fully_refunded_order_lines_are_pending_for_review(): void
    {
        $user=User::factory()->create();
        [$product,,,$item]=$this->purchase($user, OrderStatus::Delivered, 0);
        [, , , $refunded]=$this->purchase($user, OrderStatus::PartiallyRefunded, 1, 1);
        [, , , $undelivered]=$this->purchase($user, OrderStatus::Confirmed, 0);
        Sanctum::actingAs($user);

        $response=$this->getJson('/api/v1/reviews')->assertOk();
        $this->assertSame([$item->id], collect($response->json('data.pending'))->pluck('orderItemId')->all());
        $this->assertNotContains($refunded->id, collect($response->json('data.pending'))->pluck('orderItemId')->all());
        $this->assertNotContains($undelivered->id, collect($response->json('data.pending'))->pluck('orderItemId')->all());
        $this->assertSame($product->id, $response->json('data.pending.0.productId'));
    }

    /** Verifies verified review accepts up to four images and issues one account bound coupon. */
    public function test_verified_review_accepts_up_to_four_images_and_issues_one_account_bound_coupon(): void
    {
        Storage::fake('public');
        $user=User::factory()->create(); [, , , $item]=$this->purchase($user, OrderStatus::Delivered, 0);
        Sanctum::actingAs($user);
        $payload=['orderItemId'=>$item->id,'rating'=>5,'text'=>'Excellent quality, careful packaging and accurate delivery tracking.'];
        for($i=0;$i<4;$i++) $payload['images'][]=UploadedFile::fake()->image("review-{$i}.jpg",400,400);

        $data=$this->post('/api/v1/reviews',$payload,['Accept'=>'application/json'])->assertOk()
            ->assertJsonPath('data.verifiedPurchase',true)->assertJsonPath('data.status','pending')->json('data');

        $this->assertDatabaseCount('reviews',1);
        $this->assertDatabaseCount('review_images',4);
        $this->assertDatabaseHas('review_reward_coupons',['code'=>$data['coupon']['code'],'user_id'=>$user->id,'status'=>'available','percent_bps'=>1000]);
    }

    /** Verifies more than four review images are rejected. */
    public function test_more_than_four_review_images_are_rejected(): void
    {
        Storage::fake('public');
        $user=User::factory()->create(); [, , , $item]=$this->purchase($user, OrderStatus::Delivered, 0); Sanctum::actingAs($user);
        $images=[]; for($i=0;$i<5;$i++)$images[]=UploadedFile::fake()->image("review-{$i}.jpg");
        $this->post('/api/v1/reviews',['orderItemId'=>$item->id,'rating'=>4,'text'=>'A useful review with enough detail.','images'=>$images],['Accept'=>'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('images');
        $this->assertDatabaseCount('reviews',0);
    }

    /** Verifies retrying same order line does not create duplicate review or coupon. */
    public function test_retrying_same_order_line_does_not_create_duplicate_review_or_coupon(): void
    {
        $user=User::factory()->create(); [, , , $item]=$this->purchase($user, OrderStatus::Delivered, 0); Sanctum::actingAs($user);
        $payload=['orderItemId'=>$item->id,'rating'=>5,'text'=>'Very good product and delivery experience overall.'];
        $first=$this->postJson('/api/v1/reviews',$payload)->assertOk()->json('data');
        $second=$this->postJson('/api/v1/reviews',$payload)->assertOk()->json('data');
        $this->assertSame($first['id'],$second['id']);
        $this->assertSame($first['coupon']['code'],$second['coupon']['code']);
        $this->assertDatabaseCount('reviews',1); $this->assertDatabaseCount('review_reward_coupons',1);
    }

    /** Verifies pending review is not public until moderator approves it and product rating recalculates. */
    public function test_pending_review_is_not_public_until_moderator_approves_it_and_product_rating_recalculates(): void
    {
        $user=User::factory()->create(['name'=>'Fatima Rahman']); [$product,,,$item]=$this->purchase($user, OrderStatus::Delivered, 0); Sanctum::actingAs($user);
        $review=$this->postJson('/api/v1/reviews',['orderItemId'=>$item->id,'rating'=>4,'text'=>'Solid product with good packaging and delivery.'])->assertOk()->json('data');
        $this->getJson("/api/v1/products/{$product->id}/reviews")->assertOk()->assertJsonPath('data.meta.total',0);

        $moderator=User::factory()->create(['role'=>UserRole::Moderator]); Sanctum::actingAs($moderator);
        $this->postJson("/api/v1/admin/reviews/{$review['id']}/moderate",['status'=>'approved'])->assertOk()->assertJsonPath('data.status','approved');
        $this->assertSame(1,(int)$product->fresh()->reviews_count); $this->assertSame('4.00',(string)$product->fresh()->rating);
        $this->getJson("/api/v1/products/{$product->id}/reviews")->assertOk()->assertJsonPath('data.meta.total',1)->assertJsonPath('data.items.0.rating',4)->assertJsonPath('data.items.0.user.name','Fatima R.');
    }

    /** Verifies rejected review revokes an unused coupon. */
    public function test_rejected_review_revokes_an_unused_coupon(): void
    {
        $user=User::factory()->create(); [, , , $item]=$this->purchase($user, OrderStatus::Delivered, 0); Sanctum::actingAs($user);
        $review=$this->postJson('/api/v1/reviews',['orderItemId'=>$item->id,'rating'=>1,'text'=>'The item arrived but the experience did not meet expectations.'])->assertOk()->json('data');
        $moderator=User::factory()->create(['role'=>UserRole::Admin]); Sanctum::actingAs($moderator);
        $this->postJson("/api/v1/admin/reviews/{$review['id']}/moderate",['status'=>'rejected','note'=>'Contains unsupported claims.'])->assertOk();
        $this->assertDatabaseHas('review_reward_coupons',['code'=>$review['coupon']['code'],'status'=>'revoked']);
    }

    /** Verifies review coupon is ten percent reserved at checkout released on cancel and redeemed once on order. */
    public function test_review_coupon_is_ten_percent_reserved_at_checkout_released_on_cancel_and_redeemed_once_on_order(): void
    {
        $user=User::factory()->create(); [, , , $sourceItem]=$this->purchase($user, OrderStatus::Delivered, 0); Sanctum::actingAs($user);
        $review=$this->postJson('/api/v1/reviews',['orderItemId'=>$sourceItem->id,'rating'=>5,'text'=>'Excellent purchase and an easy delivery experience.'])->assertOk()->json('data');
        [$cart,$address]=$this->activeCart($user,100_000);

        $payload=['addressId'=>$address->id,'shippingMethod'=>'standard','paymentMethod'=>'cod','couponCode'=>$review['coupon']['code'],'coinRedemptionCoins'=>0,'idempotencyKey'=>'review-coupon-checkout-1'];
        $checkout=$this->postJson('/api/v1/checkout/sessions',$payload)->assertCreated()->assertJsonPath('data.totals.discountMinor',10_000)->json('data');
        $this->assertDatabaseHas('review_reward_coupons',['code'=>$review['coupon']['code'],'status'=>'reserved']);
        $this->deleteJson("/api/v1/checkout/sessions/{$checkout['id']}")->assertOk();
        $this->assertDatabaseHas('review_reward_coupons',['code'=>$review['coupon']['code'],'status'=>'available']);

        $payload['idempotencyKey']='review-coupon-checkout-2';
        $checkout=$this->postJson('/api/v1/checkout/sessions',$payload)->assertCreated()->json('data');
        $order=$this->postJson("/api/v1/checkout/sessions/{$checkout['id']}/order",[])->assertOk()->json('data');
        $this->assertDatabaseHas('review_reward_coupons',['code'=>$review['coupon']['code'],'status'=>'redeemed','redeemed_order_id'=>Order::query()->where('public_id',$order['id'])->value('id')]);

        [$anotherCart,$address]=$this->activeCart($user,100_000);
        $payload['idempotencyKey']='review-coupon-checkout-3';
        $this->postJson('/api/v1/checkout/sessions',$payload)->assertUnprocessable();
    }


    /** Verifies review coupon cannot be used by another account. */
    public function test_review_coupon_cannot_be_used_by_another_account(): void
    {
        $owner=User::factory()->create(); [, , , $item]=$this->purchase($owner, OrderStatus::Delivered, 0); Sanctum::actingAs($owner);
        $review=$this->postJson('/api/v1/reviews',['orderItemId'=>$item->id,'rating'=>5,'text'=>'A strong verified purchase experience with good packaging.'])->assertOk()->json('data');

        $other=User::factory()->create(); [, $address]=$this->activeCart($other,100_000); Sanctum::actingAs($other);
        $this->postJson('/api/v1/checkout/sessions',['addressId'=>$address->id,'shippingMethod'=>'standard','paymentMethod'=>'cod','couponCode'=>$review['coupon']['code'],'coinRedemptionCoins'=>0,'idempotencyKey'=>'foreign-review-coupon'])
            ->assertUnprocessable()->assertJsonPath('errors.couponCode.0','The review coupon is invalid for this account.');
    }

    /** Verifies fully refunded source item makes unused coupon invalid. */
    public function test_fully_refunded_source_item_makes_unused_coupon_invalid(): void
    {
        $user=User::factory()->create(); [, , , $item]=$this->purchase($user, OrderStatus::Delivered, 0); Sanctum::actingAs($user);
        $review=$this->postJson('/api/v1/reviews',['orderItemId'=>$item->id,'rating'=>5,'text'=>'Good product before the later return was processed.'])->assertOk()->json('data');
        $item->update(['refunded_quantity'=>$item->quantity]);
        [$cart,$address]=$this->activeCart($user,100_000);
        $this->postJson('/api/v1/checkout/sessions',['addressId'=>$address->id,'shippingMethod'=>'standard','paymentMethod'=>'cod','couponCode'=>$review['coupon']['code'],'coinRedemptionCoins'=>0,'idempotencyKey'=>'review-refunded-coupon'])->assertUnprocessable();
        $this->assertDatabaseHas('review_reward_coupons',['code'=>$review['coupon']['code'],'status'=>'revoked']);
    }

    /** Verifies review reminder is queued only once for eligible delivered line. */
    public function test_review_reminder_is_queued_only_once_for_eligible_delivered_line(): void
    {
        $user=User::factory()->create(['email_verified_at'=>now()]); [, , $order, $item]=$this->purchase($user, OrderStatus::Delivered, 0);
        $order->update(['delivered_at'=>now()->subDays(3)]);
        $action=app(DispatchReviewReminders::class);
        $this->assertSame(1,$action->execute());
        $this->assertSame(0,$action->execute());
        $this->assertDatabaseHas('marketplace_notifications',['user_id'=>$user->id,'type'=>'review.reminder']);
        $this->assertDatabaseHas('notification_deliveries',['channel'=>'email','status'=>'pending']);
        $this->assertDatabaseCount('review_reminders',1);
        $this->assertDatabaseHas('review_reminders',['order_item_id'=>$item->id,'status'=>'queued']);
    }

    /** Handles purchase for the review api test workflow. */
    private function purchase(User $user, OrderStatus $status, int $refundedQuantity=0, int $quantity=1): array
    {
        $vendor=Vendor::create(['name'=>'Review Seller '.Str::random(5),'slug'=>'review-seller-'.Str::lower(Str::random(8)),'status'=>'active','commission_bps'=>1000]);
        $product=Product::create(['public_id'=>(string)Str::ulid(),'vendor_id'=>$vendor->id,'sku'=>'REV-'.Str::upper(Str::random(8)),'slug'=>'review-product-'.Str::lower(Str::random(8)),'name'=>'Review Product','status'=>ProductStatus::Published,'currency'=>'PKR','base_price_minor'=>100_000]);
        $variant=ProductVariant::create(['product_id'=>$product->id,'sku'=>$product->sku.'-D','name'=>'Default','price_minor'=>100_000,'is_default'=>true,'is_active'=>true,'option_values'=>[]]);
        $cart=Cart::create(['public_id'=>(string)Str::ulid(),'user_id'=>$user->id,'status'=>CartStatus::Converted,'currency'=>'PKR']);
        $session=CheckoutSession::create(['public_id'=>(string)Str::ulid(),'user_id'=>$user->id,'cart_id'=>$cart->id,'idempotency_key'=>'review-source-'.Str::uuid(),'status'=>CheckoutStatus::Converted,'currency'=>'PKR','address_snapshot'=>['recipient_name'=>$user->name,'phone'=>'0300','line1'=>'Review Street','city'=>'Lahore','country_code'=>'PK'],'shipping_method'=>'standard','payment_method'=>'cod','subtotal_minor'=>100_000*$quantity,'shipping_minor'=>0,'discount_minor'=>0,'coin_redemption_coins'=>0,'coin_redemption_minor'=>0,'total_minor'=>100_000*$quantity,'expires_at'=>now()->addMinutes(15),'converted_at'=>now()]);
        $delivered=in_array($status,[OrderStatus::Delivered,OrderStatus::PartiallyRefunded],true);
        $order=Order::create(['public_id'=>(string)Str::ulid(),'user_id'=>$user->id,'checkout_session_id'=>$session->id,'status'=>$status,'payment_status'=>PaymentStatus::Paid,'payment_method'=>'cod','currency'=>'PKR','subtotal_minor'=>100_000*$quantity,'shipping_minor'=>0,'discount_minor'=>0,'coin_redemption_coins'=>0,'coin_redemption_minor'=>0,'total_minor'=>100_000*$quantity,'placed_at'=>now()->subDays(5),'delivered_at'=>$delivered?now()->subDays(3):null]);
        $vo=$order->vendorOrders()->create(['public_id'=>(string)Str::ulid(),'vendor_id'=>$vendor->id,'status'=>$status,'currency'=>'PKR','subtotal_minor'=>100_000*$quantity,'shipping_minor'=>0,'discount_minor'=>0,'total_minor'=>100_000*$quantity,'commission_bps'=>1000,'platform_commission_minor'=>10_000*$quantity,'seller_payable_minor'=>90_000*$quantity]);
        $item=$order->items()->create(['vendor_order_id'=>$vo->id,'product_id'=>$product->id,'product_variant_id'=>$variant->id,'product_name'=>$product->name,'variant_name'=>'Default','sku'=>$variant->sku,'quantity'=>$quantity,'refunded_quantity'=>$refundedQuantity,'currency'=>'PKR','unit_price_minor'=>100_000,'line_total_minor'=>100_000*$quantity,'selected_options'=>[]]);
        return [$product,$variant,$order,$item];
    }

    /** Handles active cart for the review api test workflow. */
    private function activeCart(User $user, int $priceMinor): array
    {
        $existing=Cart::query()->where('user_id',$user->id)->where('status',CartStatus::Active->value)->first();
        if($existing) $existing->update(['status'=>CartStatus::Abandoned]);
        $vendor=Vendor::create(['name'=>'Checkout Seller '.Str::random(4),'slug'=>'checkout-seller-'.Str::lower(Str::random(8)),'status'=>'active','commission_bps'=>1000]);
        $product=Product::create(['public_id'=>(string)Str::ulid(),'vendor_id'=>$vendor->id,'sku'=>'CHK-'.Str::upper(Str::random(8)),'slug'=>'checkout-product-'.Str::lower(Str::random(8)),'name'=>'Checkout Product','status'=>ProductStatus::Published,'currency'=>'PKR','base_price_minor'=>$priceMinor]);
        $variant=ProductVariant::create(['product_id'=>$product->id,'sku'=>$product->sku.'-D','name'=>'Default','price_minor'=>$priceMinor,'is_default'=>true,'is_active'=>true,'option_values'=>[]]);
        $warehouse=Warehouse::create(['code'=>'WH-'.Str::upper(Str::random(7)),'name'=>'Review Test Warehouse']);
        Inventory::create(['warehouse_id'=>$warehouse->id,'product_variant_id'=>$variant->id,'on_hand'=>10,'reserved'=>0,'safety_stock'=>0]);
        $cart=Cart::create(['public_id'=>(string)Str::ulid(),'user_id'=>$user->id,'status'=>CartStatus::Active,'currency'=>'PKR']);
        $cart->items()->create(['product_id'=>$product->id,'product_variant_id'=>$variant->id,'quantity'=>1,'currency'=>'PKR','unit_price_minor'=>$priceMinor,'selected_options'=>[]]);
        $address=Address::query()->where('user_id',$user->id)->first() ?: Address::create(['user_id'=>$user->id,'label'=>'Home','recipient_name'=>$user->name,'phone'=>'03001112222','line1'=>'10 Review Road','city'=>'Lahore','state'=>'Punjab','postal_code'=>'54000','country_code'=>'PK','is_default'=>true]);
        return [$cart,$address,$product,$variant];
    }
}
