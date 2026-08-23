<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Services\VendorStorefrontMediaService;
use App\Domain\Finance\Services\VendorFinanceService;
use App\Domain\Finance\Services\VendorResolver;
use App\Http\Controllers\Controller;
use App\Http\Resources\ShipmentResource;
use App\Models\Inventory;
use App\Models\ReturnRequest;
use App\Models\VendorOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/** Defines the SellerOperationsController class and its project responsibilities. */
class SellerOperationsController extends Controller
{
    /** Initializes the SellerOperationsController instance and its dependencies. */
    public function __construct(
        private readonly VendorResolver $vendors,
        private readonly VendorStorefrontMediaService $storefrontMedia,
    ) {}

    /** Handles overview for the seller operations controller workflow. */
    public function overview(Request $request, VendorFinanceService $finance): JsonResponse
    {
        $vendor = $this->vendors->forUser($request->user());
        $products = $vendor->products()->with('variants.inventories')->get();
        $orders = $vendor->vendorOrders()->get();
        $returns = $this->returnQuery($vendor->id)->count();
        $shipments = $vendor->shipments()->get();
        $availableUnits = 0;
        $lowStock = 0;
        foreach ($products as $product) {
            foreach ($product->variants as $variant) {
                $available = $variant->inventories->sum(/** Inline callback for this operation. */ fn (Inventory $row) => $row->available());
                $availableUnits += $available;
                if ($variant->is_active && $available <= max(2, (int) $variant->inventories->sum('safety_stock'))) {
                    $lowStock++;
                }
            }
        }
        $kyc = $request->user()->kycVerifications()->latest()->get();
        $financeData = $finance->summary($vendor);

        return response()->json(['data' => [
            'vendor' => $this->vendorRow($vendor),
            'metrics' => [
                'products' => $products->count(),
                'publishedProducts' => $products->filter(/** Inline callback for this operation. */ fn ($product) => $product->status->value === 'published')->count(),
                'availableUnits' => $availableUnits,
                'lowStockVariants' => $lowStock,
                'orders' => $orders->count(),
                'openOrders' => $orders->filter(/** Inline callback for this operation. */ fn ($order) => ! in_array($order->status->value, ['delivered', 'cancelled', 'returned', 'refunded'], true))->count(),
                'returns' => $returns,
                'shipments' => $shipments->count(),
                'availablePayoutMinor' => (int) ($financeData['availableMinor'] ?? 0),
                'pendingPayoutMinor' => (int) ($financeData['pendingMinor'] ?? 0),
            ],
            'verification' => [
                'emailVerified' => (bool) $request->user()->email_verified_at,
                'phoneVerified' => (bool) $request->user()->profile?->phone_verified_at,
                'governmentId' => $kyc->firstWhere('type.value', 'government_id')?->status?->value,
                'addressProof' => $kyc->firstWhere('type.value', 'address_proof')?->status?->value,
            ],
            'recentOrders' => $vendor->vendorOrders()->with(['order.user', 'items', 'shipments'])->latest()->limit(6)->get()->map(/** Inline callback for this operation. */ fn ($row) => $this->orderRow($row, false))->values(),
            'recentShipments' => ShipmentResource::collection($vendor->shipments()->with(['order', 'vendorOrder.vendor', 'items.orderItem', 'events'])->latest()->limit(5)->get())->resolve($request),
        ]]);
    }

    /** Handles orders for the seller operations controller workflow. */
    public function orders(Request $request): JsonResponse
    {
        $vendor = $this->vendors->forUser($request->user());
        $status = trim((string) $request->query('status'));
        $query = $vendor->vendorOrders()->with(['order.user', 'order.shippingAddress', 'items', 'shipments.events'])->latest();
        if ($status !== '') {
            $query->where('status', $status);
        }
        $rows = $query->paginate(min(100, max(10, (int) $request->query('perPage', 30))));

        return response()->json(['data' => [
            'items' => collect($rows->items())->map(/** Inline callback for this operation. */ fn ($row) => $this->orderRow($row, false))->values(),
            'pagination' => ['currentPage' => $rows->currentPage(), 'lastPage' => $rows->lastPage(), 'perPage' => $rows->perPage(), 'total' => $rows->total()],
        ]]);
    }

    /** Handles order for the seller operations controller workflow. */
    public function order(Request $request, VendorOrder $vendorOrder): JsonResponse
    {
        $vendor = $this->vendors->forUser($request->user());
        abort_unless($vendorOrder->vendor_id === $vendor->id, 404);
        $vendorOrder->load(['order.user', 'order.shippingAddress', 'items', 'shipments.items.orderItem', 'shipments.events', 'settlement']);

        return response()->json(['data' => $this->orderRow($vendorOrder, true)]);
    }

    /** Handles returns for the seller operations controller workflow. */
    public function returns(Request $request): JsonResponse
    {
        $vendor = $this->vendors->forUser($request->user());
        $rows = $this->returnQuery($vendor->id)
            ->with(['order', 'items.orderItem.vendorOrder', 'refund', 'dispute'])
            ->latest('submitted_at')->limit(100)->get();

        return response()->json(['data' => $rows->map(/** Inline callback for this operation. */ fn (ReturnRequest $row) => $this->returnRow($row, $vendor->id))->values()]);
    }

    /** Handles return show for the seller operations controller workflow. */
    public function returnShow(Request $request, ReturnRequest $returnRequest): JsonResponse
    {
        $vendor = $this->vendors->forUser($request->user());
        $returnRequest->load(['order', 'items.orderItem.vendorOrder', 'refund', 'dispute']);
        abort_unless($returnRequest->items->contains(/** Inline callback for this operation. */ fn ($item) => $item->orderItem?->vendorOrder?->vendor_id === $vendor->id), 404);

        return response()->json(['data' => $this->returnRow($returnRequest, $vendor->id)]);
    }

    /** Handles return feedback for the seller operations controller workflow. */
    public function returnFeedback(Request $request, ReturnRequest $returnRequest): JsonResponse
    {
        $vendor = $this->vendors->forUser($request->user());
        $returnRequest->load(['items.orderItem.vendorOrder']);
        abort_unless($returnRequest->items->contains(/** Inline callback for this operation. */ fn ($item) => $item->orderItem?->vendorOrder?->vendor_id === $vendor->id), 404);
        $data = $request->validate([
            'recommendation' => 'required|in:accept,inspect,reject,needs_information',
            'note' => 'nullable|string|max:2000',
        ]);
        $metadata = $returnRequest->metadata ?? [];
        $feedback = (array) ($metadata['seller_feedback'] ?? []);
        $feedback[(string) $vendor->id] = [
            'vendorId' => $vendor->id,
            'vendorName' => $vendor->name,
            'recommendation' => $data['recommendation'],
            'note' => $data['note'] ?? null,
            'updatedAt' => now()->toIso8601String(),
            'updatedByUserId' => $request->user()->id,
        ];
        $metadata['seller_feedback'] = $feedback;
        $returnRequest->forceFill(['metadata' => $metadata])->save();

        return response()->json(['data' => $this->returnRow($returnRequest->fresh()->load(['order', 'items.orderItem.vendorOrder', 'refund', 'dispute']), $vendor->id)]);
    }

    /** Returns seller-owned operational and public-storefront settings. */
    public function settings(Request $request): JsonResponse
    {
        $vendor = $this->vendors->forUser($request->user());

        return response()->json(['data' => ['vendor' => $this->vendorRow($vendor), 'profile' => [
            'ownerName' => $request->user()->name,
            'ownerEmail' => $request->user()->email,
            'phone' => $request->user()->profile?->phone,
        ]]]);
    }

    /** Updates seller settings and persists the logo by stable Media Library reference. */
    public function updateSettings(Request $request): JsonResponse
    {
        $vendor = $this->vendors->forUser($request->user());
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:160',
            'shopSlug' => ['sometimes', 'required', 'string', 'min:3', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('vendors', 'slug')->ignore($vendor->id)],
            'storefrontEnabled' => 'sometimes|required|boolean',
            'storefrontHeadline' => 'sometimes|nullable|string|max:190',
            'storefrontDescription' => 'sometimes|nullable|string|max:2000',
            'supportEmail' => 'sometimes|nullable|email|max:190',
            'publicSupportEmail' => 'sometimes|nullable|email|max:190',
            'supportPhone' => 'sometimes|nullable|string|max:40',
            'logoMediaAssetId' => 'sometimes|nullable|string|max:26',
            'logoUrl' => 'sometimes|nullable|string|max:2048',
            'returnAddress' => 'sometimes|nullable|string|max:1000',
            'dispatchNote' => 'sometimes|nullable|string|max:1000',
        ]);

        $metadata = array_merge($vendor->metadata ?? [], Arr::only($data, [
            'storefrontEnabled', 'storefrontHeadline', 'storefrontDescription', 'supportEmail', 'publicSupportEmail', 'supportPhone', 'returnAddress', 'dispatchNote',
        ]));
        unset($metadata['logoUrl']);

        $logoSelectionProvided = $request->exists('logoMediaAssetId') || $request->exists('logoUrl');
        if ($logoSelectionProvided) {
            $logo = $this->storefrontMedia->resolveSelection(
                $vendor,
                $data['logoMediaAssetId'] ?? null,
                $data['logoUrl'] ?? null,
            );
            if ($logo) {
                $metadata['logoMediaAssetId'] = $logo->public_id;
            } else {
                unset($metadata['logoMediaAssetId']);
            }
        }

        $vendor->forceFill([
            'name' => $data['name'] ?? $vendor->name,
            'slug' => $data['shopSlug'] ?? $vendor->slug,
            'metadata' => $metadata,
        ])->save();

        return response()->json(['data' => ['vendor' => $this->vendorRow($vendor->fresh())]]);
    }

    /** Handles return query for the seller operations controller workflow. */
    private function returnQuery(int $vendorId)
    {
        return ReturnRequest::query()->whereHas('items.orderItem.vendorOrder', /** Inline callback for this operation. */ fn ($query) => $query->where('vendor_id', $vendorId));
    }

    /** Handles vendor row for the seller operations controller workflow. */
    private function vendorRow($vendor): array
    {
        $logo = $this->storefrontMedia->logoPayload($vendor);

        return [
            'id' => $vendor->id,
            'name' => $vendor->name,
            'slug' => $vendor->slug,
            'shopUrl' => '/shop/'.$vendor->slug,
            'storefrontEnabled' => ($vendor->metadata['storefrontEnabled'] ?? true) !== false,
            'storefrontHeadline' => $vendor->metadata['storefrontHeadline'] ?? null,
            'storefrontDescription' => $vendor->metadata['storefrontDescription'] ?? null,
            'status' => $vendor->status,
            'commissionBps' => (int) $vendor->commission_bps,
            'supportEmail' => $vendor->metadata['supportEmail'] ?? null,
            'publicSupportEmail' => $vendor->metadata['publicSupportEmail'] ?? null,
            'supportPhone' => $vendor->metadata['supportPhone'] ?? null,
            'logoMediaAssetId' => $logo['logoMediaAssetId'],
            'logoUrl' => $logo['logoUrl'],
            'logoAlt' => $logo['logoAlt'],
            'returnAddress' => $vendor->metadata['returnAddress'] ?? null,
            'dispatchNote' => $vendor->metadata['dispatchNote'] ?? null,
        ];
    }

    /** Handles order row for the seller operations controller workflow. */
    private function orderRow(VendorOrder $row, bool $detailed): array
    {
        $address = $row->order?->shippingAddress;
        $base = [
            'id' => $row->public_id,
            'masterOrderId' => $row->order?->public_id,
            'status' => $row->status->value,
            'currency' => $row->currency,
            'subtotalMinor' => (int) $row->subtotal_minor,
            'shippingMinor' => (int) $row->shipping_minor,
            'discountMinor' => (int) $row->discount_minor,
            'totalMinor' => (int) $row->total_minor,
            'sellerPayableMinor' => (int) $row->seller_payable_minor,
            'paymentStatus' => $row->order?->payment_status?->value,
            'paymentMethod' => $row->order?->payment_method,
            'placedAt' => $row->order?->placed_at?->toIso8601String(),
            'packedAt' => $row->packed_at?->toIso8601String(),
            'dispatchedAt' => $row->dispatched_at?->toIso8601String(),
            'deliveredAt' => $row->delivered_at?->toIso8601String(),
            'buyer' => ['name' => $row->order?->user?->name],
            'items' => $row->items->map(/** Inline callback for this operation. */ fn ($item) => [
                'id' => $item->id, 'productName' => $item->product_name, 'variantName' => $item->variant_name, 'sku' => $item->sku,
                'quantity' => (int) $item->quantity, 'returnedQuantity' => (int) $item->returned_quantity, 'refundedQuantity' => (int) $item->refunded_quantity,
                'unitPriceMinor' => (int) $item->unit_price_minor, 'lineTotalMinor' => (int) $item->line_total_minor,
            ])->values(),
            'shipmentIds' => $row->shipments->pluck('public_id')->values(),
        ];
        if (! $detailed) {
            return $base;
        }
        $base['shippingAddress'] = $address ? [
            'recipientName' => $address->recipient_name, 'phone' => $address->phone, 'line1' => $address->line1, 'line2' => $address->line2,
            'city' => $address->city, 'state' => $address->state, 'postalCode' => $address->postal_code, 'countryCode' => $address->country_code,
        ] : null;
        $base['shipments'] = $row->shipments->map(/** Inline callback for this operation. */ fn ($shipment) => [
            'id' => $shipment->public_id, 'status' => $shipment->status->value, 'trackingNumber' => $shipment->tracking_number, 'serviceCode' => $shipment->service_code,
            'labelUrl' => $shipment->label_url, 'readyAt' => $shipment->ready_at?->toIso8601String(), 'deliveredAt' => $shipment->delivered_at?->toIso8601String(),
        ])->values();
        $base['settlement'] = $row->settlement ? [
            'id' => $row->settlement->public_id, 'status' => $row->settlement->status->value, 'sellerPayableMinor' => (int) $row->settlement->seller_payable_minor,
            'availableMinor' => $row->settlement->availableMinor(), 'eligibleAt' => $row->settlement->eligible_at?->toIso8601String(),
        ] : null;

        return $base;
    }

    /** Handles return row for the seller operations controller workflow. */
    private function returnRow(ReturnRequest $row, int $vendorId): array
    {
        $items = $row->items->filter(/** Inline callback for this operation. */ fn ($item) => $item->orderItem?->vendorOrder?->vendor_id === $vendorId)->values();
        $feedback = (array) (($row->metadata ?? [])['seller_feedback'] ?? []);

        return [
            'id' => $row->public_id, 'orderId' => $row->order?->public_id, 'status' => $row->status->value, 'resolution' => $row->resolution->value,
            'reason' => $row->reason, 'details' => $row->details, 'currency' => $row->currency,
            'requestedMinor' => (int) $items->sum('requested_minor'), 'approvedMinor' => (int) $items->sum('approved_minor'),
            'trackingReference' => $row->return_tracking_reference, 'submittedAt' => $row->submitted_at?->toIso8601String(),
            'reviewedAt' => $row->reviewed_at?->toIso8601String(), 'receivedAt' => $row->received_at?->toIso8601String(), 'resolvedAt' => $row->resolved_at?->toIso8601String(),
            'items' => $items->map(/** Inline callback for this operation. */ fn ($item) => [
                'id' => $item->id, 'productName' => $item->orderItem?->product_name, 'variantName' => $item->orderItem?->variant_name,
                'quantity' => (int) $item->quantity, 'approvedQuantity' => (int) $item->approved_quantity, 'receivedQuantity' => (int) $item->received_quantity, 'acceptedQuantity' => (int) $item->accepted_quantity, 'requestedMinor' => (int) $item->requested_minor, 'approvedMinor' => (int) $item->approved_minor, 'condition' => $item->condition,
            ])->values(),
            'sellerFeedback' => $feedback[(string) $vendorId] ?? null,
            'refund' => $row->refund ? ['id' => $row->refund->public_id, 'status' => $row->refund->status->value, 'amountMinor' => (int) $row->refund->amount_minor] : null,
            'dispute' => $row->dispute ? ['id' => $row->dispute->public_id, 'status' => $row->dispute->status->value, 'outcome' => $row->dispute->outcome] : null,
        ];
    }
}
