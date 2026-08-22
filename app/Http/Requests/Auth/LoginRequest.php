<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/** Defines the LoginRequest class and its project responsibilities. */
class LoginRequest extends FormRequest
{
    /** Handles prepare for validation for the login request workflow. */
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
    }

    /** Handles authorize for the login request workflow. */
    public function authorize(): bool
    {
        return true;
    }

    /** Handles rules for the login request workflow. */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }
}
