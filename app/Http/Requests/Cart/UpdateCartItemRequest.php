<?php

namespace App\Http\Requests\Cart;

use Illuminate\Foundation\Http\FormRequest;

/** Defines the UpdateCartItemRequest class and its project responsibilities. */
class UpdateCartItemRequest extends FormRequest
{
    /** Handles authorize for the update cart item request workflow. */
    public function authorize(): bool
    {
        return true;
    }

    /** Handles rules for the update cart item request workflow. */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:0', 'max:99'],
        ];
    }
}
