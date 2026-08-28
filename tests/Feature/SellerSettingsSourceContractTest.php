<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Guards the current seller settings UI against reverting to delivery URLs as media identity. */
class SellerSettingsSourceContractTest extends TestCase
{
    /** Confirms seller logo selection carries a stable media id and keeps the URL preview-only. */
    public function test_seller_settings_uses_stable_media_id_as_logo_identity(): void
    {
        $source = file_get_contents(resource_path('js/pages/SellerCenter.jsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString("logoMediaAssetId:v.logoMediaAssetId||''", $source);
        $this->assertStringContainsString("set('logoMediaAssetId',item.id);setLogoPreviewUrl(item.url)", $source);
        $this->assertStringContainsString("apiPut('/vendor/settings',form)", $source);
        $this->assertStringContainsString("set('logoMediaAssetId','');setLogoPreviewUrl('')", $source);
        $this->assertStringNotContainsString("set('logoUrl',item.url)", $source);
        $this->assertStringNotContainsString("logoUrl:v.logoUrl||''", $source);
    }
}
