<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payments\Actions\ProcessPaymentWebhook;
use App\Domain\Payments\Exceptions\InvalidWebhookSignature;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/** Defines the PaymentWebhookController class and its project responsibilities. */
class PaymentWebhookController extends Controller
{
    /** Executes the payment webhook controller operation. */
    public function handle(Request $request, string $provider, ProcessPaymentWebhook $processor): JsonResponse
    {
        try {
            $event = $processor->execute($provider, $request->getContent(), $request->headers->all());
        } catch (InvalidWebhookSignature $exception) {
            return response()->json(['message' => $exception->getMessage()], 401);
        } catch (PaymentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['message' => 'Payment webhook processing failed.'], 500);
        }

        return response()->json([
            'data' => [
                'accepted' => true,
                'eventId' => $event->provider_event_id,
                'status' => $event->status->value,
            ],
        ]);
    }
}
