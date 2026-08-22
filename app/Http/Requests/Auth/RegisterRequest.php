<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/** Defines the RegisterRequest class and its project responsibilities. */
class RegisterRequest extends FormRequest
{
    /** Handles prepare for validation for the register request workflow. */
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
    }

    /** Handles authorize for the register request workflow. */
    public function authorize(): bool
    {
        return true;
    }

    /** Handles rules for the register request workflow. */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(10)->mixedCase()->numbers()],
            'referral_code' => ['nullable', 'string', 'max:24'],
        ];
    }
}
