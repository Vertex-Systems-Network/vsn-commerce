<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Actions\EvaluateProductAlerts;
use App\Enums\InventoryMovementType;
use App\Enums\ProductStatus;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\ProductVariant;
use App\Models\TaxClass;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Defines the CatalogMutationService class and its project responsibilities. */
class CatalogMutationService
{
    /** Initializes the CatalogMutationService instance and its dependencies. */
    public function __construct(private readonly EvaluateProductAlerts $alerts, private readonly CatalogCache $cache) {}

    /** Creates product business data; media is attached separately through managed media services. */
    public function create(Vendor $vendor, User $actor, array $data, bool $admin = false): Product
    {
        $result = DB::transaction(/** Inline callback for this operation. */ function () use ($vendor, $actor, $data, $admin) {
            $slug = $this->uniqueSlug($data['slug'] ?? $data['name']);
            $product = Product::create(['public_id' => (string) Str::ulid(), 'vendor_id' => $vendor->id, 'category_id' => $data['categoryId'] ?? null, 'sku' => $data['sku'] ?? null, 'slug' => $slug, 'name' => $data['name'], 'short_description' => $data['shortDescription'] ?? null, 'description' => $data['description'] ?? null, 'status' => $admin ? ($data['status'] ?? ProductStatus::Published->value) : ProductStatus::Draft->value, 'currency' => $data['currency'] ?? config('vsn.currency', 'PKR'), 'base_price_minor' => (int) $data['basePriceMinor'], 'compare_at_price_minor' => $data['compareAtPriceMinor'] ?? null, 'installment_enabled' => (bool) ($data['installmentEnabled'] ?? false), 'game_enabled' => (bool) ($data['gameEnabled'] ?? false), 'tax_class_id' => ! empty($data['taxClassId']) ? TaxClass::query()->where('public_id', $data['taxClassId'])->value('id') : null, 'price_includes_tax' => $data['priceIncludesTax'] ?? null, 'metadata' => $data['metadata'] ?? []]);
            $this->recordPrice($product, null, $actor, $admin ? 'admin' : 'seller');
            $this->syncVariants($product, $actor, $data['variants'] ?? [], $admin);

            return $product->load(['vendor', 'category', 'taxClass', 'images.mediaAsset', 'variants.inventories']);
        }, 3);
        $this->cache->bump();

        return $result;
    }

    /** Updates product business data without accepting direct image URLs. */
    public function update(Product $product, User $actor, array $data, bool $admin = false): Product
    {
        $oldPrice = $product->base_price_minor;
        $result = DB::transaction(/** Inline callback for this operation. */ function () use ($product, $actor, $data, $admin, $oldPrice) {
            $fields = [];
            $map = ['categoryId' => 'category_id', 'sku' => 'sku', 'name' => 'name', 'shortDescription' => 'short_description', 'description' => 'description', 'currency' => 'currency', 'basePriceMinor' => 'base_price_minor', 'compareAtPriceMinor' => 'compare_at_price_minor', 'installmentEnabled' => 'installment_enabled', 'gameEnabled' => 'game_enabled', 'priceIncludesTax' => 'price_includes_tax', 'metadata' => 'metadata'];
            foreach ($map as $in => $db) {
                if (array_key_exists($in, $data)) {
                    $fields[$db] = $data[$in];
                }
            }if (array_key_exists('taxClassId', $data)) {
                $fields['tax_class_id'] = ! empty($data['taxClassId']) ? TaxClass::query()->where('public_id', $data['taxClassId'])->value('id') : null;
            }
            if (array_key_exists('slug', $data)) {
                $fields['slug'] = $this->uniqueSlug($data['slug'] ?: ($data['name'] ?? $product->name), $product->id);
            }
            if ($admin && array_key_exists('status', $data)) {
                $fields['status'] = $data['status'];
            } elseif (! $admin && $product->status === ProductStatus::Published) {
                $fields['status'] = ProductStatus::PendingReview->value;
            }
            if ($fields) {
                $product->update($fields);
            }
            if ((int) $oldPrice !== (int) $product->fresh()->base_price_minor) {
                $this->recordPrice($product->fresh(), null, $actor, $admin ? 'admin' : 'seller');
            }
            if (array_key_exists('variants', $data)) {
                $this->syncVariants($product, $actor, $data['variants'] ?? [], $admin);
            }

            return $product->fresh(['vendor', 'category', 'taxClass', 'images.mediaAsset', 'variants.inventories']);
        }, 3);
        $this->alerts->execute($result);
        $this->cache->bump();

        return $result;
    }

    /** Handles submit for the catalog mutation service workflow. */
    public function submit(Product $product): Product
    {
        abort_unless(in_array($product->status, [ProductStatus::Draft, ProductStatus::PendingReview], true), 422, 'Only draft or pending-review products can be submitted.');
        $product->update(['status' => ProductStatus::PendingReview]);
        $this->cache->bump();

        return $product->fresh(['vendor', 'category', 'taxClass', 'images.mediaAsset', 'variants.inventories']);
    }

    /** Updates stock. */
    public function setStock(ProductVariant $variant, User $actor, int $onHand, ?int $safetyStock = null, string $reason = 'catalog_adjustment'): Inventory
    {
        abort_unless($onHand >= 0, 422, 'Stock cannot be negative.');
        $inventory = DB::transaction(/** Inline callback for this operation. */ function () use ($variant, $actor, $onHand, $safetyStock, $reason) {
            $warehouse = Warehouse::query()->where('is_active', true)->orderBy('id')->first() ?? Warehouse::query()->create(['code' => 'MAIN', 'name' => 'Main Warehouse', 'is_active' => true]);
            $row = Inventory::query()->firstOrCreate(['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id], ['on_hand' => 0, 'reserved' => 0, 'safety_stock' => 0]);
            $row = Inventory::query()->whereKey($row->id)->lockForUpdate()->first();
            $old = $row->on_hand;
            $changes = ['on_hand' => $onHand];
            if ($safetyStock !== null) {
                $changes['safety_stock'] = max(0, $safetyStock);
            }$row->update($changes);
            $delta = $onHand - $old;
            if ($delta !== 0) {
                InventoryMovement::create(['inventory_id' => $row->id, 'type' => InventoryMovementType::Adjustment, 'on_hand_delta' => $delta, 'reserved_delta' => 0, 'reference_type' => 'catalog_adjustment', 'reference_id' => (string) $actor->id, 'metadata' => ['reason' => $reason, 'actor_user_id' => $actor->id]]);
            }

return $row->fresh();
        }, 3);
        $this->alerts->execute($variant->product);
        $this->cache->bump();

        return $inventory;
    }

    /** Handles sync variants for the catalog mutation service workflow. */
    private function syncVariants(Product $product, User $actor, array $rows, bool $admin): void
    {
        $provided = array_values(array_filter(array_map(/** Inline callback for this operation. */ fn ($r) => trim((string) ($r['sku'] ?? '')), $rows)));
        if (count($provided) !== count(array_unique(array_map('strtolower', $provided)))) {
            throw ValidationException::withMessages(['variants' => ['Variant SKUs must be unique within the product payload.']]);
        }
        foreach ($rows as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }$existing = ProductVariant::query()->where('sku', $sku)->when(! empty($row['id']), /** Inline callback for this operation. */ fn ($q) => $q->where('id', '!=', (int) $row['id']))->exists();
            if ($existing) {
                throw ValidationException::withMessages(['variants' => ["Variant SKU {$sku} is already in use."]]);
            }
        }
        $seen = [];
        foreach ($rows as $i => $row) {
            $id = $row['id'] ?? null;
            $variant = $id ? ProductVariant::query()->where('product_id', $product->id)->whereKey($id)->first() : null;
            $old = $variant?->price_minor;
            $payload = ['sku' => $row['sku'] ?? ($product->sku ? ($product->sku.'-'.($i + 1)) : 'VSNV-'.strtoupper(Str::random(10))), 'name' => $row['name'] ?? ('Variant '.($i + 1)), 'option_values' => $row['options'] ?? [], 'price_minor' => $row['priceMinor'] ?? null, 'compare_at_price_minor' => $row['compareAtPriceMinor'] ?? null, 'is_default' => (bool) ($row['isDefault'] ?? $i === 0), 'is_active' => (bool) ($row['isActive'] ?? true)];
            if ($variant) {
                $variant->update($payload);
            } else {
                $variant = $product->variants()->create($payload);
            }$seen[] = $variant->id;
            if ($old !== $variant->price_minor || ! $id) {
                $this->recordPrice($product, $variant, $actor, $admin ? 'admin' : 'seller');
            }
            if (array_key_exists('stock', $row)) {
                $this->setStock($variant, $actor, (int) $row['stock'], isset($row['safetyStock']) ? (int) $row['safetyStock'] : null, 'variant_sync');
            }
        }
        if ($rows) {
            $product->variants()->whereNotIn('id', $seen)->update(['is_active' => false, 'is_default' => false]);
        }
        if (! $product->variants()->where('is_active', true)->where('is_default', true)->exists()) {
            $first = $product->variants()->where('is_active', true)->orderBy('id')->first();
            if ($first) {
                $first->update(['is_default' => true]);
            }
        }
    }

    /** Handles record price for the catalog mutation service workflow. */
    private function recordPrice(Product $product, ?ProductVariant $variant, User $actor, string $source): void
    {
        ProductPriceHistory::create(['product_id' => $product->id, 'product_variant_id' => $variant?->id, 'price_minor' => (int) ($variant?->price_minor ?? $product->base_price_minor), 'compare_at_price_minor' => $variant?->compare_at_price_minor ?? $product->compare_at_price_minor, 'source' => $source, 'changed_by_user_id' => $actor->id, 'metadata' => ['product_status' => $product->status->value], 'recorded_at' => now()]);
    }

    /** Handles unique slug for the catalog mutation service workflow. */
    private function uniqueSlug(string $value, ?int $exceptId = null): string
    {
        $base = Str::slug($value) ?: 'product';
        $slug = $base;
        $n = 2;
        while (Product::query()->withTrashed()->where('slug',$slug)->when($exceptId,/** Inline callback for this operation. */ fn ($q) => $q->where('id','!=',$exceptId))->exists()) {
            $slug = $base.'-'.$n++;
        }

return $slug;
    }
}
