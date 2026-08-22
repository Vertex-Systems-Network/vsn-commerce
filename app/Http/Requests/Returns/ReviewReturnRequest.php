<?php
namespace App\Http\Requests\Returns;
use Illuminate\Foundation\Http\FormRequest;
/** Defines the ReviewReturnRequest class and its project responsibilities. */
class ReviewReturnRequest extends FormRequest
{
    /** Handles authorize for the review return request workflow. */
    public function authorize():bool{return true;}
    /** Handles rules for the review return request workflow. */
    public function rules():array{return [
        'approve'=>['required','boolean'],
        'resolution'=>['nullable','in:refund_original,coins,replacement'],
        'note'=>['nullable','string','max:3000'],
        'items'=>['sometimes','array','min:1'],
        'items.*.returnRequestItemId'=>['required_with:items','integer','min:1'],
        'items.*.approvedQuantity'=>['required_with:items','integer','min:0'],
        'items.*.restock'=>['sometimes','boolean'],
    ];}
}
