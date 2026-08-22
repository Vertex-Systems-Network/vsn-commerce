<?php
namespace App\Http\Requests\Returns;
use Illuminate\Foundation\Http\FormRequest;
/** Defines the ShipReturnRequest class and its project responsibilities. */
class ShipReturnRequest extends FormRequest
{
    /** Handles authorize for the ship return request workflow. */
    public function authorize(): bool{return true;}
    /** Handles rules for the ship return request workflow. */
    public function rules():array{return [
        'trackingReference'=>['required','string','max:190'],
        'carrier'=>['nullable','string','max:120'],
    ];}
}
