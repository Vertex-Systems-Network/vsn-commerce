<?php
namespace App\Domain\Providers\Contracts;
use App\Domain\Providers\Data\ProviderProbeResult;
/** Defines the ProviderProbe interface and its project responsibilities. */
interface ProviderProbe
{
    /** Handles provider type for the provider probe workflow. */
    public function providerType():string;
    /** Handles provider code for the provider probe workflow. */
    public function providerCode():string;
    /** Handles probe for the provider probe workflow. */
    public function probe():ProviderProbeResult;
}
