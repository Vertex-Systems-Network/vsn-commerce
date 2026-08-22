<?php
namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use App\Support\RequestPerformanceMetrics;

/** Defines the AppServiceProvider class and its project responsibilities. */
class AppServiceProvider extends ServiceProvider
{
    /** Handles register for the app service provider workflow. */
    public function register(): void
    {
        $this->app->scoped(RequestPerformanceMetrics::class, /** Inline callback for this operation. */ fn () => new RequestPerformanceMetrics());
    }
    /** Handles boot for the app service provider workflow. */
    public function boot(): void
    {
        Model::preventLazyLoading();
        if (app()->isProduction()) {
            Model::handleLazyLoadingViolationUsing(/** Inline callback for this operation. */ function (Model $model, string $relation): void {
                Log::warning('eloquent.lazy_loading_detected', [
                    'model' => $model::class,
                    'relation' => $relation,
                ]);
            });
        }
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());

        ResetPassword::createUrlUsing(/** Inline callback for this operation. */ function (object $user, string $token): string {
            $frontend=rtrim((string)config('vsn.frontend_url'),'/');
            return $frontend.'/reset-password?'.http_build_query(['token'=>$token,'email'=>$user->getEmailForPasswordReset()]);
        });

        RateLimiter::for('api', /** Inline callback for this operation. */ function(Request $request){
            $id=$request->user()?->id ? 'user:'.$request->user()->id : 'ip:'.hash('sha256',(string)$request->ip());
            return Limit::perMinute($request->user()?(int)config('vsn.operations.rate_limits.authenticated_per_minute',600):(int)config('vsn.operations.rate_limits.guest_per_minute',180))->by($id);
        });
        RateLimiter::for('auth-api', /** Inline callback for this operation. */ fn(Request $request)=>Limit::perMinute((int)config('vsn.operations.rate_limits.auth_per_minute',30))->by('auth:'.hash('sha256',(string)$request->ip())));
        RateLimiter::for('mobile-auth', /** Inline callback for this operation. */ fn(Request $request)=>Limit::perMinute((int)config('vsn.mobile.auth_per_minute',20))->by('mobile-auth:'.hash('sha256',(string)$request->ip().'|'.(string)$request->header('X-Device-Id'))));
        RateLimiter::for('mobile-refresh', /** Inline callback for this operation. */ fn(Request $request)=>Limit::perMinute((int)config('vsn.mobile.refresh_per_minute',30))->by('mobile-refresh:'.hash('sha256',(string)$request->ip().'|'.(string)$request->header('X-Device-Id'))));
        RateLimiter::for('catalog-read', /** Inline callback for this operation. */ fn(Request $request)=>Limit::perMinute((int)config('vsn.operations.rate_limits.catalog_read_per_minute',240))->by($this->rateKey($request,'catalog')));
        RateLimiter::for('commerce-write', /** Inline callback for this operation. */ fn(Request $request)=>Limit::perMinute((int)config('vsn.operations.rate_limits.commerce_write_per_minute',90))->by($this->rateKey($request,'commerce')));
        RateLimiter::for('upload', /** Inline callback for this operation. */ fn(Request $request)=>Limit::perMinute((int)config('vsn.operations.rate_limits.upload_per_minute',20))->by($this->rateKey($request,'upload')));
        RateLimiter::for('provider-webhook', /** Inline callback for this operation. */ fn(Request $request)=>Limit::perMinute((int)config('vsn.operations.rate_limits.webhook_per_minute',300))->by('webhook:'.hash('sha256',(string)$request->ip().'|'.(string)$request->route('provider'))));
        RateLimiter::for('sensitive', /** Inline callback for this operation. */ fn(Request $request)=>Limit::perMinute((int)config('vsn.operations.rate_limits.sensitive_per_minute',12))->by($this->rateKey($request,'sensitive')));

        if ((bool) config('vsn.performance.query_telemetry', true)) {
            DB::listen(/** Inline callback for this operation. */ function (QueryExecuted $event): void {
                app(RequestPerformanceMetrics::class)->record($event->sql, (float) $event->time);
            });
        }

        if ((int)config('vsn.operations.slow_query_ms',500)>0) {
            DB::whenQueryingForLongerThan((int)config('vsn.operations.slow_query_ms',500), /** Inline callback for this operation. */ function(Connection $connection,QueryExecuted $event):void{
                Log::warning('database.slow_request',[
                    'connection'=>$connection->getName(),
                    'query_ms'=>round((float)$event->time,2),
                    'total_query_ms'=>round($connection->totalQueryDuration(),2),
                    'query_fingerprint'=>hash('sha256',preg_replace('/\s+/',' ',trim($event->sql))),
                ]);
            });
        }
        Event::listen(QueueBusy::class,/** Inline callback for this operation. */ function(QueueBusy $event):void{Log::warning('queue.busy',['connection'=>$event->connectionName,'queue'=>$event->queue,'size'=>$event->size]);});
    }

    /** Handles rate key for the app service provider workflow. */
    private function rateKey(Request $request, string $scope): string
    {
        $identity = $request->user()?->id ? 'user:'.$request->user()->id : 'ip:'.hash('sha256', (string) $request->ip());
        return $scope.':'.$identity;
    }
}
