<?php

/**
 * VSN Ecommerce marketplace media/storefront source contract audit.
 *
 * This dependency-free gate protects reusable media ownership, seller storefront
 * routing/privacy, server-authoritative customer data, admin spacing and known
 * logical regressions before Laravel runtime dependencies are installed.
 */
$root = dirname(__DIR__);
$checks = [];
$failures = [];

/** Records one source-contract assertion and prints its status. */
function marketplaceCheck(array &$checks, array &$failures, string $name, bool $ok, string $detail = ''): void
{
    $checks[] = $name;
    echo sprintf('[%s] %s%s', $ok ? 'PASS' : 'FAIL', $name, $detail !== '' ? " — {$detail}" : '').PHP_EOL;
    if (! $ok) {
        $failures[] = $name.($detail !== '' ? ": {$detail}" : '');
    }
}

/** Reads a required project file for deterministic string-level checks. */
function marketplaceRead(string $root, string $relative): string
{
    $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

    return is_file($path) ? (string) file_get_contents($path) : '';
}

$required = [
    'app/Models/MediaLibraryAsset.php',
    'app/Domain/Catalog/Services/MediaLibraryService.php',
    'app/Domain/Catalog/Services/VendorStorefrontMediaService.php',
    'app/Http/Controllers/Api/V1/MediaLibraryController.php',
    'app/Http/Controllers/Api/V1/PublicVendorController.php',
    'resources/js/components/MediaLibraryPanel.jsx',
    'resources/js/pages/AdminMedia.jsx',
    'resources/js/pages/VendorMedia.jsx',
    'resources/js/pages/Vendors.jsx',
    'tests/Feature/MarketplaceMediaStorefrontTest.php',
    'tests/Feature/CatalogMediaWriteContractTest.php',
    'tests/Unit/MarketplaceFeatureContractTest.php',
];
foreach ($required as $file) {
    marketplaceCheck($checks, $failures, "required file {$file}", is_file($root.'/'.$file));
}

$migration = marketplaceRead($root, 'database/migrations/2026_08_14_001000_add_media_library_and_vendor_storefront.php');
$routes = marketplaceRead($root, 'routes/api.php');
$rbac = marketplaceRead($root, 'app/Security/Rbac.php');
$service = marketplaceRead($root, 'app/Domain/Catalog/Services/MediaLibraryService.php');
$storefrontMedia = marketplaceRead($root, 'app/Domain/Catalog/Services/VendorStorefrontMediaService.php');
$productMedia = marketplaceRead($root, 'app/Domain/Catalog/Services/ProductMediaService.php');
$catalogMutation = marketplaceRead($root, 'app/Domain/Catalog/Services/CatalogMutationService.php');
$mediaController = marketplaceRead($root, 'app/Http/Controllers/Api/V1/MediaLibraryController.php');
$publicVendor = marketplaceRead($root, 'app/Http/Controllers/Api/V1/PublicVendorController.php');
$sellerOps = marketplaceRead($root, 'app/Http/Controllers/Api/V1/SellerOperationsController.php');
$sellerCatalog = marketplaceRead($root, 'app/Http/Controllers/Api/V1/SellerCatalogController.php');
$adminCatalog = marketplaceRead($root, 'app/Http/Controllers/Api/V1/AdminCatalogController.php');
$app = marketplaceRead($root, 'resources/js/App.jsx');
$adminShell = marketplaceRead($root, 'resources/js/layout/AdminShell.jsx');
$vendorShell = marketplaceRead($root, 'resources/js/layout/VendorShell.jsx');
$productEditor = marketplaceRead($root, 'resources/js/pages/CatalogManagement.jsx');
$sellerCenter = marketplaceRead($root, 'resources/js/pages/SellerCenter.jsx');
$home = marketplaceRead($root, 'resources/js/pages/Home.jsx');
$productPage = marketplaceRead($root, 'resources/js/pages/Product.jsx');
$store = marketplaceRead($root, 'resources/js/platform/store.jsx');
$styles = marketplaceRead($root, 'resources/js/styles.scss');
$featureTests = marketplaceRead($root, 'tests/Feature/MarketplaceMediaStorefrontTest.php');
$catalogMediaTests = marketplaceRead($root, 'tests/Feature/CatalogMediaWriteContractTest.php');

marketplaceCheck($checks, $failures, 'media_library_assets migration exists', str_contains($migration, "Schema::create('media_library_assets'"));
foreach (['public_id', 'vendor_id', 'uploaded_by_user_id', 'scope_key', 'sha256', 'width', 'height', 'status'] as $column) {
    marketplaceCheck($checks, $failures, "media library column {$column}", str_contains($migration, "'{$column}'"));
}
marketplaceCheck($checks, $failures, 'scope SHA uniqueness', str_contains($migration, 'media_library_scope_sha_unique'));
marketplaceCheck($checks, $failures, 'secure upload inspector used', str_contains($service, 'SecureUploadInspector') && str_contains($service, '$this->uploads->inspect'));
marketplaceCheck($checks, $failures, 'shared media attach avoids binary duplication', str_contains($service, "'source'=>'media_library'") && str_contains($service, "'media_library_asset_id'"));
marketplaceCheck($checks, $failures, 'shared product detach preserves library binary', str_contains($productMedia, "if(\$source!=='media_library')Storage::disk"));
marketplaceCheck($checks, $failures, 'unused library archive removes binary', str_contains($service, 'Storage::disk($asset->disk)->delete($asset->path)'));
marketplaceCheck($checks, $failures, 'seller media hides uploader identity', str_contains($mediaController, '$includeUploader && $asset->uploader'));
marketplaceCheck($checks, $failures, 'seller media scope includes own/global only', str_contains($mediaController, "where('vendor_id',\$vendor->id)->orWhereNull('vendor_id')"));
marketplaceCheck($checks, $failures, 'cross-vendor product media attach blocked', str_contains($mediaController, 'Vendor media can only be attached to that vendor'));

foreach ([
    "Route::get('/vendors'",
    "Route::get('/vendors/{slug}'",
    "Route::get('/vendor/media-library'",
    "Route::post('/vendor/media-library'",
    "Route::get('/admin/media-library'",
    "Route::post('/admin/media-library'",
] as $route) {
    marketplaceCheck($checks, $failures, "route {$route}", str_contains($routes, $route));
}
marketplaceCheck($checks, $failures, 'admin media RBAC mapped', str_contains($rbac, 'admin/(?:media|media-library)'));
marketplaceCheck($checks, $failures, 'seller media RBAC mapped', str_contains($rbac, 'vendor/media-library'));

marketplaceCheck($checks, $failures, 'seller slug validation is unique and normalized', str_contains($sellerOps, "Rule::unique('vendors','slug')") && str_contains($sellerOps, 'regex:/^[a-z0-9]+'));
marketplaceCheck($checks, $failures, 'seller controls storefront visibility', str_contains($sellerOps, "'storefrontEnabled' => 'required|boolean'"));
marketplaceCheck($checks, $failures, 'seller logo canonical persistence is a media asset id', str_contains($sellerOps, "'logoMediaAssetId' => 'nullable|string|max:26'") && str_contains($sellerOps, "\$metadata['logoMediaAssetId'] = \$logo->public_id") && str_contains($sellerOps, "unset(\$metadata['logoUrl'])"));
marketplaceCheck($checks, $failures, 'seller logo selection is scoped to active own/global media', str_contains($storefrontMedia, "where('status', 'active')") && str_contains($storefrontMedia, "whereNull('vendor_id')->orWhere('vendor_id', \$vendor->id)"));
marketplaceCheck($checks, $failures, 'seller logo URL is derived from storage at response time', str_contains($storefrontMedia, 'Storage::disk($asset->disk)->url($asset->path)') && str_contains($publicVendor, "'logoMediaAssetId'=>\$logo['logoMediaAssetId']") && str_contains($publicVendor, "'logoUrl'=>\$logo['logoUrl']"));
marketplaceCheck($checks, $failures, 'public vendor output uses explicit public support email only', str_contains($publicVendor, "'supportEmail'=>\$meta['publicSupportEmail']??null") && ! str_contains($publicVendor, "\$meta['supportEmail']"));
marketplaceCheck($checks, $failures, 'public vendor products are published-only', str_contains($publicVendor, "where('status','published')"));

marketplaceCheck($checks, $failures, 'product editor exposes reusable media picker', str_contains($productEditor, '<MediaLibraryPanel'));
marketplaceCheck($checks, $failures, 'product editor has no editable legacy imageUrl state/payload', ! str_contains($productEditor, 'imageUrl') && ! str_contains($productEditor, 'images:form.'));
marketplaceCheck($checks, $failures, 'seller catalog prohibits arbitrary image payloads', str_contains($sellerCatalog, "'images'=>['prohibited']"));
marketplaceCheck($checks, $failures, 'admin catalog prohibits arbitrary image payloads', str_contains($adminCatalog, "'images'=>['prohibited']"));
marketplaceCheck($checks, $failures, 'catalog mutation service cannot create legacy URL image rows', ! str_contains($catalogMutation, 'syncImages(') && ! str_contains($catalogMutation, "'source'=>'legacy_url'"));
marketplaceCheck($checks, $failures, 'product URL write rejection behavior test present', str_contains($catalogMediaTests, 'test_seller_product_create_rejects_arbitrary_image_urls'));

marketplaceCheck($checks, $failures, 'public all-vendors SPA route', str_contains($app, 'path="/vendors"'));
marketplaceCheck($checks, $failures, 'public seller-shop SPA route', str_contains($app, 'path="/shop/:slug"'));
marketplaceCheck($checks, $failures, 'vendor media navigation', str_contains($vendorShell, '/vendor/media'));
marketplaceCheck($checks, $failures, 'dead admin migration navigation removed', ! str_contains($adminShell, '/admin/migration'));
marketplaceCheck($checks, $failures, 'obsolete read-only AdminMediaController API removed', ! is_file($root.'/app/Http/Controllers/Api/V1/AdminMediaController.php') && ! str_contains($routes, 'AdminMediaController'));
marketplaceCheck($checks, $failures, 'seller logo picker uses seller media library', str_contains($sellerCenter, '<MediaLibraryPanel mode="vendor" compact'));
marketplaceCheck($checks, $failures, 'seller shop link is exposed', str_contains($sellerCenter, 'Your public shop') && str_contains($sellerCenter, 'Copy link'));
marketplaceCheck($checks, $failures, 'admin workspace spacing override present', str_contains($styles, '.admin-content') && str_contains($styles, '.admin-content>.simple-page'));

marketplaceCheck($checks, $failures, 'home no longer imports static catalog or legacy StoreProvider state', ! str_contains($home, '../data/catalog') && ! str_contains($home, '../platform/store') && ! str_contains($home, 'apiBackend'));
marketplaceCheck($checks, $failures, 'home uses only server-authorized game collection', str_contains($home, 'const liveGames=lg.games.filter'));
marketplaceCheck($checks, $failures, 'home loads live public categories/vendors/products', str_contains($home, "apiGet('/categories')") && str_contains($home, "apiGet('/vendors')") && str_contains($home, "apiGet('/products?sort=popular&perPage=12')"));
marketplaceCheck($checks, $failures, 'Laravel product page blocks demo fallback on API failure', str_contains($productPage, "if (apiBackend==='laravel' && remoteLoading)") && str_contains($productPage, "if (apiBackend==='laravel' && remoteError && !remoteProduct)"));
marketplaceCheck($checks, $failures, 'personal mock orders removed', str_contains($store, 'const initialOrders=[];'));
marketplaceCheck($checks, $failures, 'personal mock notifications removed', str_contains($store, 'const initialNotifications=[];'));
marketplaceCheck($checks, $failures, 'personal mock messages removed', str_contains($store,'const initialMessages=[];'));
marketplaceCheck($checks,$failures,'personal mock identity removed',! str_contains($store,'Muhammad Ahmed Khan') && ! str_contains($store,'ahmed@example.com'));
marketplaceCheck($checks,$failures,'dummy seller score rows removed',str_contains($store,'const sellerScores=[];'));

marketplaceCheck($checks,$failures,'customer data isolation regression test present',str_contains($featureTests,'test_customer_address_data_is_scoped_to_authenticated_user'));
marketplaceCheck($checks,$failures,'seller logo stable-id regression test present',str_contains($featureTests,'test_seller_logo_persists_media_asset_reference_not_delivery_url'));
marketplaceCheck($checks,$failures,'cross-vendor seller logo regression test present',str_contains($featureTests,'test_seller_cannot_select_cross_vendor_logo_media'));
marketplaceCheck($checks,$failures,'public seller logo resolution regression test present',str_contains($featureTests,'test_public_vendor_logo_is_resolved_from_media_library_reference'));
marketplaceCheck($checks,$failures,'media RBAC regression test present',str_contains(marketplaceRead($root,'tests/Unit/MarketplaceFeatureContractTest.php'),'test_media_library_routes_have_rbac_mappings'));

printf("Marketplace media/storefront audit: %d/%d PASS\n", count($checks) - count($failures), count($checks));
if ($failures) {
    echo "Failures:\n - ".implode("\n - ",$failures).PHP_EOL;
    exit(1);
}
