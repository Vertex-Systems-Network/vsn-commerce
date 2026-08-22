<?php
namespace App\Http\Requests\Games;

use Illuminate\Foundation\Http\FormRequest;

/** Defines the CreateGameRequest class and its project responsibilities. */
class CreateGameRequest extends FormRequest
{
    /** Handles authorize for the create game request workflow. */
    public function authorize(): bool { return true; }
    /** Handles rules for the create game request workflow. */
    public function rules(): array
    {
        return [
            'productSlug'=>['required','string','exists:products,slug'],
            'entryCoins'=>['required','integer','min:1'],
            'maxEntries'=>['nullable','integer','min:1'],
            'maxEntriesPerUser'=>['nullable','integer','min:1'],
            'winnerBonusCoins'=>['nullable','integer','min:0','max:100000000'],
            'opensAt'=>['required','date'],
            'closesAt'=>['required','date','after:opensAt'],
            'announcementAt'=>['required','date','after_or_equal:closesAt'],
            'rulesVersion'=>['nullable','string','max:60'],
        ];
    }
}
