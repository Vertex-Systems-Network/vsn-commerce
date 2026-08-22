<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Exceptions\InsufficientInventory;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Defines the ReserveInventory class and its project responsibilities. */
class ReserveInventory
{
    /** Executes the reserve inventory operation. */
    public function execute(
        User $user,
        int $variantId,
        int $quantity,
        string $idempotencyKey,
        ?int $warehouseId = null,
        ?string $reference = null,
    ): InventoryReservation {
        return DB::transaction(/** Inline callback for this operation. */ function () use (
            $user,
            $variantId,
            $quantity,
            $idempotencyKey,
            $warehouseId,
            $reference,
        ): InventoryReservation {
            $existing = InventoryReservation::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                abort_unless($existing->user_id === $user->id, 409, 'Idempotency key already used.');

                return $existing->load('inventory');
            }

            $inventory = Inventory::query()
                ->where('product_variant_id', $variantId)
                ->when($warehouseId, /** Inline callback for this operation. */ fn ($q) => $q->where('warehouse_id', $warehouseId))
                // Avoid subtraction on unsigned MySQL columns: a negative result
                // can raise BIGINT UNSIGNED out-of-range before PHP can handle it.
                ->whereRaw('on_hand >= (reserved + safety_stock + ?)', [$quantity])
                ->orderByDesc('on_hand')
                ->orderBy('reserved')
                ->orderBy('safety_stock')
                ->lockForUpdate()
                ->first();

            if (! $inventory || $inventory->available() < $quantity) {
                throw new InsufficientInventory('Requested quantity is not available.');
            }

            $inventory->increment('reserved', $quantity);
            $inventory->refresh();

            $reservation = InventoryReservation::create([
                'inventory_id' => $inventory->id,
                'user_id' => $user->id,
                'idempotency_key' => $idempotencyKey,
                'quantity' => $quantity,
                'status' => InventoryReservationStatus::Active,
                'reference' => $reference,
                'expires_at' => now()->addMinutes(config('vsn.inventory_reservation_minutes', 15)),
            ]);

            InventoryMovement::create([
                'inventory_id' => $inventory->id,
                'type' => InventoryMovementType::Reservation,
                'on_hand_delta' => 0,
                'reserved_delta' => $quantity,
                'reference_type' => 'inventory_reservation',
                'reference_id' => (string) $reservation->id,
            ]);

            return $reservation->load('inventory');
        }, 3);
    }
}
