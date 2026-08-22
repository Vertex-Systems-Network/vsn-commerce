<?php
namespace App\Domain\Tax\Services;
use App\Models\TaxJurisdiction;
/** Defines the TaxJurisdictionResolver class and its project responsibilities. */
class TaxJurisdictionResolver {
 /** Handles resolve for the tax jurisdiction resolver workflow. */
 public function resolve(array $address):?TaxJurisdiction { $country=strtoupper((string)($address['country_code']??$address['countryCode']??'')); if(strlen($country)!==2)return null;$region=trim((string)($address['state']??''));$q=TaxJurisdiction::query()->where('status','active')->where('country_code',$country); if($region!==''){ $exact=(clone $q)->whereRaw('lower(region_code)=?', [mb_strtolower($region)])->orderByDesc('priority')->first(); if($exact)return $exact;} return $q->whereNull('region_code')->orderByDesc('priority')->first(); }
}