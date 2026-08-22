<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Checkout\Exceptions\CheckoutValidationException;
use App\Domain\Payments\Actions\CreatePaymentIntent;
use App\Domain\Payments\Actions\ProcessPaymentWebhook;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Domain\Payments\Gateways\SandboxPaymentGateway;
use App\Domain\Payments\Services\PaymentGatewayManager;
use App\Domain\Payments\Services\PaymentLifecycleService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\CreatePaymentIntentRequest;
use App\Http\Resources\PaymentIntentResource;
use App\Models\CheckoutSession;
use App\Models\PaymentIntent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Defines the PaymentController class and its project responsibilities. */
class PaymentController extends Controller
{
    /** Handles the store request for this resource. */
    public function store(
        CreatePaymentIntentRequest $request,
        CheckoutSession $checkoutSession,
        CreatePaymentIntent $create,
    ): PaymentIntentResource|JsonResponse {
        abort_unless($checkoutSession->user_id === $request->user()->id, 404);

        try {
            $intent = $create->execute($request->user(), $checkoutSession, $request->validated('idempotencyKey'));
        } catch (PaymentException|CheckoutValidationException $exception) {
            $field = $exception instanceof PaymentException ? $exception->field : $exception->field;
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => [$field => [$exception->getMessage()]],
            ], 422);
        }

        return new PaymentIntentResource($intent);
    }

    /** Handles the show request for this resource. */
    public function show(Request $request, PaymentIntent $paymentIntent): PaymentIntentResource
    {
        abort_unless($paymentIntent->user_id === $request->user()->id, 404);
        return new PaymentIntentResource($paymentIntent->load('order','savedPaymentMethod'));
    }

    /** Handles sandbox complete for the payment controller workflow. */
    public function sandboxComplete(
        Request $request,
        PaymentIntent $paymentIntent,
        PaymentGatewayManager $gateways,
        ProcessPaymentWebhook $processor,
    ): PaymentIntentResource {
        abort_unless($paymentIntent->user_id === $request->user()->id, 404);
        abort_if(app()->isProduction(), 404);
        abort_unless((bool) config('vsn.payments.providers.sandbox.simulator_enabled'), 404);
        abort_unless($paymentIntent->provider === 'sandbox', 404);

        $gateway = $gateways->gateway('sandbox');
        abort_unless($gateway instanceof SandboxPaymentGateway, 500);
        $event = $gateway->signedEvent($paymentIntent);
        $processor->execute('sandbox', $event['raw'], $event['headers']);

        return new PaymentIntentResource($paymentIntent->fresh()->load('order','savedPaymentMethod'));
    }
    /** Handles refresh provider for the payment controller workflow. */
    public function refreshProvider(Request $request, PaymentIntent $paymentIntent, PaymentLifecycleService $life): PaymentIntentResource|JsonResponse
    {
        abort_unless($paymentIntent->user_id === $request->user()->id, 404);
        try { return new PaymentIntentResource($life->sync($paymentIntent)); }
        catch (PaymentException $e) { return response()->json(['message'=>$e->getMessage(),'errors'=>['payment'=>[$e->getMessage()]]],422); }
    }

    /** Handles retry initialization for the payment controller workflow. */
    public function retryInitialization(Request $request, PaymentIntent $paymentIntent, PaymentLifecycleService $life): PaymentIntentResource|JsonResponse
    {
        abort_unless($paymentIntent->user_id === $request->user()->id, 404);
        try { return new PaymentIntentResource($life->retryInitialization($request->user(),$paymentIntent)); }
        catch (PaymentException|CheckoutValidationException $e) { return response()->json(['message'=>$e->getMessage(),'errors'=>['payment'=>[$e->getMessage()]]],422); }
    }

}
