<?php

namespace App\Http\Requests\Reviews;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Defines the ModerateReviewRequest class and its project responsibilities. */
class ModerateReviewRequest extends FormRequest
{
    /** Handles authorize for the moderate review request workflow. */
    public function authorize(): bool { return $this->user() !== null; }
    /** Handles rules for the moderate review request workflow. */
    public function rules(): array
    {
        return ['status'=>['required', Rule::in(['approved','rejected'])], 'note'=>['nullable','string','max:2000']];
    }
}
