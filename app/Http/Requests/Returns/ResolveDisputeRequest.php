<?php
namespace App\Http\Requests\Returns;
use Illuminate\Foundation\Http\FormRequest;
/** Defines the ResolveDisputeRequest class and its project responsibilities. */
class ResolveDisputeRequest extends FormRequest { public function authorize():bool{return true;} public function rules():array{return ['outcome'=>['required','in:refund_original,coins,replacement,rejected'],'note'=>['nullable','string','max:3000']];} }
