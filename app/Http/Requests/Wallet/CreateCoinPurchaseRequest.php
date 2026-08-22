<?php
namespace App\Http\Requests\Wallet;
use Illuminate\Foundation\Http\FormRequest;
/** Defines the CreateCoinPurchaseRequest class and its project responsibilities. */
class CreateCoinPurchaseRequest extends FormRequest
{
    /** Handles authorize for the create coin purchase request workflow. */
    public function authorize(): bool { return true; }
    /** Handles rules for the create coin purchase request workflow. */
    public function rules(): array { return ['coins'=>['required','integer','min:1'],'idempotencyKey'=>['required','string','min:8','max:190']]; }
}
