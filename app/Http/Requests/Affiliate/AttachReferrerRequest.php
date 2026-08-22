<?php
namespace App\Http\Requests\Affiliate;

use Illuminate\Foundation\Http\FormRequest;

/** Defines the AttachReferrerRequest class and its project responsibilities. */
class AttachReferrerRequest extends FormRequest
{
    /** Handles prepare for validation for the attach referrer request workflow. */
    protected function prepareForValidation(): void { if ($this->has('referralCode')) $this->merge(['referralCode'=>strtoupper(trim((string)$this->input('referralCode')))]); }
    /** Handles authorize for the attach referrer request workflow. */
    public function authorize(): bool { return true; }
    /** Handles rules for the attach referrer request workflow. */
    public function rules(): array { return ['referralCode'=>['required','string','max:24']]; }
}
