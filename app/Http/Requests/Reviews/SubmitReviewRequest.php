<?php

namespace App\Http\Requests\Reviews;

use Illuminate\Foundation\Http\FormRequest;

/** Defines the SubmitReviewRequest class and its project responsibilities. */
class SubmitReviewRequest extends FormRequest
{
    /** Handles authorize for the submit review request workflow. */
    public function authorize(): bool { return $this->user() !== null; }
    /** Handles rules for the submit review request workflow. */
    public function rules(): array
    {
        return [
            'orderItemId'=>['required','integer','exists:order_items,id'],
            'rating'=>['required','integer','between:1,5'],
            'text'=>['required','string','min:10','max:3000'],
            'images'=>['sometimes','array','max:4'],
            'images.*'=>['file','image','mimes:jpeg,jpg,png,webp','max:5120'],
        ];
    }
}
