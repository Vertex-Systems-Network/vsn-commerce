<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/** Defines the ReserveInventoryRequest class and its project responsibilities. */
class ReserveInventoryRequest extends FormRequest
{
    /** Handles authorize for the reserve inventory request workflow. */
    public function authorize(): bool
    {
        return true;
    }

    /** Handles rules for the reserve inventory request workflow. */
    public function rules(): array
    {
        return [
            'variantId' => ['required', 'integer', 'exists:product_variants,id'],
            'warehouseId' => ['sometimes', 'integer', 'exists:warehouses,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'idempotencyKey' => ['required', 'string', 'max:120'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }
}
