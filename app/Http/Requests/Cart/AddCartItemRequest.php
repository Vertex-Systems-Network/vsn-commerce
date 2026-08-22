<?php

namespace App\Http\Requests\Cart;

use Illuminate\Foundation\Http\FormRequest;

/** Defines the AddCartItemRequest class and its project responsibilities. */
class AddCartItemRequest extends FormRequest
{
    /** Handles authorize for the add cart item request workflow. */
    public function authorize(): bool
    {
        return true;
    }

    /** Handles rules for the add cart item request workflow. */
    public function rules(): array
    {
        return [
            'variantId' => ['nullable', 'integer', 'exists:product_variants,id', 'required_without_all:productId,productSlug'],
            'productId' => ['nullable', 'integer', 'exists:products,id', 'required_without_all:variantId,productSlug'],
            'productSlug' => ['nullable', 'string', 'max:190', 'exists:products,slug', 'required_without_all:variantId,productId'],
            'selectedVariant' => ['nullable', 'string', 'max:160'],
            'selectedOptions' => ['nullable', 'array', 'max:10'],
            'selectedOptions.*' => ['nullable', 'string', 'max:160'],
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ];
    }
}
