<?php

namespace App\Domain\Shipping\Actions;

use App\Domain\Shipping\Exceptions\ShippingException;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\VendorOrder;
use Illuminate\Support\Facades\DB;

/** Defines the PackVendorOrder class and its project responsibilities. */
class PackVendorOrder
{
    /** Initializes the PackVendorOrder instance and its dependencies. */
    public function __construct(private readonly ReconcileOrderFulfillment $reconcile)
    {
    }

    /** Executes the pack vendor order operation. */
    public function execute(VendorOrder $vendorOrder): VendorOrder
    {
        $masterOrder = null;

        $vendorOrder = DB::transaction(/** Inline callback for this operation. */ function () use ($vendorOrder, &$masterOrder): VendorOrder {
            $vo = VendorOrder::query()
                ->whereKey($vendorOrder->id)
                ->lockForUpdate()
                ->with('order')
                ->firstOrFail();

            $masterOrder = $vo->order;
            if (! $masterOrder instanceof Order) {
                throw new ShippingException('The seller order has no master order.');
            }

            if ($masterOrder->payment_method !== 'cod' && $masterOrder->payment_status !== PaymentStatus::Paid) {
                throw new ShippingException('Online-payment orders cannot be packed before verified payment.');
            }

            if (in_array($vo->status, [OrderStatus::Cancelled, OrderStatus::Returned, OrderStatus::Refunded, OrderStatus::Delivered], true)) {
                throw new ShippingException('This seller order can no longer be packed.');
            }

            if (! $vo->packed_at) {
                $vo->update([
                    'status' => OrderStatus::Packed,
                    'packed_at' => now(),
                ]);
            }

            return $vo->fresh(['order', 'vendor', 'items']);
        }, 3);

        if ($masterOrder) {
            $this->reconcile->execute($masterOrder);
        }

        return $vendorOrder->fresh(['order', 'vendor', 'items']);
    }
}
