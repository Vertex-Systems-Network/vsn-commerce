<?php
namespace App\Http\Resources;
use Illuminate\Http\Request;use Illuminate\Http\Resources\Json\JsonResource;
/** Defines the SavedPaymentMethodResource class and its project responsibilities. */
class SavedPaymentMethodResource extends JsonResource{public function toArray(Request $request):array{return ['id'=>$this->public_id,'provider'=>$this->provider,'type'=>$this->payment_method,'brand'=>$this->brand,'last4'=>$this->last4,'expiry'=>$this->exp_month&&$this->exp_year?['month'=>$this->exp_month,'year'=>$this->exp_year]:null,'holderName'=>$this->holder_name,'default'=>(bool)$this->is_default,'status'=>$this->status,'verifiedAt'=>$this->verified_at?->toIso8601String(),'lastUsedAt'=>$this->last_used_at?->toIso8601String()];}}
