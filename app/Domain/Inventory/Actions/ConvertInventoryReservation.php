<?php

namespace App\Domain\Inventory\Actions;

use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Defines the ConvertInventoryReservation class and its project responsibilities. */
class ConvertInventoryReservation
{
    /** Executes the convert inventory reservation operation. */
    public function execute(InventoryReservation $reservation, string $referenceType = 'order', ?string $referenceId = null): InventoryReservation
    {
        return DB::transaction(/** Inline callback for this operation. */ function () use ($reservation, $referenceType, $referenceId): InventoryReservation {
            $reservation = InventoryReservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if ($reservation->status === InventoryReservationStatus::Converted) {
                return $reservation->load('inventory');
            }

            if ($reservation->status !== InventoryReservationStatus::Active || $reservation->expires_at->isPast()) {
                throw new RuntimeException('Inventory reservation is no longer active.');
            }

            $inventory = Inventory::query()->whereKey($reservation->inventory_id)->lockForUpdate()->firstOrFail();

            if ($inventory->reserved < $reservation->quantity || $inventory->on_hand < $reservation->quantity) {
                throw new RuntimeException('Reserved inventory is inconsistent and cannot be converted.');
            }

            $inventory->update([
                'on_hand' => $inventory->on_hand - $reservation->quantity,
                'reserved' => max(0, $inventory->reserved - $reservation->quantity),
            ]);

            $reservation->update([
                'status' => InventoryReservationStatus::Converted,
                'converted_at' => now(),
            ]);

            InventoryMovement::create([
                'inventory_id' => $inventory->id,
                'type' => InventoryMovementType::Sale,
                'on_hand_delta' => -$reservation->quantity,
                'reserved_delta' => -$reservation->quantity,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);

            return $reservation->fresh()->load('inventory');
        }, 3);
    }
}
