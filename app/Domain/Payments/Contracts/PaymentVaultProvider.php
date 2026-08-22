<?php
namespace App\Domain\Payments\Contracts;
use App\Domain\Payments\Data\VaultedPaymentMethodData;
/** Defines the PaymentVaultProvider interface and its project responsibilities. */
interface PaymentVaultProvider{public function code():string;public function inspectToken(string $providerToken):VaultedPaymentMethodData;public function detach(string $providerToken):void;}
