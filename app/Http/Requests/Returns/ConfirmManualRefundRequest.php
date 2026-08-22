<?php
namespace App\Http\Requests\Returns;
use Illuminate\Foundation\Http\FormRequest;
/** Defines the ConfirmManualRefundRequest class and its project responsibilities. */
class ConfirmManualRefundRequest extends FormRequest
{
    /** Handles authorize for the confirm manual refund request workflow. */
    public function authorize():bool{return true;}
    /** Handles rules for the confirm manual refund request workflow. */
    public function rules():array{return ['reference'=>['required','string','max:190'],'note'=>['nullable','string','max:2000']];}
}
