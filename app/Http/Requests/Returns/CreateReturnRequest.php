<?php
namespace App\Http\Requests\Returns;
use Illuminate\Foundation\Http\FormRequest;
/** Defines the CreateReturnRequest class and its project responsibilities. */
class CreateReturnRequest extends FormRequest
{
    /** Handles authorize for the create return request workflow. */
    public function authorize(): bool { return true; }
    /** Handles rules for the create return request workflow. */
    public function rules(): array { return [
        'orderId'=>['required','string','max:40'],'reason'=>['required','string','max:120'],'resolution'=>['required','in:refund_original,coins,replacement,dispute'],'details'=>['nullable','string','max:5000'],
        'items'=>['sometimes','array','min:1'],'items.*.orderItemId'=>['required_with:items','integer','min:1'],'items.*.quantity'=>['required_with:items','integer','min:1'],
    ]; }
}
