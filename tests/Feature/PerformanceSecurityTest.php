<?php

namespace Tests\Feature;

use App\Domain\Catalog\Services\CatalogCache;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Defines the PerformanceSecurityTest class and its project responsibilities. */
class PerformanceSecurityTest extends TestCase
{
    use RefreshDatabase;

    /** Verifies public catalog response has security and edge cache headers. */
    public function test_public_catalog_response_has_security_and_edge_cache_headers(): void
    {
        Category::create(['name'=>'AV Category','slug'=>'av-category','is_active'=>true,'sort_order'=>1]);
        $this->getJson('/api/v1/categories')->assertOk()
            ->assertHeader('X-Content-Type-Options','nosniff')
            ->assertHeader('X-Frame-Options','DENY')
            ->assertHeader('Cross-Origin-Resource-Policy','same-site')
            ->assertHeader('Cache-Control','public, max-age=30, stale-while-revalidate=60');
    }

    /** Verifies authenticated api response is not cacheable. */
    public function test_authenticated_api_response_is_not_cacheable(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertHeader('Cache-Control','no-store, private')
            ->assertHeader('Pragma','no-cache');
    }

    /** Verifies catalog cache version can invalidate cached public dimensions. */
    public function test_catalog_cache_version_can_invalidate_cached_public_dimensions(): void
    {
        Cache::flush();
        $cache=app(CatalogCache::class);
        $first=$cache->version();
        $cache->remember('probe',120,/** Inline callback for this operation. */ fn()=>['value'=>1]);
        $this->assertSame(['value'=>1],$cache->remember('probe',120,/** Inline callback for this operation. */ fn()=>['value'=>2]));
        $this->assertSame($first+1,$cache->bump());
        $this->assertSame(['value'=>2],$cache->remember('probe',120,/** Inline callback for this operation. */ fn()=>['value'=>2]));
    }

    /** Verifies av rate limit and request budget guards are wired. */
    public function test_av_rate_limit_and_request_budget_guards_are_wired(): void
    {
        $routes=file_get_contents(base_path('routes/api.php'));
        $provider=file_get_contents(app_path('Providers/AppServiceProvider.php'));
        $bootstrap=file_get_contents(base_path('bootstrap/app.php'));
        foreach(['catalog-read','commerce-write','upload','provider-webhook','sensitive'] as $limiter){
            $this->assertStringContainsString("RateLimiter::for('{$limiter}'",$provider);
            $this->assertStringContainsString("throttle:{$limiter}",$routes);
        }
        $this->assertStringContainsString('LimitRequestBody::class',$bootstrap);
        $this->assertStringContainsString('RequestPerformanceTelemetry::class',$bootstrap);
    }

    /** Verifies upload and csp hardening are present on all sensitive media flows. */
    public function test_upload_and_csp_hardening_are_present_on_all_sensitive_media_flows(): void
    {
        $paths=[
            app_path('Domain/Catalog/Services/ProductMediaService.php'),
            app_path('Http/Controllers/Api/V1/KycController.php'),
            app_path('Http/Controllers/Api/V1/ReviewController.php'),
            app_path('Http/Controllers/Api/V1/MessageController.php'),
        ];
        foreach($paths as $path)$this->assertStringContainsString('SecureUploadInspector',file_get_contents($path));
        $headers=file_get_contents(app_path('Http/Middleware/WebSecurityHeaders.php'));
        $this->assertStringContainsString('Content-Security-Policy',$headers);
        $this->assertStringContainsString("frame-ancestors 'none'",$headers);
    }

    /** Verifies frontend and database performance guards are release gated. */
    public function test_frontend_and_database_performance_guards_are_release_gated(): void
    {
        $app=file_get_contents(resource_path('js/App.jsx'));
        $this->assertGreaterThanOrEqual(40,substr_count($app,'lazy(() => import(')+substr_count($app,'lazyNamed(() => import('));
        $migration=file_get_contents(database_path('migrations/2026_08_12_190000_harden_performance_and_security.php'));
        foreach(['av_products_status_recent_idx','av_products_price_idx','av_products_rating_idx','av_products_popular_idx','av_orders_user_status_idx'] as $index)$this->assertStringContainsString($index,$migration);
        $this->assertFileExists(base_path('scripts/audit-performance-security.php'));
    }
}
