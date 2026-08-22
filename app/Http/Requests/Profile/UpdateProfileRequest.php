<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

/** Defines the UpdateProfileRequest class and its project responsibilities. */
class UpdateProfileRequest extends FormRequest
{
    /** Handles authorize for the update profile request workflow. */
    public function authorize(): bool
    {
        return true;
    }

    /** Handles rules for the update profile request workflow. */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'locale' => ['sometimes', 'nullable', 'string', 'max:12'],
            'timezone' => ['sometimes', 'nullable', 'timezone'],
        ];
    }
}
