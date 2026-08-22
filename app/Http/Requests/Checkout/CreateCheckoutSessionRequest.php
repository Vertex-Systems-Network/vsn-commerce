<?php

namespace App\Http\Requests\Checkout;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Defines the CreateCheckoutSessionRequest class and its project responsibilities. */
class CreateCheckoutSessionRequest extends FormRequest
{
    /** Handles authorize for the create checkout session request workflow. */
    public function authorize(): bool { return true; }

    /** Handles rules for the create checkout session request workflow. */
    public function rules(): array
    {
        return [
            'addressId' => ['required', 'integer', 'exists:addresses,id'],
            'shippingMethod' => ['required', 'string', 'max:60'],
            'paymentMethod' => ['required', Rule::in(['cod', 'card', 'coins'])],
            'savedPaymentMethodId' => ['nullable', 'string', 'max:40'],
            'couponCode' => ['nullable', 'string', 'max:80'],
            'coinRedemptionCoins' => ['sometimes', 'integer', 'min:0'],
            'idempotencyKey' => ['required', 'string', 'min:8', 'max:120'],
        ];
    }
}
