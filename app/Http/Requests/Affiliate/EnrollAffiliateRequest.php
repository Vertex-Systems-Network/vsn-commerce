<?php
namespace App\Http\Requests\Affiliate;

use Illuminate\Foundation\Http\FormRequest;

/** Defines the EnrollAffiliateRequest class and its project responsibilities. */
class EnrollAffiliateRequest extends FormRequest
{
    /** Handles authorize for the enroll affiliate request workflow. */
    public function authorize(): bool { return true; }
    /** Handles rules for the enroll affiliate request workflow. */
    public function rules(): array { return ['acceptTerms'=>['accepted']]; }
}
