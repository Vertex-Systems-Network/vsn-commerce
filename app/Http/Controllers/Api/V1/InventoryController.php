<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\Actions\ReleaseInventoryReservation;
use App\Domain\Inventory\Actions\ReserveInventory;
use App\Domain\Inventory\Exceptions\InsufficientInventory;
use App\Enums\InventoryReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\ReserveInventoryRequest;
use App\Models\InventoryReservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Defines the InventoryController class and its project responsibilities. */
class InventoryController extends Controller
{
    /** Handles reserve for the inventory controller workflow. */
    public function reserve(
        ReserveInventoryRequest $request,
        ReserveInventory $reserveInventory,
    ): JsonResponse {
        $data = $request->validated();

        try {
            $reservation = $reserveInventory->execute(
                user: $request->user(),
                variantId: $data['variantId'],
                quantity: $data['quantity'],
                idempotencyKey: $data['idempotencyKey'],
                warehouseId: $data['warehouseId'] ?? null,
                reference: $data['reference'] ?? null,
            );
        } catch (InsufficientInventory $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => ['quantity' => [$exception->getMessage()]],
            ], 422);
        }

        return response()->json([
            'data' => [
                'reservationId' => $reservation->id,
                'status' => $reservation->status->value,
                'quantity' => $reservation->quantity,
                'expiresAt' => $reservation->expires_at->toIso8601String(),
                'inventory' => [
                    'id' => $reservation->inventory->id,
                    'available' => $reservation->inventory->available(),
                ],
            ],
        ], 201);
    }

    /** Handles release for the inventory controller workflow. */
    public function release(
        Request $request,
        InventoryReservation $reservation,
        ReleaseInventoryReservation $releaseInventory,
    ): JsonResponse {
        abort_unless($reservation->user_id === $request->user()->id, 404);

        if ($reservation->status === InventoryReservationStatus::Converted) {
            abort(409, 'Converted reservations cannot be released.');
        }

        $reservation = $releaseInventory->execute($reservation);

        return response()->json([
            'data' => [
                'reservationId' => $reservation->id,
                'status' => $reservation->status->value,
            ],
        ]);
    }
}
