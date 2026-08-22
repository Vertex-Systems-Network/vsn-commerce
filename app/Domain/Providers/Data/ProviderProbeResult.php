<?php
namespace App\Domain\Providers\Data;
/** Defines the ProviderProbeResult class and its project responsibilities. */
final readonly class ProviderProbeResult
{
    /** Initializes the ProviderProbeResult instance and its dependencies. */
    public function __construct(public bool $healthy,public bool $productionReady,public string $message,public array $details=[]){ }
}
