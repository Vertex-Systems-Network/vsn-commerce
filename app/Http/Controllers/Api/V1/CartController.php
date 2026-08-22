<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Cart\Actions\AddCartItem;
use App\Domain\Cart\Actions\MergeGuestCart;
use App\Domain\Cart\Actions\UpdateCartItem;
use App\Domain\Cart\Exceptions\CartValidationException;
use App\Domain\Cart\Services\CartLoader;
use App\Domain\Cart\Services\CartResolver;
use App\Domain\Checkout\Actions\ReleaseCheckoutSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\AddCartItemRequest;
use App\Http\Requests\Cart\MergeCartRequest;
use App\Http\Requests\Cart\UpdateCartItemRequest;
use App\Http\Resources\CartResource;
use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Defines the CartController class and its project responsibilities. */
class CartController extends Controller
{
    /** Handles the show request for this resource. */
    public function show(Request $request, CartResolver $resolver, CartLoader $loader): CartResource
    {
        return new CartResource($loader->load($resolver->resolve($request)));
    }

    /** Handles store item for the cart controller workflow. */
    public function storeItem(
        AddCartItemRequest $request,
        CartResolver $resolver,
        AddCartItem $add,
        CartLoader $loader,
    ): CartResource|JsonResponse {
        $data = $request->validated();

        $cart = $resolver->resolve($request);
        $this->releaseReservedCheckout($cart, app(ReleaseCheckoutSession::class));

        try {
            $cart = $add->execute(
                cart: $cart,
                variantId: $data['variantId'] ?? null,
                productId: $data['productId'] ?? null,
                quantity: $data['quantity'],
                selectedVariant: $data['selectedVariant'] ?? null,
                productSlug: $data['productSlug'] ?? null,
                selectedOptions: $data['selectedOptions'] ?? null,
            );
        } catch (CartValidationException $exception) {
            return $this->validationError($exception);
        }

        return new CartResource($loader->load($cart));
    }

    /** Handles update item for the cart controller workflow. */
    public function updateItem(
        UpdateCartItemRequest $request,
        CartItem $item,
        CartResolver $resolver,
        UpdateCartItem $update,
        CartLoader $loader,
    ): CartResource|JsonResponse {
        $cart = $resolver->resolve($request);

        abort_unless($item->cart_id === $cart->id, 404);
        $this->releaseReservedCheckout($cart, app(ReleaseCheckoutSession::class));

        try {
            $cart = $update->execute($cart, $item, $request->integer('quantity'));
        } catch (CartValidationException $exception) {
            return $this->validationError($exception);
        }

        return new CartResource($loader->load($cart));
    }

    /** Handles destroy item for the cart controller workflow. */
    public function destroyItem(
        Request $request,
        CartItem $item,
        CartResolver $resolver,
        CartLoader $loader,
    ): CartResource {
        $cart = $resolver->resolve($request);
        abort_unless($item->cart_id === $cart->id, 404);
        $this->releaseReservedCheckout($cart, app(ReleaseCheckoutSession::class));

        $item->delete();

        return new CartResource($loader->load($cart->fresh()));
    }

    /** Handles clear for the cart controller workflow. */
    public function clear(Request $request, CartResolver $resolver, CartLoader $loader): CartResource
    {
        $cart = $resolver->resolve($request);
        $this->releaseReservedCheckout($cart, app(ReleaseCheckoutSession::class));
        $cart->items()->delete();

        return new CartResource($loader->load($cart->fresh()));
    }

    /** Handles merge for the cart controller workflow. */
    public function merge(
        MergeCartRequest $request,
        CartResolver $resolver,
        MergeGuestCart $merge,
        CartLoader $loader,
    ): JsonResponse {
        $userCart = $resolver->forUser($request->user());
        $this->releaseReservedCheckout($userCart, app(ReleaseCheckoutSession::class));
        $result = $merge->execute(
            user: $request->user(),
            userCart: $userCart,
            guestToken: $request->validated('guestToken'),
        );

        return response()->json([
            'data' => (new CartResource($loader->load($result['cart'])))->resolve($request),
            'meta' => [
                'mergedQuantity' => $result['merged'],
                'skippedQuantity' => $result['skipped'],
            ],
        ]);
    }

    /** Handles release reserved checkout for the cart controller workflow. */
    private function releaseReservedCheckout(Cart $cart, ReleaseCheckoutSession $release): void
    {
        $sessions = $cart->checkoutSessions()
            ->where('status', \App\Enums\CheckoutStatus::Reserved->value)
            ->orderBy('id')
            ->get();

        foreach ($sessions as $session) {
            $release->execute($session);
        }
    }

    /** Handles validation error for the cart controller workflow. */
    private function validationError(CartValidationException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'errors' => [$exception->field => [$exception->getMessage()]],
        ], 422);
    }
}
