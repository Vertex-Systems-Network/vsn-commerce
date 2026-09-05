<?php

namespace Database\Seeders;

use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Game;
use App\Models\KycVerification;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayoutMethod;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/** Defines the DatabaseSeeder class and its project responsibilities. */
class DatabaseSeeder extends Seeder
{
    /** Executes the database seeder operation. */
    public function run(): void
    {
        // This seeder is intentionally demo-only. Production must never receive
        // predictable demo users, passwords, catalog rows or payout data.
        $demoEnabled = (bool) config('vsn.demo.enabled', false);

        if (app()->environment('production')) {
            if ($demoEnabled) {
                throw new RuntimeException(
                    'Refusing to run VSN demo seed in production while VSN_DEMO_SEED_ENABLED is enabled.'
                );
            }

            $this->command?->info('VSN demo seed skipped because the application environment is production.');
            return;
        }

        if (! $demoEnabled) {
            $this->command?->info('VSN demo seed skipped because VSN_DEMO_SEED_ENABLED is false.');
            return;
        }

        $customer = User::firstOrCreate(
            ['email' => 'customer@example.test'],
            ['name' => 'VSN Demo Customer', 'password' => Hash::make('ChangeMe12345'), 'role' => 'customer']
        );
        $customer->forceFill(['email_verified_at' => $customer->email_verified_at ?: now()])->save();
        $customer->profile()->firstOrCreate([], ['timezone' => config('app.timezone', 'UTC')]);

        $admin = User::firstOrCreate(
            ['email' => 'admin@example.test'],
            ['name' => 'VSN Super Admin', 'password' => Hash::make('ChangeMe12345'), 'role' => 'super_admin']
        );
        $admin->forceFill(['role' => 'super_admin', 'email_verified_at' => $admin->email_verified_at ?: now()])->save();
        $admin->profile()->firstOrCreate([], ['timezone' => config('app.timezone', 'UTC')]);

        $seller = User::firstOrCreate(
            ['email' => 'seller@example.test'],
            [
                'name' => 'VSN Demo Seller',
                'password' => Hash::make('ChangeMe12345'),
                'role' => 'seller',
            ]
        );

        $seller->forceFill(['role' => 'seller', 'email_verified_at' => $seller->email_verified_at ?: now()])->save();
        $sellerProfile=$seller->profile()->firstOrCreate([], ['timezone' => config('app.timezone', 'UTC')]);
        $sellerProfile->forceFill(['phone'=>$sellerProfile->phone ?: '+92 300 0000000','phone_verified_at'=>$sellerProfile->phone_verified_at ?: now()])->save();

        $vendor = Vendor::firstOrCreate(
            ['slug' => 'techzone-pk'],
            [
                'owner_user_id' => $seller->id,
                'name' => 'TechZone PK',
                'status' => 'active',
                'commission_bps' => 1000,
            ]
        );

        KycVerification::firstOrCreate(
            ['user_id'=>$seller->id,'type'=>'government_id','status'=>'approved'],
            ['public_id'=>(string) Str::uuid(),'provider'=>'development_seed','document_number_cipher'=>'DEMO-SELLER-ID-0001','document_number_last4'=>'0001','country_code'=>'PK','submitted_at'=>now(),'reviewed_by_user_id'=>$admin->id,'reviewed_at'=>now()]
        );

        $vendor->forceFill([
            'owner_user_id' => $seller->id,
            'status' => 'active',
            'metadata' => array_merge([
                'supportEmail' => 'seller-support@example.test',
                'supportPhone' => '+92 300 0000000',
                'returnAddress' => 'Demo return warehouse, Lahore, PK',
                'dispatchNote' => 'Demo seller account for local development only.',
            ], $vendor->metadata ?? []),
        ])->save();

        VendorPayoutMethod::firstOrCreate(
            ['vendor_id'=>$vendor->id,'account_last4'=>'0001'],
            ['public_id'=>(string) Str::ulid(),'type'=>'bank_transfer','label'=>'Demo bank','account_holder_name'=>'VSN Demo Seller','bank_name'=>'Demo Bank','account_identifier_cipher'=>'DEMO-ACCOUNT-0001','routing_identifier_cipher'=>'DEMO-ROUTING-0001','routing_last4'=>'0001','country_code'=>'PK','currency'=>'PKR','is_default'=>true,'verified_by_user_id'=>$admin->id,'verified_at'=>now(),'metadata'=>['seeded'=>true]]
        );

        $warehouse = Warehouse::firstOrCreate(
            ['code' => 'LHE-01'],
            ['name' => 'Lahore Main Warehouse']
        );

        $images = [
            'iphone-16-pro-max-titanium' => 'https://images.unsplash.com/photo-1695048132590-b687e2a7e2aa?w=700&h=700&fit=crop&auto=format',
            'macbook-pro-16-m4' => 'https://images.unsplash.com/photo-1517336714731-489689fd1ca8?w=700&h=700&fit=crop&auto=format',
            'samsung-neo-qled-8k-tv' => 'https://images.unsplash.com/photo-1593359677879-a4bb92f829e1?w=700&h=700&fit=crop&auto=format',
            'sony-wh-1000xm6-headphones' => 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=700&h=700&fit=crop&auto=format',
            'rolex-submariner-date' => 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=700&h=700&fit=crop&auto=format',
            'ps5-console-bundle-pro' => 'https://images.unsplash.com/photo-1606813907291-d86efa9b94db?w=700&h=700&fit=crop&auto=format',
            'air-jordan-1-retro-high-og' => 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?w=700&h=700&fit=crop&auto=format',
            'dji-air-3s-drone-fly-more' => 'https://images.unsplash.com/photo-1473968512647-3e447244af8f?w=700&h=700&fit=crop&auto=format',
        ];

        $catalog = [
            ['Mobiles', 'iPhone 16 Pro Max Titanium', 289999, 329999, 248, true, true, ['Natural Titanium','Black','White','Blue'], ['256GB','512GB','1TB']],
            ['Laptops', 'MacBook Pro 16 M4', 699999, 759999, 84, true, false, ['Space Black','Silver'], ['512GB','1TB','2TB']],
            ['Home', 'Samsung Neo QLED 8K TV', 849999, 999999, 31, true, true, ['Graphite','Silver'], ['75 inch','85 inch']],
            ['Gaming', 'Sony WH-1000XM6 Headphones', 89999, 109999, 164, true, true, ['Black','Platinum Silver'], ['Standard']],
            ['Watches', 'Rolex Submariner Date', 2199999, 2399999, 9, true, false, ['Oystersteel'], ['41mm']],
            ['Gaming', 'PS5 Console Bundle Pro', 149999, 169999, 92, true, true, ['White'], ['Disc Edition','Digital Edition']],
            ['Fashion', 'Air Jordan 1 Retro High OG', 34999, 42999, 127, false, true, ['Chicago','Black/White'], ['EU 40','EU 41','EU 42','EU 43']],
            ['Gaming', 'DJI Air 3S Drone Fly More', 249999, 289999, 42, true, true, ['Grey'], ['Fly More Combo']],
        ];

        foreach ($catalog as [$categoryName, $name, $priceRs, $compareRs, $stock, $installment, $game, $colors, $variants]) {
            $category = Category::firstOrCreate(
                ['slug' => Str::slug($categoryName)],
                ['name' => $categoryName]
            );

            $product = Product::firstOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'public_id' => (string) Str::ulid(),
                    'vendor_id' => $vendor->id,
                    'category_id' => $category->id,
                    'sku' => 'VSN-'.strtoupper(Str::random(8)),
                    'name' => $name,
                    'status' => ProductStatus::Published,
                    'currency' => 'PKR',
                    'base_price_minor' => $priceRs * 100,
                    'compare_at_price_minor' => $compareRs * 100,
                    'installment_enabled' => $installment,
                    'game_enabled' => $game,
                    'metadata' => $game ? ['gameEntryCoins' => 70] : [],
                ]
            );

            if (isset($images[$product->slug])) {
                ProductImage::firstOrCreate(
                    ['product_id' => $product->id, 'sort_order' => 0],
                    ['url' => $images[$product->slug], 'alt_text' => $product->name]
                );
            }

            $combinations = [];
            foreach ($colors as $color) {
                foreach ($variants as $variantName) {
                    $combinations[] = [$color, $variantName];
                }
            }

            $combinationCount = max(1, count($combinations));
            $baseStock = intdiv($stock, $combinationCount);
            $remainder = $stock % $combinationCount;
            $combinationNames = array_map(
                /** Inline callback for this operation. */ fn (array $combination) => $combination[0].' / '.$combination[1],
                $combinations
            );

            // Converge old Milestone A storage-only demo variants without deleting rows that
            // may already be referenced by test carts or reservations.
            $product->variants()->update(['is_default' => false]);
            $product->variants()->whereNotIn('name', $combinationNames)->update(['is_active' => false]);

            foreach ($combinations as $index => [$color, $variantName]) {
                $combinationName = $color.' / '.$variantName;
                $variant = $product->variants()->updateOrCreate(
                    ['name' => $combinationName],
                    [
                        'sku' => $product->sku.'-OPT-'.($index + 1),
                        'option_values' => ['color' => $color, 'variant' => $variantName],
                        'is_default' => $index === 0,
                        'is_active' => true,
                    ]
                );

                Inventory::firstOrCreate(
                    [
                        'warehouse_id' => $warehouse->id,
                        'product_variant_id' => $variant->id,
                    ],
                    [
                        'on_hand' => $baseStock + ($index < $remainder ? 1 : 0),
                        'reserved' => 0,
                        'safety_stock' => 0,
                    ]
                );
            }

            if ($game) {
                $secret = bin2hex(random_bytes(32));
                Game::firstOrCreate(
                    ['product_id' => $product->id, 'rules_version' => '2026-08-demo'],
                    [
                        'public_id' => (string) Str::ulid(),
                        'status' => 'open',
                        'entry_coins' => 70,
                        'max_entries' => 100000,
                        'total_entries' => 0,
                        'opens_at' => now()->subMinute(),
                        'closes_at' => now()->addHours(12),
                        'announcement_at' => now()->addHours(18),
                        'commitment_hash' => hash('sha256', $secret),
                        'draw_secret_ciphertext' => Crypt::encryptString($secret),
                        'metadata' => ['seeded' => true],
                    ]
                );
            }
        }

        $this->call(DemoEnvironmentSeeder::class);
    }
}
