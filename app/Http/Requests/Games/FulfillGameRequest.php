<?php
namespace App\Http\Requests\Games;

use Illuminate\Foundation\Http\FormRequest;

/** Defines the FulfillGameRequest class and its project responsibilities. */
class FulfillGameRequest extends FormRequest
{
    /** Handles authorize for the fulfill game request workflow. */
    public function authorize(): bool { return true; }
    /** Handles rules for the fulfill game request workflow. */
    public function rules(): array
    {
        return [
            'method'=>['nullable','string','max:50'],
            'reference'=>['nullable','string','max:190'],
            'note'=>['nullable','string','max:1000'],
        ];
    }
}
