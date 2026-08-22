<?php

namespace App\Http\Requests\Cart;

use Illuminate\Foundation\Http\FormRequest;

/** Defines the MergeCartRequest class and its project responsibilities. */
class MergeCartRequest extends FormRequest
{
    /** Handles authorize for the merge cart request workflow. */
    public function authorize(): bool
    {
        return true;
    }

    /** Handles rules for the merge cart request workflow. */
    public function rules(): array
    {
        return [
            'guestToken' => ['nullable', 'uuid'],
        ];
    }
}
