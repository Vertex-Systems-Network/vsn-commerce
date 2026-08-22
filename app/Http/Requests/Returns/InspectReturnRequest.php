<?php
namespace App\Http\Requests\Returns;
use Illuminate\Foundation\Http\FormRequest;
/** Defines the InspectReturnRequest class and its project responsibilities. */
class InspectReturnRequest extends FormRequest
{
    /** Handles authorize for the inspect return request workflow. */
    public function authorize(): bool{return true;}
    /** Handles rules for the inspect return request workflow. */
    public function rules(): array{return [
        'note'=>['nullable','string','max:3000'],
        'items'=>['sometimes','array','min:1'],
        'items.*.returnRequestItemId'=>['required_with:items','integer','min:1'],
        'items.*.receivedQuantity'=>['required_with:items','integer','min:0'],
        'items.*.acceptedQuantity'=>['required_with:items','integer','min:0'],
        'items.*.restock'=>['sometimes','boolean'],
        'items.*.condition'=>['nullable','in:resellable,opened,damaged,wrong_item,missing'],
        'items.*.note'=>['nullable','string','max:1000'],
    ];}
}
