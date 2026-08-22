<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Cart\Services\CartLoader;
use App\Domain\Cart\Services\CartResolver;
use App\Domain\Checkout\Actions\CreateCheckoutSession;
use App\Domain\Checkout\Actions\PlaceOrder;
use App\Domain\Checkout\Actions\ReleaseCheckoutSession;
use App\Domain\Checkout\Exceptions\CheckoutValidationException;
use App\Domain\Checkout\Services\ShippingQuoteService;
use App\Domain\Payments\Services\PaymentGatewayManager;
use App\Enums\CheckoutStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\CreateCheckoutSessionRequest;
use App\Http\Resources\CheckoutSessionResource;
use App\Http\Resources\OrderResource;
use App\Models\Address;
use App\Models\CheckoutSession;
use App\Domain\Settings\MarketplaceSettings;
use App\Http\Resources\SavedPaymentMethodResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Defines the CheckoutController class and its project responsibilities. */
class CheckoutController extends Controller
{
    /** Handles options for the checkout controller workflow. */
    public function options(Request $request, CartResolver $cartResolver, CartLoader $loader, ShippingQuoteService $shipping, PaymentGatewayManager $payments): JsonResponse
    {
        $addressId = (int) $request->query('addressId', 0);
        $address = Address::query()->whereKey($addressId)->where('user_id', $request->user()->id)->first();
        if (! $address) {
            return response()->json([
                'data' => [
                    'shippingQuotes' => [],
                    'paymentMethods' => $payments->methods(),
                    'savedPaymentMethods' => SavedPaymentMethodResource::collection($request->user()->savedPaymentMethods()->where('status','active')->orderByDesc('is_default')->get()->filter(/** Inline callback for this operation. */ fn($method)=>$method->isActive() && $method->provider === config('vsn.payments.methods.card.provider'))->values())->resolve($request),
                ],
            ]);
        }

        $cart = $loader->load($cartResolver->forUser($request->user()));

        return response()->json([
            'data' => [
                'shippingQuotes' => $shipping->quotes($cart, $address),
                'paymentMethods' => $payments->methods(),
                'savedPaymentMethods' => SavedPaymentMethodResource::collection($request->user()->savedPaymentMethods()->where('status','active')->orderByDesc('is_default')->get()->filter(/** Inline callback for this operation. */ fn($method)=>$method->isActive() && $method->provider === config('vsn.payments.methods.card.provider'))->values())->resolve($request),
            ],
        ]);
    }

    /** Handles current for the checkout controller workflow. */
    public function current(Request $request, CartResolver $cartResolver, ReleaseCheckoutSession $release): CheckoutSessionResource|JsonResponse
    {
        $cart = $cartResolver->forUser($request->user());
        $session = CheckoutSession::query()
            ->where('user_id', $request->user()->id)
            ->where('cart_id', $cart->id)
            ->where('status', CheckoutStatus::Reserved->value)
            ->latest('id')
            ->first();

        if (! $session) return response()->json(['data' => null]);
        if ($session->expires_at->isPast()) {
            $release->execute($session, CheckoutStatus::Expired);
            return response()->json(['data' => null]);
        }

        return new CheckoutSessionResource($session->load(['items.reservation.inventory','items.vendor','order','gift','savedPaymentMethod','paymentIntents.order','paymentIntents.savedPaymentMethod']));
    }

    /** Handles the store request for this resource. */
    public function store(
        CreateCheckoutSessionRequest $request,
        CartResolver $cartResolver,
        CartLoader $loader,
        CreateCheckoutSession $create,
        MarketplaceSettings $settings,
    ): CheckoutSessionResource|JsonResponse {
        if (! $settings->orderingEnabled()) return response()->json(['message'=>'Ordering is temporarily disabled by marketplace operations.'], 423);
        $data = $request->validated();
        $address = Address::query()->findOrFail($data['addressId']);
        $cart = $loader->load($cartResolver->forUser($request->user()));

        try {
            $session = $create->execute(
                user: $request->user(),
                cart: $cart,
                address: $address,
                shippingMethod: $data['shippingMethod'],
                paymentMethod: $data['paymentMethod'],
                idempotencyKey: $data['idempotencyKey'],
                couponCode: $data['couponCode'] ?? null,
                coinRedemptionCoins: (int) ($data['coinRedemptionCoins'] ?? 0),
                savedPaymentMethodId: $data['savedPaymentMethodId'] ?? null,
            );
        } catch (CheckoutValidationException $exception) {
            return $this->validationError($exception);
        }

        return new CheckoutSessionResource($session);
    }

    /** Handles the show request for this resource. */
    public function show(Request $request, CheckoutSession $checkoutSession, ReleaseCheckoutSession $release): CheckoutSessionResource
    {
        abort_unless($checkoutSession->user_id === $request->user()->id, 404);

        if ($checkoutSession->status === CheckoutStatus::Reserved && $checkoutSession->expires_at->isPast()) {
            $checkoutSession = $release->execute($checkoutSession, CheckoutStatus::Expired);
        }

        return new CheckoutSessionResource($checkoutSession->load(['items.reservation.inventory', 'items.vendor', 'order', 'gift', 'savedPaymentMethod']));
    }

    /** Handles the destroy request for this resource. */
    public function destroy(Request $request, CheckoutSession $checkoutSession, ReleaseCheckoutSession $release): CheckoutSessionResource
    {
        abort_unless($checkoutSession->user_id === $request->user()->id, 404);
        $session = $release->execute($checkoutSession, CheckoutStatus::Cancelled);

        return new CheckoutSessionResource($session);
    }

    /** Handles place order for the checkout controller workflow. */
    public function placeOrder(Request $request, CheckoutSession $checkoutSession, PlaceOrder $place, MarketplaceSettings $settings): OrderResource|JsonResponse
    {
        if (! $settings->orderingEnabled()) return response()->json(['message'=>'Ordering is temporarily disabled by marketplace operations.'], 423);
        abort_unless($checkoutSession->user_id === $request->user()->id, 404);

        try {
            $order = $place->execute($request->user(), $checkoutSession);
        } catch (CheckoutValidationException $exception) {
            return $this->validationError($exception);
        }

        return new OrderResource($order);
    }

    /** Handles validation error for the checkout controller workflow. */
    private function validationError(CheckoutValidationException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'errors' => [$exception->field => [$exception->getMessage()]],
        ], 422);
    }
}
