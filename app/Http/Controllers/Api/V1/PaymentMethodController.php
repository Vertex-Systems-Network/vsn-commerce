<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payments\Exceptions\PaymentException;
use App\Domain\Payments\Gateways\SandboxPaymentVaultProvider;
use App\Domain\Payments\Gateways\StripePaymentVaultProvider;
use App\Domain\Payments\Services\PaymentVaultManager;
use App\Domain\Security\Services\SecurityRecorder;
use App\Domain\Security\Services\StepUpService;
use App\Enums\SecuritySeverity;
use App\Http\Controllers\Controller;
use App\Http\Resources\SavedPaymentMethodResource;
use App\Models\SavedPaymentMethod;
use App\Models\PaymentProviderCustomer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Defines the PaymentMethodController class and its project responsibilities. */
class PaymentMethodController extends Controller
{
    /** Handles the index request for this resource. */
    public function index(Request $request, PaymentVaultManager $vaults): JsonResponse
    {
        $rows = $request->user()->savedPaymentMethods()
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->latest()
            ->get()
            ->filter(/** Inline callback for this operation. */ fn (SavedPaymentMethod $method) => $method->isActive())
            ->values();

        return response()->json(['data' => [
            'items' => SavedPaymentMethodResource::collection($rows)->resolve($request),
            'security' => [
                'rawCardStorage' => false,
                'stepUpRequired' => true,
                'maxSavedMethods' => (int) config('vsn.payments.vault.max_saved_methods', 8),
                'sandboxSetupEnabled' => $vaults->sandboxSetupEnabled(),
                'browserSetupProvider' => (bool) config('vsn.payments.methods.card.enabled', false) && (string) config('vsn.payments.methods.card.provider') === 'stripe' ? 'stripe' : null,
            ],
        ]]);
    }

    /** Updates up. */
    public function setup(Request $request, PaymentVaultManager $vaults, StepUpService $stepUp): JsonResponse
    {
        $data=$request->validate(['provider'=>['required','string','max:60']]);$stepUp->assert($request,$request->user(),'payment_methods');
        try{$provider=$vaults->provider($data['provider']);if(!$provider instanceof StripePaymentVaultProvider)return response()->json(['message'=>'This provider does not expose a browser tokenization setup flow.'],422);return response()->json(['data'=>$provider->createSetupIntent($request->user())]);}
        catch(PaymentException $e){return response()->json(['message'=>$e->getMessage(),'errors'=>[$e->field=>[$e->getMessage()]]],422);}
    }

    /** Handles sandbox setup for the payment method controller workflow. */
    public function sandboxSetup(Request $request, PaymentVaultManager $vaults): JsonResponse
    {
        abort_unless($vaults->sandboxSetupEnabled(), 404);
        $data = $request->validate([
            'brand' => ['required', 'string', 'max:40'],
            'last4' => ['required', 'digits:4'],
            'expMonth' => ['required', 'integer', 'min:1', 'max:12'],
            'expYear' => ['required', 'integer', 'min:'.now()->year, 'max:2200'],
            'holderName' => ['nullable', 'string', 'max:160'],
            'cardNumber' => ['prohibited'], 'pan' => ['prohibited'], 'cvc' => ['prohibited'], 'cvv' => ['prohibited'],
        ]);

        $provider = $vaults->provider('sandbox');
        abort_unless($provider instanceof SandboxPaymentVaultProvider, 500);

        return response()->json(['data' => [
            'provider' => 'sandbox',
            'providerToken' => $provider->issueTestToken($data['brand'], $data['last4'], (int) $data['expMonth'], (int) $data['expYear'], $data['holderName'] ?? null, $request->user()->id),
            'message' => 'Sandbox token contains masked test metadata only; no PAN/CVC is collected.',
        ]]);
    }

    /** Handles the store request for this resource. */
    public function store(Request $request, PaymentVaultManager $vaults, StepUpService $stepUp, SecurityRecorder $events): SavedPaymentMethodResource|JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:60'],
            'providerToken' => ['required', 'string', 'max:4096'],
            'makeDefault' => ['sometimes', 'boolean'],
            'cardNumber' => ['prohibited'], 'pan' => ['prohibited'], 'cvc' => ['prohibited'], 'cvv' => ['prohibited'],
        ]);
        $stepUp->assert($request, $request->user(), 'payment_methods');

        try {
            $provider = $vaults->provider($data['provider']);
            $info = $provider->inspectToken($data['providerToken']);
        } catch (PaymentException $exception) {
            return response()->json(['message'=>$exception->getMessage(),'errors'=>[$exception->field=>[$exception->getMessage()]]], 422);
        }

        if (isset($info->metadata['subject_user_id']) && (int) $info->metadata['subject_user_id'] !== $request->user()->id) {
            return response()->json(['message'=>'The provider token belongs to another account.','errors'=>['providerToken'=>['The provider token belongs to another account.']]], 422);
        }

        if ($data['provider'] === 'stripe') {
            $customer = PaymentProviderCustomer::query()->where('user_id', $request->user()->id)->where('provider', 'stripe')->first();
            $tokenCustomer = (string) ($info->metadata['stripe_customer_id'] ?? '');
            if (! $customer || $tokenCustomer === '' || ! hash_equals((string) $customer->provider_customer_id_cipher, $tokenCustomer)) {
                return response()->json(['message'=>'The Stripe payment method is not attached to this account vault.','errors'=>['providerToken'=>['The Stripe payment method is not attached to this account vault.']]], 422);
            }
        }

        $method = DB::transaction(/** Inline callback for this operation. */ function () use ($request, $data, $info): SavedPaymentMethod {
            $user = $request->user();
            $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $fingerprint = hash('sha256', $data['provider'].'|'.$info->fingerprint);
            $existing = SavedPaymentMethod::query()
                ->where('user_id', $user->id)
                ->where('provider', $data['provider'])
                ->where('fingerprint_sha256', $fingerprint)
                ->lockForUpdate()
                ->first();

            $activeCount = SavedPaymentMethod::query()->where('user_id', $user->id)->where('status', 'active')->count();
            if ((! $existing || $existing->status !== 'active') && $activeCount >= (int) config('vsn.payments.vault.max_saved_methods', 8)) {
                abort(422, 'Maximum saved payment-method limit reached.');
            }

            $makeDefault = (bool) ($existing?->is_default) || (bool) ($data['makeDefault'] ?? false) || $activeCount === 0;
            if ($makeDefault) {
                SavedPaymentMethod::query()->where('user_id', $user->id)->where('is_default', true)->update(['is_default'=>false]);
            }

            $values = [
                'user_id'=>$user->id, 'provider'=>$data['provider'], 'payment_method'=>'card',
                'provider_token_cipher'=>$info->providerToken, 'fingerprint_sha256'=>$fingerprint,
                'brand'=>$info->brand, 'last4'=>$info->last4, 'exp_month'=>$info->expMonth, 'exp_year'=>$info->expYear,
                'holder_name'=>$info->holderName, 'provider_customer_id_cipher'=>$info->metadata['stripe_customer_id']??null, 'status'=>'active', 'is_default'=>$makeDefault,
                'verified_at'=>now(), 'revoked_at'=>null, 'metadata'=>$info->metadata,
            ];

            if ($existing) {
                $existing->update($values);
                return $existing->fresh();
            }

            return SavedPaymentMethod::create(['public_id'=>(string) Str::ulid(), ...$values]);
        }, 3);

        $events->record($request->user(), 'payment_method_added', SecuritySeverity::Medium, $request, [
            'paymentMethodId'=>$method->public_id, 'provider'=>$method->provider, 'brand'=>$method->brand, 'last4'=>$method->last4,
        ]);

        return new SavedPaymentMethodResource($method);
    }

    /** Handles make default for the payment method controller workflow. */
    public function makeDefault(Request $request, SavedPaymentMethod $paymentMethod, StepUpService $stepUp, SecurityRecorder $events): SavedPaymentMethodResource
    {
        $stepUp->assert($request, $request->user(), 'payment_methods');
        abort_unless($paymentMethod->user_id === $request->user()->id && $paymentMethod->isActive(), 404);

        DB::transaction(/** Inline callback for this operation. */ function () use ($paymentMethod): void {
            $paymentMethod->user()->lockForUpdate()->firstOrFail();
            SavedPaymentMethod::query()->where('user_id', $paymentMethod->user_id)->where('is_default', true)->update(['is_default'=>false]);
            $paymentMethod->update(['is_default'=>true]);
        }, 3);

        $events->record($request->user(), 'payment_method_default_changed', SecuritySeverity::Low, $request, ['paymentMethodId'=>$paymentMethod->public_id]);
        return new SavedPaymentMethodResource($paymentMethod->fresh());
    }

    /** Handles the destroy request for this resource. */
    public function destroy(Request $request, SavedPaymentMethod $paymentMethod, PaymentVaultManager $vaults, StepUpService $stepUp, SecurityRecorder $events): JsonResponse
    {
        $stepUp->assert($request, $request->user(), 'payment_methods');
        abort_unless($paymentMethod->user_id === $request->user()->id && $paymentMethod->status === 'active', 404);

        try {
            $vaults->provider($paymentMethod->provider)->detach($paymentMethod->provider_token_cipher);
        } catch (PaymentException) {
            return response()->json(['message'=>'Provider could not detach this payment method.'], 502);
        }

        DB::transaction(/** Inline callback for this operation. */ function () use ($paymentMethod): void {
            $paymentMethod->user()->lockForUpdate()->firstOrFail();
            $wasDefault = $paymentMethod->is_default;
            $paymentMethod->update(['status'=>'revoked','is_default'=>false,'revoked_at'=>now()]);
            if ($wasDefault) {
                SavedPaymentMethod::query()->where('user_id',$paymentMethod->user_id)->where('status','active')->orderByDesc('last_used_at')->get()->first(/** Inline callback for this operation. */ fn(SavedPaymentMethod $method)=>$method->isActive())?->update(['is_default'=>true]);
            }
        }, 3);

        $events->record($request->user(), 'payment_method_revoked', SecuritySeverity::High, $request, [
            'paymentMethodId'=>$paymentMethod->public_id, 'provider'=>$paymentMethod->provider, 'last4'=>$paymentMethod->last4,
        ]);
        return response()->json(['data'=>['ok'=>true]]);
    }
}
