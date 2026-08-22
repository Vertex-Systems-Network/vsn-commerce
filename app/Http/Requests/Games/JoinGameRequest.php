<?php
namespace App\Http\Requests\Games;

use Illuminate\Foundation\Http\FormRequest;

/** Defines the JoinGameRequest class and its project responsibilities. */
class JoinGameRequest extends FormRequest
{
    /** Handles authorize for the join game request workflow. */
    public function authorize(): bool { return true; }
    /** Handles rules for the join game request workflow. */
    public function rules(): array
    {
        return [
            'entries'=>['required','integer','min:1','max:20'],
            'idempotencyKey'=>['required','string','min:8','max:190'],
            'acceptRules'=>['accepted'],
        ];
    }
}
