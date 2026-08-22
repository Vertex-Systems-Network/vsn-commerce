<?php
namespace App\Domain\Kyc\Providers;
use App\Domain\Kyc\Contracts\KycProvider;
use App\Models\KycVerification;
/** Defines the ManualKycProvider class and its project responsibilities. */
class ManualKycProvider implements KycProvider { public function name():string{return 'manual';} public function submit(KycVerification $verification):array{return ['status'=>'pending','reference'=>null,'payload'=>['mode'=>'manual_review']];} }
