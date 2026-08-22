<?php

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;

/** Defines the CreatePaymentIntentRequest class and its project responsibilities. */
class CreatePaymentIntentRequest extends FormRequest
{
    /** Handles authorize for the create payment intent request workflow. */
    public function authorize(): bool { return true; }

    /** Handles rules for the create payment intent request workflow. */
    public function rules(): array
    {
        return [
            'idempotencyKey' => ['required', 'string', 'min:8', 'max:120'],
        ];
    }
}
