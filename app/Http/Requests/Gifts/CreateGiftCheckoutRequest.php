<?php

namespace App\Http\Requests\Gifts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Defines the CreateGiftCheckoutRequest class and its project responsibilities. */
class CreateGiftCheckoutRequest extends FormRequest
{
    /** Handles authorize for the create gift checkout request workflow. */
    public function authorize(): bool { return true; }
    /** Handles rules for the create gift checkout request workflow. */
    public function rules(): array
    {
        return [
            'recipient'=>['required','string','max:190'],
            'variantId'=>['nullable','integer'],
            'productId'=>['nullable','integer'],
            'productSlug'=>['nullable','string','max:190'],
            'selectedVariant'=>['nullable','string','max:160'],
            'selectedOptions'=>['nullable','array'],
            'selectedOptions.*'=>['nullable','string','max:120'],
            'message'=>['nullable','string','max:500'],
            'giftWrap'=>['sometimes','boolean'],
            'anonymous'=>['sometimes','boolean'],
            'scheduledFor'=>['nullable','date'],
            'shippingMethod'=>['required','string','max:60'],
            'paymentMethod'=>['required', Rule::in(['card','coins','cod'])],
            'savedPaymentMethodId'=>['nullable','string','max:40'],
            'coinRedemptionCoins'=>['sometimes','integer','min:0'],
            'idempotencyKey'=>['required','string','min:8','max:120'],
        ];
    }
}
