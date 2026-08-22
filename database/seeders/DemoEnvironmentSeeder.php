<?php

namespace Database\Seeders;

use App\Enums\CartStatus;
use App\Enums\CheckoutStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Enums\ReturnRequestStatus;
use App\Enums\ReturnResolution;
use App\Enums\ReviewStatus;
use App\Enums\UserRole;
use App\Enums\VendorSettlementStatus;
use App\Models\Address;
use App\Models\AffiliateAccount;
use App\Models\AffiliateCommission;
use App\Models\AffiliateRelationship;
use App\Models\Cart;
use App\Models\Category;
use App\Models\CheckoutSession;
use App\Models\Game;
use App\Models\GameEntry;
use App\Models\Inventory;
use App\Models\KycVerification;
use App\Models\MarketplaceNotification;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReturnRequest;
use App\Models\ReturnRequestItem;
use App\Models\Review;
use App\Models\ReviewReport;
use App\Models\RiskCase;
use App\Models\RiskHold;
use App\Models\RiskProfile;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorOrder;
use App\Models\VendorPayout;
use App\Models\VendorPayoutAttempt;
use App\Models\VendorPayoutItem;
use App\Models\VendorPayoutMethod;
use App\Models\VendorSettlement;
use App\Models\Wallet;
use App\Models\WalletEntry;
use App\Models\WalletTransaction;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Defines the DemoEnvironmentSeeder class and its project responsibilities. */
class DemoEnvironmentSeeder extends Seeder
{
    private const PASSWORD = 'ChangeMe12345';

    /** Executes the demo environment seeder operation. */
    public function run(): void
    {
        if (! config('vsn.demo.enabled', false)) return;

        $staff = [
            ['ops-admin@example.test','VSN Operations Admin',UserRole::Admin->value],
            ['finance@example.test','VSN Finance Officer',UserRole::Finance->value],
            ['support@example.test','VSN Support Agent',UserRole::Support->value],
            ['moderator@example.test','VSN Marketplace Moderator',UserRole::Moderator->value],
        ];
        foreach ($staff as [$email,$name,$role]) $this->user($email,$name,$role);

        $customers = [
            $this->user('customer2@example.test','Ayesha Khan',UserRole::Customer->value),
            $this->user('customer3@example.test','Hamza Ali',UserRole::Customer->value),
            $this->user('customer4@example.test','Sara Ahmed',UserRole::Customer->value),
            $this->user('customer5@example.test','Bilal Raza',UserRole::Customer->value),
        ];
        $primaryCustomer = User::where('email','customer@example.test')->firstOrFail();
        array_unshift($customers, $primaryCustomer);

        $sellers = [
            ['seller2@example.test','HomeHub Seller','homehub-pk','HomeHub PK'],
            ['seller3@example.test','UrbanStyle Seller','urbanstyle-pk','UrbanStyle PK'],
        ];
        $vendors = [];
        foreach ($sellers as [$email,$name,$slug,$vendorName]) {
            $seller = $this->user($email,$name,UserRole::Seller->value);
            $profile = $seller->profile()->firstOrCreate();
            $profile->forceFill(['phone'=>'+92 300 '.str_pad((string)$seller->id,7,'0',STR_PAD_LEFT),'phone_verified_at'=>now()])->save();
            $vendors[] = Vendor::updateOrCreate(['slug'=>$slug],['owner_user_id'=>$seller->id,'name'=>$vendorName,'status'=>'active','commission_bps'=>1200,'metadata'=>['seeded'=>true]]);
        }
        $primaryVendor = Vendor::where('slug','techzone-pk')->firstOrFail();
        array_unshift($vendors, $primaryVendor);

        $admin = User::where('email','admin@example.test')->firstOrFail();
        foreach ($vendors as $index => $vendor) {
            $owner = $vendor->owner;
            KycVerification::firstOrCreate(
                ['user_id'=>$owner->id,'type'=>'government_id'],
                ['public_id'=>(string)Str::uuid(),'status'=>$index === 2 ? 'pending' : 'approved','provider'=>'development_seed','document_number_cipher'=>'DEMO-ID-'.$owner->id,'document_number_last4'=>str_pad((string)$owner->id,4,'0',STR_PAD_LEFT),'country_code'=>'PK','submitted_at'=>now()->subDays(3),'reviewed_by_user_id'=>$index === 2 ? null : $admin->id,'reviewed_at'=>$index === 2 ? null : now()->subDays(2)]
            );
            VendorPayoutMethod::firstOrCreate(
                ['vendor_id'=>$vendor->id,'account_last4'=>str_pad((string)$vendor->id,4,'0',STR_PAD_LEFT)],
                ['public_id'=>(string)Str::ulid(),'type'=>'bank_transfer','label'=>'Demo Bank','account_holder_name'=>$vendor->name,'bank_name'=>'Demo Bank','account_identifier_cipher'=>'DEMO-ACCOUNT-'.$vendor->id,'routing_identifier_cipher'=>'DEMO-ROUTING-'.$vendor->id,'routing_last4'=>'0001','country_code'=>'PK','currency'=>'PKR','is_default'=>true,'verified_by_user_id'=>$index === 2 ? null : $admin->id,'verified_at'=>$index === 2 ? null : now()->subDay(),'metadata'=>['seeded'=>true]]
            );
        }

        $this->seedExtraCatalog($vendors);
        foreach ($customers as $i => $customer) $this->seedCustomerBasics($customer, $i);

        $products = Product::published()->with(['variants','vendor'])->orderBy('id')->take(8)->get();
        $statuses = [OrderStatus::Delivered, OrderStatus::Shipped, OrderStatus::Processing, OrderStatus::Confirmed, OrderStatus::Delivered];
        $orders = [];
        foreach ($customers as $i => $customer) {
            $product = $products[$i % max(1,$products->count())];
            $orders[] = $this->seedOrder($customer, $product, $statuses[$i % count($statuses)], $i);
        }

        if (isset($orders[0])) $this->seedReviewAndReport($orders[0], $customers[1] ?? $customers[0], $admin);
        if (isset($orders[4])) $this->seedReturn($orders[4]);
        $this->seedPayouts($vendors, $orders, $admin);
        $this->seedRisk($customers[2] ?? $primaryCustomer, $admin);
        $this->seedAffiliate($customers, $orders[0] ?? null);
        $this->seedGameEntry($primaryCustomer);
        $this->seedNotifications($customers);
    }

    /** Handles user for the demo environment seeder workflow. */
    private function user(string $email, string $name, string $role): User
    {
        $user = User::firstOrCreate(['email'=>$email],['name'=>$name,'password'=>Hash::make(self::PASSWORD),'role'=>$role]);
        $user->forceFill(['name'=>$name,'role'=>$role,'email_verified_at'=>$user->email_verified_at ?: now()])->save();
        $user->profile()->firstOrCreate([],['timezone'=>config('app.timezone','UTC')]);
        return $user;
    }

    /** Handles seed customer basics for the demo environment seeder workflow. */
    private function seedCustomerBasics(User $user, int $index): void
    {
        Address::firstOrCreate(['user_id'=>$user->id,'label'=>'Home'],['recipient_name'=>$user->name,'phone'=>'+92 301 555'.str_pad((string)$index,4,'0',STR_PAD_LEFT),'line1'=>(20+$index).' Demo Street','city'=>$index % 2 ? 'Lahore' : 'Karachi','state'=>'Punjab','postal_code'=>'54000','country_code'=>'PK','is_default'=>true]);
        Wallet::firstOrCreate(['user_id'=>$user->id],['balance_coins'=>500 + ($index*120),'reserved_coins'=>0]);
    }

    /** Handles seed extra catalog for the demo environment seeder workflow. */
    private function seedExtraCatalog(array $vendors): void
    {
        $warehouse = Warehouse::firstOrCreate(['code'=>'LHE-01'],['name'=>'Lahore Main Warehouse']);
        $category = Category::firstOrCreate(['slug'=>'demo-marketplace'],['name'=>'Demo Marketplace']);
        foreach (array_slice($vendors,1) as $i => $vendor) {
            $name = $i === 0 ? 'Smart Air Purifier Pro' : 'Premium Travel Backpack';
            $product = Product::firstOrCreate(['slug'=>Str::slug($name)],['public_id'=>(string)Str::ulid(),'vendor_id'=>$vendor->id,'category_id'=>$category->id,'sku'=>'VSN-DEMO-'.($i+20),'name'=>$name,'short_description'=>'Seeded marketplace product for role and workflow testing.','status'=>ProductStatus::Published,'currency'=>'PKR','base_price_minor'=>($i?18999:54999)*100,'compare_at_price_minor'=>($i?22999:61999)*100,'rating'=>4.4,'reviews_count'=>8,'sold_count'=>42,'metadata'=>['seeded'=>true]]);
            $variant = ProductVariant::firstOrCreate(['product_id'=>$product->id,'name'=>'Default'],['sku'=>$product->sku.'-DEFAULT','option_values'=>[],'is_default'=>true,'is_active'=>true]);
            Inventory::firstOrCreate(['warehouse_id'=>$warehouse->id,'product_variant_id'=>$variant->id],['on_hand'=>60,'reserved'=>3,'safety_stock'=>5]);
        }
    }

    /** Handles seed order for the demo environment seeder workflow. */
    private function seedOrder(User $user, Product $product, OrderStatus $status, int $index): Order
    {
        $existing = Order::query()->where('user_id',$user->id)->where('metadata->demoKey','ap-order-'.$index)->first();
        if ($existing) return $existing;
        $address = $user->addresses()->where('is_default',true)->firstOrFail();
        $cart = Cart::create(['public_id'=>(string)Str::ulid(),'user_id'=>$user->id,'status'=>CartStatus::Converted,'currency'=>'PKR','metadata'=>['seeded'=>true]]);
        $price = (int)$product->base_price_minor;
        $shipping = 50000;
        $session = CheckoutSession::create(['public_id'=>(string)Str::ulid(),'user_id'=>$user->id,'cart_id'=>$cart->id,'idempotency_key'=>'demo-checkout-'.$user->id.'-'.$index,'status'=>CheckoutStatus::Converted,'currency'=>'PKR','address_id'=>$address->id,'address_snapshot'=>$address->toArray(),'shipping_method'=>'standard','payment_method'=>$index===3?'cod':'card','subtotal_minor'=>$price,'shipping_minor'=>$shipping,'discount_minor'=>0,'coin_redemption_minor'=>0,'total_minor'=>$price+$shipping,'expires_at'=>now()->addHour(),'converted_at'=>now()->subDays(8-$index),'metadata'=>['seeded'=>true]]);
        $paid = $status !== OrderStatus::Confirmed || $index !== 3;
        $order = Order::create(['public_id'=>(string)Str::ulid(),'user_id'=>$user->id,'checkout_session_id'=>$session->id,'status'=>$status,'payment_status'=>$paid?PaymentStatus::Paid:PaymentStatus::Pending,'payment_method'=>$index===3?'cod':'card','currency'=>'PKR','subtotal_minor'=>$price,'shipping_minor'=>$shipping,'discount_minor'=>0,'coin_redemption_minor'=>0,'total_minor'=>$price+$shipping,'placed_at'=>now()->subDays(8-$index),'delivered_at'=>$status===OrderStatus::Delivered?now()->subDays(2):null,'metadata'=>['seeded'=>true,'demoKey'=>'ap-order-'.$index]]);
        $vendor = $product->vendor;
        $commission = intdiv($price * (int)$vendor->commission_bps,10000);
        $vo = VendorOrder::create(['public_id'=>(string)Str::ulid(),'order_id'=>$order->id,'vendor_id'=>$vendor->id,'status'=>$status,'currency'=>'PKR','subtotal_minor'=>$price,'shipping_minor'=>$shipping,'discount_minor'=>0,'total_minor'=>$price+$shipping,'commission_bps'=>$vendor->commission_bps,'platform_commission_minor'=>$commission,'seller_payable_minor'=>max(0,$price+$shipping-$commission),'packed_at'=>in_array($status,[OrderStatus::Packed,OrderStatus::Shipped,OrderStatus::OutForDelivery,OrderStatus::Delivered],true)?now()->subDays(4):null,'dispatched_at'=>in_array($status,[OrderStatus::Shipped,OrderStatus::OutForDelivery,OrderStatus::Delivered],true)?now()->subDays(3):null,'delivered_at'=>$status===OrderStatus::Delivered?now()->subDays(2):null,'metadata'=>['seeded'=>true]]);
        $variant = $product->variants()->where('is_active',true)->first();
        $item = OrderItem::create(['order_id'=>$order->id,'vendor_order_id'=>$vo->id,'product_id'=>$product->id,'product_variant_id'=>$variant?->id,'product_name'=>$product->name,'variant_name'=>$variant?->name ?? 'Default','sku'=>$variant?->sku ?? $product->sku,'quantity'=>1,'currency'=>'PKR','unit_price_minor'=>$price,'line_total_minor'=>$price,'selected_options'=>$variant?->option_values ?? [],'metadata'=>['seeded'=>true]]);
        OrderAddress::create(['order_id'=>$order->id,'type'=>'shipping','label'=>'Home','recipient_name'=>$address->recipient_name,'phone'=>$address->phone,'line1'=>$address->line1,'line2'=>$address->line2,'city'=>$address->city,'state'=>$address->state,'postal_code'=>$address->postal_code,'country_code'=>$address->country_code]);
        if ($status===OrderStatus::Delivered) VendorSettlement::firstOrCreate(['vendor_order_id'=>$vo->id],['public_id'=>(string)Str::ulid(),'vendor_id'=>$vendor->id,'currency'=>'PKR','gross_minor'=>$price+$shipping,'platform_commission_minor'=>$commission,'seller_payable_minor'=>max(0,$price+$shipping-$commission),'status'=>'available','eligible_at'=>now()->subDay(),'available_at'=>now()->subDay(),'metadata'=>['seeded'=>true]]);
        return $order->load('items','vendorOrders');
    }

    /** Handles seed review and report for the demo environment seeder workflow. */
    private function seedReviewAndReport(Order $order, User $reporter, User $moderator): void
    {
        $item = $order->items()->first(); if (! $item) return;
        $review = Review::firstOrCreate(['order_item_id'=>$item->id],['public_id'=>(string)Str::ulid(),'user_id'=>$order->user_id,'order_id'=>$order->id,'product_id'=>$item->product_id,'product_variant_id'=>$item->product_variant_id,'status'=>ReviewStatus::Approved,'rating'=>5,'body'=>'Excellent demo purchase. Delivery and packaging were both good.','verified_purchase'=>true,'submitted_at'=>now()->subDay(),'moderated_at'=>now()->subHours(20),'moderated_by'=>$moderator->id,'metadata'=>['seeded'=>true]]);
        ReviewReport::firstOrCreate(['review_id'=>$review->id,'user_id'=>$reporter->id],['public_id'=>(string)Str::ulid(),'reason'=>'misleading','details'=>'Seeded moderation queue example.','status'=>'pending']);
    }

    /** Handles seed return for the demo environment seeder workflow. */
    private function seedReturn(Order $order): void
    {
        if ($order->status !== OrderStatus::Delivered) return;
        $item=$order->items()->first(); if(!$item)return;
        $request=ReturnRequest::firstOrCreate(['user_id'=>$order->user_id,'order_id'=>$order->id],['public_id'=>(string)Str::ulid(),'status'=>ReturnRequestStatus::Submitted,'resolution'=>ReturnResolution::OriginalPayment,'reason'=>'not_as_expected','details'=>'Seeded return request awaiting review.','currency'=>'PKR','requested_minor'=>$item->line_total_minor,'approved_minor'=>0,'submitted_at'=>now()->subHours(8),'metadata'=>['seeded'=>true]]);
        ReturnRequestItem::firstOrCreate(['return_request_id'=>$request->id,'order_item_id'=>$item->id],['quantity'=>1,'requested_minor'=>$item->line_total_minor,'approved_minor'=>0,'restock'=>true,'metadata'=>['seeded'=>true]]);
    }

    /** Handles seed payouts for the demo environment seeder workflow. */
    private function seedPayouts(array $vendors, array $orders, User $admin): void
    {
        foreach (array_slice($vendors,0,2) as $i=>$vendor) {
            $settlement=VendorSettlement::where('vendor_id',$vendor->id)->where('status','available')->first(); if(!$settlement)continue;
            $method=VendorPayoutMethod::where('vendor_id',$vendor->id)->whereNotNull('verified_at')->first(); if(!$method)continue;
            $payout=VendorPayout::firstOrCreate(['idempotency_key'=>'demo-payout-'.$vendor->id],['public_id'=>(string)Str::ulid(),'vendor_id'=>$vendor->id,'vendor_payout_method_id'=>$method->id,'requested_by_user_id'=>$vendor->owner_user_id,'approved_by_user_id'=>$i===0?$admin->id:null,'status'=>$i===0?'approved':'requested','currency'=>'PKR','amount_minor'=>min(5000000,(int)$settlement->seller_payable_minor),'payout_method_snapshot'=>['type'=>'bank_transfer','last4'=>$method->account_last4,'label'=>$method->label],'approved_at'=>$i===0?now()->subHour():null,'metadata'=>['seeded'=>true]]);
            VendorPayoutItem::firstOrCreate(['vendor_payout_id'=>$payout->id,'vendor_settlement_id'=>$settlement->id],['amount_minor'=>$payout->amount_minor]);
            $settlement->forceFill(['payout_reserved_minor'=>$payout->amount_minor,'status'=>VendorSettlementStatus::PayoutPending])->save();
        }
    }

    /** Handles seed risk for the demo environment seeder workflow. */
    private function seedRisk(User $user, User $admin): void
    {
        $profile=RiskProfile::firstOrCreate(['user_id'=>$user->id],['public_id'=>(string)Str::ulid(),'score'=>72,'level'=>'high','status'=>'review','signal_summary'=>['demo_velocity'=>true,'failed_payments'=>3],'last_evaluated_at'=>now()->subHours(2)]);
        $case=RiskCase::firstOrCreate(['user_id'=>$user->id,'title'=>'Demo payment velocity review'],['public_id'=>(string)Str::ulid(),'assigned_to_user_id'=>$admin->id,'status'=>'open','priority'=>'high','summary'=>'Seeded risk case for admin workflow testing.','score_at_open'=>72,'metadata'=>['seeded'=>true],'opened_at'=>now()->subHours(2)]);
        RiskHold::firstOrCreate(['risk_case_id'=>$case->id,'scope'=>'checkout'],['public_id'=>(string)Str::ulid(),'user_id'=>$user->id,'created_by_user_id'=>$admin->id,'status'=>'active','reason'=>'Demo checkout hold for manual review.','starts_at'=>now()->subHours(2),'expires_at'=>now()->addDay(),'metadata'=>['seeded'=>true]]);
    }

    /** Handles seed affiliate for the demo environment seeder workflow. */
    private function seedAffiliate(array $customers, ?Order $order): void
    {
        if (count($customers)<2)return;
        $parent=$customers[0];$child=$customers[1];
        $account=AffiliateAccount::firstOrCreate(['user_id'=>$parent->id],['referral_code'=>'VSNDEMO'.$parent->id,'status'=>'active','terms_version'=>'demo-1','terms_accepted_at'=>now()->subMonth(),'metadata'=>['seeded'=>true]]);
        AffiliateRelationship::firstOrCreate(['user_id'=>$child->id],['parent_user_id'=>$parent->id,'referral_account_id'=>$account->id,'joined_at'=>now()->subWeeks(2),'metadata'=>['seeded'=>true]]);
        if($order) AffiliateCommission::firstOrCreate(['order_id'=>$order->id,'level_no'=>1],['public_id'=>(string)Str::ulid(),'buyer_id'=>$order->user_id,'beneficiary_id'=>$parent->id,'rate_bps'=>200,'currency'=>'PKR','eligible_spend_minor'=>$order->subtotal_minor,'reward_coins'=>120,'status'=>'available','available_at'=>now()->subHour(),'metadata'=>['seeded'=>true]]);
    }

    /** Handles seed game entry for the demo environment seeder workflow. */
    private function seedGameEntry(User $user): void
    {
        $game=Game::where('status','open')->first(); if(!$game)return;
        if(GameEntry::where('game_id',$game->id)->where('user_id',$user->id)->exists())return;
        $wallet=Wallet::firstOrCreate(['user_id'=>$user->id],['balance_coins'=>1000,'reserved_coins'=>0]);
        if($wallet->balance_coins<70)$wallet->forceFill(['balance_coins'=>1000])->save();
        $after=$wallet->balance_coins-70;
        $tx=WalletTransaction::create(['public_id'=>(string)Str::ulid(),'initiated_by_user_id'=>$user->id,'type'=>'game_entry','status'=>'posted','idempotency_key'=>'demo-game-entry-'.$game->id.'-'.$user->id,'reference_type'=>'game','reference_id'=>$game->public_id,'metadata'=>['seeded'=>true],'occurred_at'=>now()->subHour()]);
        WalletEntry::create(['wallet_transaction_id'=>$tx->id,'wallet_id'=>$wallet->id,'user_id'=>$user->id,'direction'=>'debit','coins'=>70,'balance_after_coins'=>$after,'metadata'=>['seeded'=>true]]);
        $wallet->forceFill(['balance_coins'=>$after])->save();
        GameEntry::create(['public_id'=>(string)Str::ulid(),'game_id'=>$game->id,'user_id'=>$user->id,'quantity'=>1,'coins_spent'=>70,'idempotency_key'=>'demo-game-entry-'.$game->id.'-'.$user->id,'wallet_transaction_id'=>$tx->id,'rules_version'=>$game->rules_version,'consented_at'=>now()->subHour()]);
        $game->increment('total_entries');
    }

    /** Handles seed notifications for the demo environment seeder workflow. */
    private function seedNotifications(array $customers): void
    {
        foreach(array_slice($customers,0,3) as $i=>$user){
            MarketplaceNotification::firstOrCreate(['dedup_key'=>'demo-notification-'.$user->id],['public_id'=>(string)Str::uuid(),'user_id'=>$user->id,'category'=>$i===0?'orders':'promotions','type'=>'demo.seeded','title'=>$i===0?'Your demo order is ready to track':'Welcome to VSN Ecommerce','body'=>'Seeded notification for inbox and role-flow testing.','action_url'=>$i===0?'/account/orders':'/deals','data'=>['seeded'=>true],'in_app_visible'=>true]);
        }
    }
}
