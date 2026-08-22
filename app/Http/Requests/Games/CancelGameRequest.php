<?php
namespace App\Http\Requests\Games;

use Illuminate\Foundation\Http\FormRequest;

/** Defines the CancelGameRequest class and its project responsibilities. */
class CancelGameRequest extends FormRequest
{
    /** Handles authorize for the cancel game request workflow. */
    public function authorize(): bool { return true; }
    /** Handles rules for the cancel game request workflow. */
    public function rules(): array { return ['reason'=>['required','string','min:5','max:1000']]; }
}
