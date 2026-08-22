<?php
namespace App\Domain\Kyc\Contracts;
use App\Models\KycVerification;
/** Defines the KycProvider interface and its project responsibilities. */
interface KycProvider { public function name():string; public function submit(KycVerification $verification):array; }
