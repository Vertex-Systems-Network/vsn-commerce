<?php
namespace App\Http\Requests\Wallet;
use Illuminate\Foundation\Http\FormRequest;
/** Defines the TransferCoinsRequest class and its project responsibilities. */
class TransferCoinsRequest extends FormRequest
{
    /** Handles authorize for the transfer coins request workflow. */
    public function authorize(): bool { return true; }
    /** Handles rules for the transfer coins request workflow. */
    public function rules(): array { return ['recipient'=>['required','string','max:190'],'coins'=>['required','integer','min:1'],'idempotencyKey'=>['required','string','min:8','max:190']]; }
}
