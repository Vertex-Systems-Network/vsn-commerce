<?php

namespace App\Domain\Cart\Services;

use App\Enums\CartStatus;
use App\Models\Cart;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/** Defines the CartResolver class and its project responsibilities. */
class CartResolver
{
    /** Handles resolve for the cart resolver workflow. */
    public function resolve(Request $request): Cart
    {
        $user = Auth::guard('sanctum')->user();

        if ($user instanceof User) {
            return $this->forUser($user);
        }

        $token = trim((string) $request->header('X-Cart-Token'));

        if ($token !== '' && Str::isUuid($token)) {
            $existing = Cart::query()
                ->where('guest_token', $token)
                ->where('status', CartStatus::Active->value)
                ->whereNull('user_id')
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return Cart::create([
            'public_id' => (string) Str::ulid(),
            'guest_token' => (string) Str::uuid(),
            'status' => CartStatus::Active,
            'currency' => config('vsn.currency', 'PKR'),
        ]);
    }

    /** Handles for user for the cart resolver workflow. */
    public function forUser(User $user): Cart
    {
        $existing = Cart::query()
            ->where('user_id', $user->id)
            ->where('status', CartStatus::Active->value)
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            return Cart::create([
                'public_id' => (string) Str::ulid(),
                'user_id' => $user->id,
                'status' => CartStatus::Active,
                'currency' => config('vsn.currency', 'PKR'),
            ]);
        } catch (QueryException $exception) {
            $winner = Cart::query()
                ->where('user_id', $user->id)
                ->where('status', CartStatus::Active->value)
                ->first();

            if ($winner) {
                return $winner;
            }

            throw $exception;
        }
    }
}
