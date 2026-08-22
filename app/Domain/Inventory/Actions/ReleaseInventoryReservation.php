<?php

namespace App\Domain\Inventory\Actions;

use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use Illuminate\Support\Facades\DB;

/** Defines the ReleaseInventoryReservation class and its project responsibilities. */
class ReleaseInventoryReservation
{
    /** Executes the release inventory reservation operation. */
    public function execute(InventoryReservation $reservation): InventoryReservation
    {
        return DB::transaction(/** Inline callback for this operation. */ function () use ($reservation): InventoryReservation {
            $reservation = InventoryReservation::query()
                ->whereKey($reservation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($reservation->status !== InventoryReservationStatus::Active) {
                return $reservation->load('inventory');
            }

            $inventory = Inventory::query()
                ->whereKey($reservation->inventory_id)
                ->lockForUpdate()
                ->firstOrFail();

            $inventory->update([
                'reserved' => max(0, $inventory->reserved - $reservation->quantity),
            ]);

            $reservation->update([
                'status' => InventoryReservationStatus::Released,
                'released_at' => now(),
            ]);

            InventoryMovement::create([
                'inventory_id' => $inventory->id,
                'type' => InventoryMovementType::ReservationRelease,
                'on_hand_delta' => 0,
                'reserved_delta' => -$reservation->quantity,
                'reference_type' => 'inventory_reservation',
                'reference_id' => (string) $reservation->id,
            ]);

            return $reservation->fresh()->load('inventory');
        }, 3);
    }
}
