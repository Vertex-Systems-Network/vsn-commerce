<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\StoreAddressRequest;
use App\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Defines the AddressController class and its project responsibilities. */
class AddressController extends Controller
{
    /** Handles the index request for this resource. */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->addresses()->latest()->get(),
        ]);
    }

    /** Handles the store request for this resource. */
    public function store(StoreAddressRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $address = DB::transaction(/** Inline callback for this operation. */ function () use ($user, $data): Address {
            $makeDefault = ! $user->addresses()->exists() || ! empty($data['is_default']);
            if ($makeDefault) {
                $user->addresses()->update(['is_default' => false]);
                $data['is_default'] = true;
            }

            return $user->addresses()->create($data);
        });

        return response()->json(['data' => $address], 201);
    }


    /** Handles the update request for this resource. */
    public function update(StoreAddressRequest $request, Address $address): JsonResponse
    {
        abort_unless($address->user_id === $request->user()->id, 404);
        $data = $request->validated();
        $user = $request->user();

        DB::transaction(/** Inline callback for this operation. */ function () use ($user, $address, $data): void {
            if (! empty($data['is_default'])) {
                $user->addresses()->where('id', '!=', $address->id)->update(['is_default' => false]);
            } elseif ($address->is_default) {
                $replacement = $user->addresses()->where('id', '!=', $address->id)->latest('id')->first();
                if ($replacement) {
                    $replacement->update(['is_default' => true]);
                } else {
                    $data['is_default'] = true;
                }
            }
            $address->update($data);
        });

        return response()->json(['data' => $address->fresh()]);
    }

    /** Handles the destroy request for this resource. */
    public function destroy(Request $request, Address $address): JsonResponse
    {
        abort_unless($address->user_id === $request->user()->id, 404);

        $wasDefault = (bool) $address->is_default;
        $user = $request->user();
        DB::transaction(/** Inline callback for this operation. */ function () use ($address, $wasDefault, $user): void {
            $address->delete();
            if ($wasDefault) {
                $user->addresses()->latest('id')->first()?->update(['is_default' => true]);
            }
        });

        return response()->json(['data' => ['ok' => true]]);
    }
}
