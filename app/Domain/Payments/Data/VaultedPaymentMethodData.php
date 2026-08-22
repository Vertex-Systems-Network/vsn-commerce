<?php
namespace App\Domain\Payments\Data;
/** Defines the VaultedPaymentMethodData class and its project responsibilities. */
final readonly class VaultedPaymentMethodData{public function __construct(public string $providerToken,public string $brand,public string $last4,public int $expMonth,public int $expYear,public string $fingerprint,public ?string $holderName=null,public array $metadata=[]){}}
