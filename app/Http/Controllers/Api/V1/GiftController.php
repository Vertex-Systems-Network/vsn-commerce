<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Cart\Exceptions\CartValidationException;
use App\Domain\Gifts\Actions\CancelGiftCheckout;
use App\Domain\Gifts\Actions\CreateGiftCheckout;
use App\Domain\Gifts\Exceptions\GiftException;
use App\Domain\Gifts\Services\GiftDashboardService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gifts\CreateGiftCheckoutRequest;
use App\Http\Resources\CheckoutSessionResource;
use App\Http\Resources\GiftResource;
use App\Models\Gift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Defines the GiftController class and its project responsibilities. */
class GiftController extends Controller
{
    /** Handles the index request for this resource. */
    public function index(Request $request, GiftDashboardService $dashboard): JsonResponse
    {
        $data = $dashboard->forUser($request->user());
        return response()->json(['data'=>[
            'profile'=>$data['profile'],
            'rewards'=>$data['rewards']->map(/** Inline callback for this operation. */ fn ($reward)=>[
                'id'=>$reward->public_id,'code'=>$reward->reward_code,'level'=>$reward->level,'status'=>$reward->status->value,
                'label'=>$reward->metadata['label'] ?? $reward->reward_code,'awardedAt'=>$reward->awarded_at?->toISOString(),
            ])->values(),
            'sent'=>GiftResource::collection($data['sent'])->resolve($request),
            'received'=>GiftResource::collection($data['received'])->resolve($request),
        ]]);
    }

    /** Handles the store request for this resource. */
    public function store(CreateGiftCheckoutRequest $request, CreateGiftCheckout $create): JsonResponse
    {
        try { $result = $create->execute($request->user(), $request->validated()); }
        catch (GiftException|CartValidationException $e) {
            $field = property_exists($e, 'field') ? $e->field : 'gift';
            return response()->json(['message'=>$e->getMessage(),'errors'=>[$field=>[$e->getMessage()]]],422);
        }
        return response()->json(['data'=>[
            'gift'=>(new GiftResource($result['gift']))->resolve($request),
            'checkout'=>(new CheckoutSessionResource($result['checkout']))->resolve($request),
        ]],201);
    }

    /** Handles the show request for this resource. */
    public function show(Request $request, Gift $gift): GiftResource
    {
        abort_unless(in_array($request->user()->id, [$gift->sender_user_id,$gift->recipient_user_id], true),404);
        return new GiftResource($gift->load(['sender','recipient','product.images','variant','order','checkoutSession.paymentIntents']));
    }

    /** Handles cancel for the gift controller workflow. */
    public function cancel(Request $request, Gift $gift, CancelGiftCheckout $cancel): GiftResource|JsonResponse
    {
        try { return new GiftResource($cancel->execute($request->user(),$gift)); }
        catch (GiftException $e) { return response()->json(['message'=>$e->getMessage(),'errors'=>[$e->field=>[$e->getMessage()]]],422); }
    }
}
