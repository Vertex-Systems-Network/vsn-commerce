<?php

use App\Http\Controllers\Api\V1\AddressController;
use App\Http\Controllers\Api\V1\AdminGameController;
use App\Http\Controllers\Api\V1\AdminEngagementController;
use App\Http\Controllers\Api\V1\GameController;
use App\Http\Controllers\Api\V1\GiftController;
use App\Http\Controllers\Api\V1\AffiliateController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentMethodController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\SocialAuthController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Controllers\Api\V1\ReturnController;
use App\Http\Controllers\Api\V1\AdminReturnController;
use App\Http\Controllers\Api\V1\AdminOrderController;
use App\Http\Controllers\Api\V1\AdminSettingsController;
use App\Http\Controllers\Api\V1\AdminNotificationController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\AdminReviewController;
use App\Http\Controllers\Api\V1\ReviewEngagementController;
use App\Http\Controllers\Api\V1\SellerReviewController;
use App\Http\Controllers\Api\V1\MediaLibraryController;
use App\Http\Controllers\Api\V1\PublicVendorController;
use App\Http\Controllers\Api\V1\SellerFinanceController;
use App\Http\Controllers\Api\V1\VendorPayoutMethodController;
use App\Http\Controllers\Api\V1\SellerOperationsController;
use App\Http\Controllers\Api\V1\AdminFinanceController;
use App\Http\Controllers\Api\V1\ShipmentController;
use App\Http\Controllers\Api\V1\SellerShippingController;
use App\Http\Controllers\Api\V1\ShippingWebhookController;
use App\Http\Controllers\Api\V1\AdminShippingController;
use App\Http\Controllers\Api\V1\AdminPaymentController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\ActivityController;
use App\Http\Controllers\Api\V1\KycController;
use App\Http\Controllers\Api\V1\PhoneVerificationController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\SecurityController;
use App\Http\Controllers\Api\V1\AdminComplianceController;
use App\Http\Controllers\Api\V1\AdminCatalogController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ProductAlertController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SellerCatalogController;
use App\Http\Controllers\Api\V1\WishlistController;
use App\Http\Controllers\Api\V1\PersonalizationController;
use App\Http\Controllers\Api\V1\ProductMediaController;
use App\Http\Controllers\Api\V1\SellerAnalyticsController;
use App\Http\Controllers\Api\V1\PromotionController;
use App\Http\Controllers\Api\V1\SellerPromotionController;
use App\Http\Controllers\Api\V1\AdminPromotionController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\SellerTaxController;
use App\Http\Controllers\Api\V1\AdminTaxController;
use App\Http\Controllers\Api\V1\AdminRiskController;
use App\Http\Controllers\Api\V1\AdminAnalyticsController;
use App\Http\Controllers\Api\V1\AdminOperationsController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\KycWebhookController;
use App\Http\Controllers\Api\V1\AdminProviderController;
use App\Http\Controllers\Api\V1\AdminAcceptanceController;
use App\Http\Controllers\Api\V1\AdminGoLiveController;
use App\Http\Controllers\Api\V1\AdminUserController;
use App\Http\Controllers\Api\V1\AdminVendorController;
use App\Http\Controllers\Api\Mobile\V1\MobileAppController;
use App\Http\Controllers\Api\Mobile\V1\MobileAuthController;
use App\Http\Controllers\Api\Mobile\V1\MobileDeviceController;
use App\Http\Controllers\Api\Mobile\V1\MobileOAuthController;
use App\Http\Controllers\Api\V1\DemoController;
use App\Http\Controllers\Api\V1\AdminRbacController;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile/v1')->middleware('throttle:api')->group(/** Inline callback for this operation. */ function (): void {
    Route::get('/config', [MobileAppController::class, 'config'])->middleware('throttle:60,1');

    Route::prefix('auth')->middleware('throttle:mobile-auth')->group(/** Inline callback for this operation. */ function (): void {
        Route::post('/register', [MobileAuthController::class, 'register']);
        Route::post('/login', [MobileAuthController::class, 'login']);
        Route::post('/otp/send', [AuthController::class, 'sendOtp'])->middleware('throttle:5,10');
        Route::post('/otp/verify', [MobileAuthController::class, 'verifyOtp']);
        Route::post('/refresh', [MobileAuthController::class, 'refresh'])->middleware('throttle:mobile-refresh');
        Route::post('/password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
        Route::post('/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');
        Route::get('/oauth/providers', [MobileOAuthController::class, 'providers']);
        Route::post('/oauth/{provider}/start', [MobileOAuthController::class, 'start'])->where('provider', 'google|facebook');
        Route::get('/oauth/{provider}/callback', [MobileOAuthController::class, 'callback'])->where('provider', 'google|facebook')->withoutMiddleware('throttle:mobile-auth');
        Route::post('/oauth/exchange', [MobileOAuthController::class, 'exchange']);
    });

    Route::middleware(['auth:sanctum', 'mobile.access'])->group(/** Inline callback for this operation. */ function (): void {
        Route::get('/bootstrap', [MobileAppController::class, 'bootstrap']);
        Route::get('/auth/me', [MobileAuthController::class, 'me']);
        Route::post('/auth/logout', [MobileAuthController::class, 'logout']);
        Route::post('/auth/logout-all', [MobileAuthController::class, 'logoutAll'])->middleware('throttle:10,1');
        Route::get('/sessions', [MobileDeviceController::class, 'index']);
        Route::delete('/sessions/{session}', [MobileDeviceController::class, 'revoke'])->middleware('throttle:20,1');
        Route::put('/device/push-token', [MobileDeviceController::class, 'updatePushToken'])->middleware('throttle:30,1');
        Route::delete('/device/push-token', [MobileDeviceController::class, 'removePushToken'])->middleware('throttle:30,1');
    });
});

Route::prefix('v1')->middleware('throttle:api')->group(/** Inline callback for this operation. */ function (): void {
    Route::get('/health', [HealthController::class, 'live']);
    Route::get('/health/ready', [HealthController::class, 'ready'])->middleware('throttle:30,1');
    Route::get('/demo/accounts', [DemoController::class, 'accounts'])->middleware('throttle:30,1');

    Route::prefix('auth')->middleware('throttle:auth-api')->group(/** Inline callback for this operation. */ function (): void {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
        Route::post('/password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
        Route::post('/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');
        Route::post('/otp/send', [AuthController::class, 'sendOtp'])->middleware('throttle:5,10');
        Route::post('/otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:10,10');
        Route::get('/providers', [SocialAuthController::class, 'providers']);
        Route::get('/oauth/{provider}/start', [SocialAuthController::class, 'start'])->where('provider', 'google|facebook|apple|linkedin');
        Route::match(['get', 'post'], '/oauth/{provider}/callback', [SocialAuthController::class, 'callback'])->where('provider', 'google|facebook|apple|linkedin');
    });

    Route::get('/vendors', [PublicVendorController::class, 'index'])->middleware('throttle:catalog-read');
    Route::get('/vendors/{slug}', [PublicVendorController::class, 'show'])->middleware('throttle:catalog-read');
    Route::get('/products', [ProductController::class, 'index'])->middleware('throttle:catalog-read');
    Route::get('/products/{product}', [ProductController::class, 'show'])->middleware('throttle:catalog-read');
    Route::get('/categories', [CategoryController::class, 'index'])->middleware('throttle:catalog-read');
    Route::get('/search/suggestions', [SearchController::class, 'suggestions'])->middleware('throttle:catalog-read');
    Route::get('/search/trending', [SearchController::class, 'trending'])->middleware('throttle:catalog-read');
    Route::get('/products/{product}/reviews', [ReviewController::class, 'product'])->middleware('throttle:catalog-read');
    Route::get('/recommendations', [PersonalizationController::class, 'recommendations'])->middleware('throttle:catalog-read');
    Route::get('/deals', [PromotionController::class, 'deals'])->middleware('throttle:catalog-read');
    Route::post('/products/{product}/views', [PersonalizationController::class, 'view'])->middleware('throttle:120,1');

    Route::get('/games', [GameController::class, 'index']);
    Route::get('/games/{game}', [GameController::class, 'show']);

    // Provider callbacks are public but must pass provider-specific signature verification.
    Route::post('/payments/webhooks/{provider}', [PaymentWebhookController::class, 'handle'])
        ->where('provider', 'sandbox|stripe')
        ->middleware('throttle:provider-webhook');

    Route::post('/shipping/webhooks/{provider}', [ShippingWebhookController::class, 'handle'])->where('provider', 'sandbox|courier_http')->middleware('throttle:provider-webhook');
    Route::post('/kyc/webhooks/{provider}', [KycWebhookController::class, 'handle'])->where('provider', 'kyc_http')->middleware('throttle:provider-webhook');

    // Cart is available to guests and authenticated users. Guests are identified by X-Cart-Token.
    Route::get('/cart', [CartController::class, 'show']);
    Route::post('/cart/items', [CartController::class, 'storeItem'])->middleware('throttle:commerce-write');
    Route::patch('/cart/items/{item}', [CartController::class, 'updateItem'])->middleware('throttle:commerce-write');
    Route::delete('/cart/items/{item}', [CartController::class, 'destroyItem'])->middleware('throttle:commerce-write');
    Route::delete('/cart', [CartController::class, 'clear'])->middleware('throttle:commerce-write');

    Route::middleware(['auth:sanctum', 'area.role'])->group(/** Inline callback for this operation. */ function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/cart/merge', [CartController::class, 'merge'])->middleware('throttle:20,1');

        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);

        Route::post('/profile/email/send-code', [EmailVerificationController::class, 'send'])->middleware('throttle:5,10');
        Route::post('/profile/email/verify', [EmailVerificationController::class, 'verify'])->middleware('throttle:10,10');
        Route::post('/profile/phone/send-code', [PhoneVerificationController::class, 'send'])->middleware('throttle:5,10');
        Route::post('/profile/phone/verify', [PhoneVerificationController::class, 'verify'])->middleware('throttle:10,10');
        Route::get('/kyc', [KycController::class, 'index']);
        Route::post('/kyc', [KycController::class, 'store'])->middleware(['throttle:upload','throttle:sensitive']);
        Route::post('/kyc/{verification}/retry', [KycController::class, 'retry'])->middleware('throttle:5,10');
        Route::get('/kyc/{verification}/documents/{kind}', [KycController::class, 'document']);
        Route::get('/security', [SecurityController::class, 'index']);
        Route::post('/security/step-up', [SecurityController::class, 'stepUp'])->middleware('throttle:sensitive');
        Route::put('/security/password', [SecurityController::class, 'changePassword'])->middleware('throttle:sensitive');
        Route::get('/payment-methods', [PaymentMethodController::class, 'index']);
        Route::post('/payment-methods/setup', [PaymentMethodController::class, 'setup'])->middleware('throttle:10,5');
        Route::post('/payment-methods/sandbox/setup', [PaymentMethodController::class, 'sandboxSetup'])->middleware('throttle:10,5');
        Route::post('/payment-methods', [PaymentMethodController::class, 'store'])->middleware('throttle:10,5');
        Route::post('/payment-methods/{paymentMethod}/default', [PaymentMethodController::class, 'makeDefault'])->middleware('throttle:10,5');
        Route::delete('/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'destroy'])->middleware('throttle:10,5');
        Route::post('/security/devices/{device}/trust', [SecurityController::class, 'trust'])->middleware('throttle:10,1');
        Route::post('/security/devices/{device}/revoke', [SecurityController::class, 'revoke'])->middleware('throttle:20,1');
        Route::post('/security/sessions/revoke-others', [SecurityController::class, 'revokeOthers'])->middleware('throttle:5,10');

        Route::get('/activity', [ActivityController::class, 'show']);
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'read']);
        Route::get('/notification-preferences', [NotificationController::class, 'preferences']);
        Route::put('/notification-preferences', [NotificationController::class, 'updatePreferences']);

        Route::get('/messages/conversations', [MessageController::class, 'index']);
        Route::post('/messages/conversations', [MessageController::class, 'store'])->middleware('throttle:20,1');
        Route::get('/messages/conversations/{conversation}', [MessageController::class, 'show']);
        Route::post('/messages/conversations/{conversation}/messages', [MessageController::class, 'send'])->middleware('throttle:60,1');
        Route::post('/messages/conversations/{conversation}/read', [MessageController::class, 'read'])->middleware('throttle:120,1');
        Route::get('/messages/attachments/{attachment}', [MessageController::class, 'attachment']);


        Route::get('/search/recent', [SearchController::class, 'recent']);
        Route::delete('/search/recent', [SearchController::class, 'clearRecent'])->middleware('throttle:10,1');

        Route::get('/wishlist', [WishlistController::class, 'index']);
        Route::get('/wishlist/products/{product}', [WishlistController::class, 'status']);
        Route::post('/wishlist/products/{product}', [WishlistController::class, 'store'])->middleware('throttle:30,1');
        Route::delete('/wishlist/{wishlistItem}', [WishlistController::class, 'destroy'])->middleware('throttle:30,1');
        Route::get('/recently-viewed', [PersonalizationController::class, 'recent']);
        Route::delete('/recently-viewed', [PersonalizationController::class, 'clearRecent'])->middleware('throttle:10,1');
        Route::get('/buy-again', [PersonalizationController::class, 'buyAgain']);

        Route::get('/product-alerts', [ProductAlertController::class, 'index']);
        Route::post('/products/{product}/alerts', [ProductAlertController::class, 'store'])->middleware('throttle:30,1');
        Route::delete('/product-alerts/{productAlert}', [ProductAlertController::class, 'destroy'])->middleware('throttle:30,1');

        Route::get('/vendor/reviews', [SellerReviewController::class, 'index']);
        Route::post('/vendor/reviews/{review}/reply', [SellerReviewController::class, 'reply'])->middleware('throttle:30,1');

        Route::get('/vendor/overview', [SellerOperationsController::class, 'overview']);
        Route::get('/vendor/orders', [SellerOperationsController::class, 'orders']);
        Route::get('/vendor/orders/{vendorOrder}', [SellerOperationsController::class, 'order']);
        Route::get('/vendor/returns', [SellerOperationsController::class, 'returns']);
        Route::get('/vendor/returns/{returnRequest}', [SellerOperationsController::class, 'returnShow']);
        Route::put('/vendor/returns/{returnRequest}/feedback', [SellerOperationsController::class, 'returnFeedback'])->middleware('throttle:20,1');
        Route::get('/vendor/settings', [SellerOperationsController::class, 'settings']);
        Route::get('/vendor/media-library', [MediaLibraryController::class, 'index']);
        Route::post('/vendor/media-library', [MediaLibraryController::class, 'store'])->middleware('throttle:upload');
        Route::delete('/vendor/media-library/{asset}', [MediaLibraryController::class, 'destroy'])->middleware('throttle:30,1');
        Route::put('/vendor/settings', [SellerOperationsController::class, 'updateSettings'])->middleware('throttle:20,1');
        Route::get('/vendor/catalog', [SellerCatalogController::class, 'index']);
        Route::post('/vendor/products', [SellerCatalogController::class, 'store'])->middleware('throttle:20,1');
        Route::get('/vendor/products/{product}', [SellerCatalogController::class, 'show']);
        Route::put('/vendor/products/{product}', [SellerCatalogController::class, 'update'])->middleware('throttle:30,1');
        Route::post('/vendor/products/{product}/submit', [SellerCatalogController::class, 'submit'])->middleware('throttle:20,1');
        Route::put('/vendor/variants/{variant}/stock', [SellerCatalogController::class, 'stock'])->middleware('throttle:60,1');
        Route::post('/vendor/products/{product}/media', [ProductMediaController::class, 'sellerUpload'])->middleware('throttle:upload');
        Route::put('/vendor/products/{product}/media/{media}', [ProductMediaController::class, 'sellerUpdate'])->middleware('throttle:30,1');
        Route::delete('/vendor/products/{product}/media/{media}', [ProductMediaController::class, 'sellerDelete'])->middleware('throttle:30,1');
        Route::post('/vendor/products/{product}/media-library/{asset}', [MediaLibraryController::class, 'attach'])->middleware('throttle:30,1');
        Route::get('/vendor/analytics', [SellerAnalyticsController::class, 'show']);
        Route::get('/vendor/promotions', [SellerPromotionController::class, 'index']);
        Route::post('/vendor/promotions', [SellerPromotionController::class, 'store'])->middleware('throttle:20,1');
        Route::put('/vendor/promotions/{promotion}', [SellerPromotionController::class, 'update'])->middleware('throttle:30,1');
        Route::post('/vendor/promotions/{promotion}/status', [SellerPromotionController::class, 'status'])->middleware('throttle:30,1');

        Route::get('/admin/users', [AdminUserController::class, 'index']);
        Route::get('/admin/rbac', [AdminRbacController::class, 'index']);
        Route::post('/admin/users', [AdminUserController::class, 'store'])->middleware('throttle:20,1');
        Route::put('/admin/users/{user}', [AdminUserController::class, 'update'])->middleware('throttle:30,1');
        Route::get('/admin/vendors', [AdminVendorController::class, 'index']);
        Route::post('/admin/vendors', [AdminVendorController::class, 'store'])->middleware('throttle:20,1');
        Route::put('/admin/vendors/{vendor}', [AdminVendorController::class, 'update'])->middleware('throttle:30,1');

        Route::get('/admin/catalog', [AdminCatalogController::class, 'index']);
        Route::post('/admin/products', [AdminCatalogController::class, 'store'])->middleware('throttle:20,1');
        Route::get('/admin/products/{product}', [AdminCatalogController::class, 'show']);
        Route::put('/admin/products/{product}', [AdminCatalogController::class, 'update'])->middleware('throttle:30,1');
        Route::post('/admin/products/{product}/review', [AdminCatalogController::class, 'review'])->middleware('throttle:30,1');
        Route::put('/admin/variants/{variant}/stock', [AdminCatalogController::class, 'stock'])->middleware('throttle:60,1');
        Route::post('/admin/products/{product}/media', [ProductMediaController::class, 'adminUpload'])->middleware('throttle:upload');
        Route::put('/admin/products/{product}/media/{media}', [ProductMediaController::class, 'adminUpdate'])->middleware('throttle:30,1');
        Route::delete('/admin/products/{product}/media/{media}', [ProductMediaController::class, 'adminDelete'])->middleware('throttle:30,1');
        Route::get('/admin/media-library', [MediaLibraryController::class, 'index']);
        Route::post('/admin/media-library', [MediaLibraryController::class, 'store'])->middleware('throttle:upload');
        Route::delete('/admin/media-library/{asset}', [MediaLibraryController::class, 'destroy'])->middleware('throttle:30,1');
        Route::post('/admin/products/{product}/media-library/{asset}', [MediaLibraryController::class, 'attach'])->middleware('throttle:30,1');
        Route::get('/admin/categories', [AdminCatalogController::class, 'categories']);
        Route::post('/admin/categories', [AdminCatalogController::class, 'storeCategory'])->middleware('throttle:30,1');
        Route::put('/admin/categories/{category}', [AdminCatalogController::class, 'updateCategory'])->middleware('throttle:30,1');
        Route::get('/admin/promotions', [AdminPromotionController::class, 'index']);
        Route::post('/admin/promotions', [AdminPromotionController::class, 'store'])->middleware('throttle:20,1');
        Route::put('/admin/promotions/{promotion}', [AdminPromotionController::class, 'update'])->middleware('throttle:30,1');
        Route::post('/admin/promotions/{promotion}/status', [AdminPromotionController::class, 'status'])->middleware('throttle:30,1');

        Route::get('/addresses', [AddressController::class, 'index']);
        Route::post('/addresses', [AddressController::class, 'store']);
        Route::put('/addresses/{address}', [AddressController::class, 'update']);
        Route::delete('/addresses/{address}', [AddressController::class, 'destroy']);

        Route::post('/inventory/reserve', [InventoryController::class, 'reserve']);
        Route::post('/inventory/reservations/{reservation}/release', [InventoryController::class, 'release']);

        Route::get('/checkout/options', [CheckoutController::class, 'options']);
        Route::get('/checkout/current', [CheckoutController::class, 'current']);
        Route::post('/checkout/sessions', [CheckoutController::class, 'store'])->middleware('throttle:commerce-write');
        Route::get('/checkout/sessions/{checkoutSession}', [CheckoutController::class, 'show']);
        Route::delete('/checkout/sessions/{checkoutSession}', [CheckoutController::class, 'destroy'])->middleware('throttle:commerce-write');
        Route::post('/checkout/sessions/{checkoutSession}/order', [CheckoutController::class, 'placeOrder'])->middleware('throttle:sensitive');
        Route::post('/checkout/sessions/{checkoutSession}/payments', [PaymentController::class, 'store'])->middleware('throttle:sensitive');
        Route::get('/payments/{paymentIntent}', [PaymentController::class, 'show']);
        Route::post('/payments/{paymentIntent}/refresh-provider', [PaymentController::class, 'refreshProvider'])->middleware('throttle:20,1');
        Route::post('/payments/{paymentIntent}/retry-initialization', [PaymentController::class, 'retryInitialization'])->middleware('throttle:10,1');
        Route::post('/payments/{paymentIntent}/sandbox/complete', [PaymentController::class, 'sandboxComplete'])->middleware('throttle:10,1');

        Route::get('/games/me/entries', [GameController::class, 'myEntries']);
        Route::post('/games/{game}/entries', [GameController::class, 'join'])->middleware('throttle:commerce-write');

        Route::post('/admin/games', [AdminGameController::class, 'store'])->middleware('throttle:20,1');
        Route::post('/admin/games/{game}/close', [AdminGameController::class, 'close'])->middleware('throttle:20,1');
        Route::post('/admin/games/{game}/draw', [AdminGameController::class, 'draw'])->middleware('throttle:10,1');
        Route::post('/admin/games/{game}/cancel', [AdminGameController::class, 'cancel'])->middleware('throttle:10,1');
        Route::post('/admin/games/{game}/refunds/process', [AdminGameController::class, 'processRefunds'])->middleware('throttle:10,1');
        Route::post('/admin/games/{game}/fulfill', [AdminGameController::class, 'fulfill'])->middleware('throttle:10,1');

        Route::get('/affiliate', [AffiliateController::class, 'show']);
        Route::post('/affiliate/enroll', [AffiliateController::class, 'enroll'])->middleware('throttle:10,1');
        Route::post('/affiliate/referrer', [AffiliateController::class, 'attachReferrer'])->middleware('throttle:10,1');
        Route::get('/affiliate/commissions', [AffiliateController::class, 'commissions']);

        Route::get('/wallet', [WalletController::class, 'show']);
        Route::get('/wallet/transactions', [WalletController::class, 'transactions']);
        Route::post('/wallet/check-in', [WalletController::class, 'checkin'])->middleware('throttle:10,1');
        Route::post('/wallet/transfers', [WalletController::class, 'transfer'])->middleware('throttle:sensitive');
        Route::post('/wallet/coin-purchases', [WalletController::class, 'purchase'])->middleware('throttle:sensitive');
        Route::get('/wallet/coin-purchases/{coinPurchase}', [WalletController::class, 'purchaseShow']);


        Route::get('/admin/engagement/summary', [AdminEngagementController::class, 'summary']);
        Route::get('/admin/engagement/wallets', [AdminEngagementController::class, 'wallets']);
        Route::post('/admin/engagement/wallets/users/{user}/adjust', [AdminEngagementController::class, 'adjustWallet'])->middleware('throttle:20,1');
        Route::post('/admin/engagement/wallets/expire', [AdminEngagementController::class, 'expireCoins'])->middleware('throttle:5,1');
        Route::get('/admin/engagement/affiliate/accounts', [AdminEngagementController::class, 'affiliateAccounts']);
        Route::post('/admin/engagement/affiliate/accounts/{affiliateAccount}/status', [AdminEngagementController::class, 'affiliateStatus'])->middleware('throttle:20,1');
        Route::get('/admin/engagement/affiliate/commissions', [AdminEngagementController::class, 'affiliateCommissions']);
        Route::post('/admin/engagement/affiliate/process', [AdminEngagementController::class, 'processAffiliate'])->middleware('throttle:5,1');
        Route::get('/admin/engagement/games', [AdminEngagementController::class, 'games']);
        Route::get('/admin/engagement/games/{game}/entries', [AdminEngagementController::class, 'gameEntries']);

        Route::get('/invoices', [InvoiceController::class, 'index']);
        Route::get('/vendor/invoices', [InvoiceController::class, 'sellerIndex']);
        Route::get('/vendor/invoices/{invoice}', [InvoiceController::class, 'sellerShow']);
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
        Route::get('/tax/classes', [SellerTaxController::class, 'classes']);
        Route::get('/vendor/tax-profile', [SellerTaxController::class, 'show']);
        Route::put('/vendor/tax-profile', [SellerTaxController::class, 'update'])->middleware('throttle:10,1');
        Route::get('/admin/tax', [AdminTaxController::class, 'index']);
        Route::post('/admin/tax/jurisdictions', [AdminTaxController::class, 'jurisdiction'])->middleware('throttle:20,1');
        Route::post('/admin/tax/classes', [AdminTaxController::class, 'taxClass'])->middleware('throttle:20,1');
        Route::post('/admin/tax/rates', [AdminTaxController::class, 'rate'])->middleware('throttle:30,1');
        Route::put('/admin/tax/rates/{rate}', [AdminTaxController::class, 'updateRate'])->middleware('throttle:30,1');
        Route::post('/admin/tax/vendor-profiles/{profile}/review', [AdminTaxController::class, 'reviewVendor'])->middleware('throttle:20,1');

        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);

        Route::get('/shipments', [ShipmentController::class, 'index']);
        Route::get('/shipments/{shipment}', [ShipmentController::class, 'show']);

        Route::get('/vendor/shipping', [SellerShippingController::class, 'index']);
        Route::post('/vendor/orders/{vendorOrder}/pack', [SellerShippingController::class, 'pack'])->middleware('throttle:30,1');
        Route::post('/vendor/orders/{vendorOrder}/shipments', [SellerShippingController::class, 'create'])->middleware('throttle:20,1');
        Route::post('/vendor/shipments/{shipment}/ready', [SellerShippingController::class, 'ready'])->middleware('throttle:30,1');
        Route::post('/vendor/shipments/{shipment}/retry-create', [SellerShippingController::class, 'retryCreate'])->middleware('throttle:10,1');
        Route::post('/vendor/shipments/{shipment}/sync', [SellerShippingController::class, 'sync'])->middleware('throttle:30,1');
        Route::post('/vendor/shipments/{shipment}/cancel', [SellerShippingController::class, 'cancel'])->middleware('throttle:20,1');
        Route::post('/vendor/shipments/{shipment}/sandbox-event', [SellerShippingController::class, 'sandboxEvent'])->middleware('throttle:60,1');

        Route::get('/admin/compliance/kyc', [AdminComplianceController::class, 'kyc']);
        Route::post('/admin/compliance/kyc/{verification}/review', [AdminComplianceController::class, 'review'])->middleware('throttle:30,1');
        Route::post('/admin/compliance/kyc/{verification}/retry', [AdminComplianceController::class, 'retryKyc'])->middleware('throttle:20,1');
        Route::post('/admin/compliance/kyc/{verification}/sync', [AdminComplianceController::class, 'syncKyc'])->middleware('throttle:30,1');
        Route::get('/admin/security/events', [AdminComplianceController::class, 'events']);
        Route::get('/admin/audit-logs', [AdminComplianceController::class, 'audits']);

        Route::get('/admin/shipping/quality', [AdminShippingController::class, 'quality']);
        Route::get('/admin/shipping/shipments', [AdminShippingController::class, 'shipments']);
        Route::post('/admin/shipping/shipments/{shipment}/retry-create', [AdminShippingController::class, 'retryCreate'])->middleware('throttle:10,1');
        Route::post('/admin/shipping/shipments/{shipment}/sync', [AdminShippingController::class, 'sync'])->middleware('throttle:30,1');
        Route::post('/admin/shipping/shipments/{shipment}/cancel', [AdminShippingController::class, 'cancel'])->middleware('throttle:20,1');
        Route::get('/admin/payments', [AdminPaymentController::class, 'index']);
        Route::post('/admin/payments/{paymentIntent}/sync', [AdminPaymentController::class, 'sync'])->middleware('throttle:30,1');

        Route::get('/reviews', [ReviewController::class, 'index']);
        Route::post('/reviews/{review}/helpful', [ReviewEngagementController::class, 'helpful'])->middleware('throttle:60,1');
        Route::post('/reviews/{review}/report', [ReviewEngagementController::class, 'report'])->middleware('throttle:10,10');
        Route::post('/reviews', [ReviewController::class, 'store'])->middleware('throttle:10,1');
        Route::get('/admin/reviews', [AdminReviewController::class, 'index']);
        Route::get('/admin/reviews/reports', [AdminReviewController::class, 'reports']);
        Route::post('/admin/reviews/reports/{report}/resolve', [AdminReviewController::class, 'resolveReport'])->middleware('throttle:30,1');
        Route::post('/admin/reviews/{review}/moderate', [AdminReviewController::class, 'moderate'])->middleware('throttle:30,1');

        Route::get('/gifts', [GiftController::class, 'index']);
        Route::post('/gifts/checkouts', [GiftController::class, 'store'])->middleware('throttle:10,1');
        Route::get('/gifts/{gift}', [GiftController::class, 'show']);
        Route::post('/gifts/{gift}/cancel', [GiftController::class, 'cancel'])->middleware('throttle:10,1');


        Route::get('/vendor/finance', [SellerFinanceController::class, 'show']);
        Route::get('/vendor/payout-methods', [VendorPayoutMethodController::class, 'index']);
        Route::post('/vendor/payout-methods', [VendorPayoutMethodController::class, 'store'])->middleware('throttle:10,1');
        Route::post('/vendor/payout-methods/{payoutMethod}/default', [VendorPayoutMethodController::class, 'makeDefault'])->middleware('throttle:10,1');
        Route::delete('/vendor/payout-methods/{payoutMethod}', [VendorPayoutMethodController::class, 'destroy'])->middleware('throttle:10,1');
        Route::get('/vendor/payouts', [SellerFinanceController::class, 'payouts']);
        Route::post('/vendor/payouts', [SellerFinanceController::class, 'requestPayout'])->middleware('throttle:10,1');

        Route::get('/admin/finance', [AdminFinanceController::class, 'dashboard']);
        Route::get('/admin/finance/payouts', [AdminFinanceController::class, 'payouts']);
        Route::post('/admin/finance/payouts/{payout}/review', [AdminFinanceController::class, 'reviewPayout'])->middleware('throttle:20,1');
        Route::post('/admin/finance/payouts/{payout}/paid', [AdminFinanceController::class, 'markPaid'])->middleware('throttle:20,1');
        Route::post('/admin/finance/payouts/{payout}/fail', [AdminFinanceController::class, 'failPayout'])->middleware('throttle:20,1');
        Route::post('/admin/finance/payouts/{payout}/retry', [AdminFinanceController::class, 'retryPayout'])->middleware('throttle:20,1');
        Route::get('/admin/finance/payout-methods', [AdminFinanceController::class, 'payoutMethods']);
        Route::post('/admin/finance/payout-methods/{payoutMethod}/verify', [AdminFinanceController::class, 'verifyPayoutMethod'])->middleware('throttle:20,1');
        Route::post('/admin/finance/payouts/{payout}/cancel', [AdminFinanceController::class, 'cancelPayout'])->middleware('throttle:20,1');
        Route::get('/admin/finance/payout-batches', [AdminFinanceController::class, 'batches']);
        Route::post('/admin/finance/payout-batches', [AdminFinanceController::class, 'createBatch'])->middleware('throttle:10,1');
        Route::post('/admin/finance/orders/{order}/confirm-cod', [AdminFinanceController::class, 'confirmCod'])->middleware('throttle:20,1');
        Route::post('/admin/finance/orders/{order}/mark-delivered', [AdminFinanceController::class, 'markDelivered'])->middleware('throttle:20,1');
        Route::post('/admin/finance/reconcile', [AdminFinanceController::class, 'reconcile'])->middleware('throttle:5,1');

        Route::get('/admin/risk', [AdminRiskController::class, 'index']);
        Route::post('/admin/risk/users/{user}/evaluate', [AdminRiskController::class, 'evaluateUser'])->middleware('throttle:30,1');
        Route::post('/admin/risk/vendors/{vendor}/evaluate', [AdminRiskController::class, 'evaluateVendor'])->middleware('throttle:30,1');
        Route::post('/admin/risk/holds', [AdminRiskController::class, 'hold'])->middleware('throttle:30,1');
        Route::post('/admin/risk/holds/{hold}/release', [AdminRiskController::class, 'release'])->middleware('throttle:30,1');
        Route::post('/admin/risk/cases/{case}/status', [AdminRiskController::class, 'caseUpdate'])->middleware('throttle:30,1');

        Route::get('/returns', [ReturnController::class, 'index']);
        Route::post('/returns', [ReturnController::class, 'store'])->middleware('throttle:10,1');
        Route::get('/returns/{returnRequest}', [ReturnController::class, 'show']);
        Route::post('/returns/{returnRequest}/ship', [ReturnController::class, 'ship'])->middleware('throttle:10,1');
        Route::post('/returns/{returnRequest}/cancel', [ReturnController::class, 'cancel'])->middleware('throttle:10,1');

        Route::get('/admin/orders', [AdminOrderController::class, 'index']);
        Route::get('/admin/orders/{order}', [AdminOrderController::class, 'show']);
        Route::put('/admin/orders/{order}/status', [AdminOrderController::class, 'status'])->middleware('throttle:30,1');
        Route::get('/admin/returns', [AdminReturnController::class, 'index']);
        Route::get('/admin/returns/{returnRequest}', [AdminReturnController::class, 'show']);
        Route::post('/admin/returns/{returnRequest}/review', [AdminReturnController::class, 'review'])->middleware('throttle:20,1');
        Route::post('/admin/returns/{returnRequest}/receive', [AdminReturnController::class, 'receive'])->middleware('throttle:20,1');
        Route::post('/admin/refunds/{refund}/confirm-manual', [AdminReturnController::class, 'confirmManual'])->middleware('throttle:20,1');
        Route::post('/admin/refunds/{refund}/retry', [AdminReturnController::class, 'retryRefund'])->middleware('throttle:10,1');
        Route::post('/admin/disputes/{dispute}/resolve', [AdminReturnController::class, 'resolveDispute'])->middleware('throttle:20,1');
        Route::get('/admin/analytics', [AdminAnalyticsController::class, 'dashboard']);
        Route::get('/admin/analytics/exports', [AdminAnalyticsController::class, 'exports']);
        Route::post('/admin/analytics/exports', [AdminAnalyticsController::class, 'createExport'])->middleware('throttle:20,1');
        Route::get('/admin/analytics/exports/{export}/download', [AdminAnalyticsController::class, 'download']);
        Route::get('/admin/analytics/schedules', [AdminAnalyticsController::class, 'schedules']);
        Route::post('/admin/analytics/schedules', [AdminAnalyticsController::class, 'createSchedule'])->middleware('throttle:20,1');
        Route::put('/admin/analytics/schedules/{schedule}', [AdminAnalyticsController::class, 'updateSchedule'])->middleware('throttle:30,1');
        Route::delete('/admin/analytics/schedules/{schedule}', [AdminAnalyticsController::class, 'deleteSchedule'])->middleware('throttle:30,1');
        Route::get('/admin/notifications', [AdminNotificationController::class, 'index']);
        Route::post('/admin/notifications/broadcast', [AdminNotificationController::class, 'broadcast'])->middleware('throttle:5,1');
        Route::get('/admin/notifications/campaigns', [AdminNotificationController::class, 'campaignSummary']);
        Route::get('/admin/notifications/deliveries', [AdminNotificationController::class, 'deliveries']);
        Route::post('/admin/notifications/deliveries/{delivery}/retry', [AdminNotificationController::class, 'retryDelivery'])->middleware('throttle:30,1');
        Route::get('/admin/settings', [AdminSettingsController::class, 'index']);
        Route::put('/admin/settings', [AdminSettingsController::class, 'update'])->middleware('throttle:20,1');
        Route::get('/admin/system/operations', [AdminOperationsController::class, 'index']);
        Route::get('/admin/system/operations/configuration', [AdminOperationsController::class, 'configuration']);
        Route::get('/admin/system/operations/deployments', [AdminOperationsController::class, 'deployments']);
        Route::get('/admin/system/operations/incidents', [AdminOperationsController::class, 'incidents']);
        Route::post('/admin/system/operations/incidents/{incident}/notes', [AdminOperationsController::class, 'incidentNote'])->middleware('throttle:20,1');
        Route::put('/admin/system/operations/incidents/{incident}/status', [AdminOperationsController::class, 'incidentStatus'])->middleware('throttle:20,1');
        Route::post('/admin/system/operations/incidents/{incident}/resolve', [AdminOperationsController::class, 'incidentResolve'])->middleware('throttle:10,1');
        Route::get('/admin/system/backups', [AdminOperationsController::class, 'backups']);
        Route::get('/admin/system/launch-gate', [AdminOperationsController::class, 'launchGate']);
        Route::post('/admin/system/launch-gate', [AdminOperationsController::class, 'runLaunchGate'])->middleware('throttle:10,1');
        Route::get('/admin/system/providers', [AdminProviderController::class, 'index']);
        Route::post('/admin/system/providers/probe', [AdminProviderController::class, 'probe'])->middleware('throttle:10,1');
        Route::post('/admin/system/providers/reconcile', [AdminProviderController::class, 'reconcile'])->middleware('throttle:10,1');

        Route::get('/admin/system/acceptance', [AdminAcceptanceController::class, 'index']);
        Route::post('/admin/system/acceptance', [AdminAcceptanceController::class, 'run'])->middleware('throttle:5,1');
        Route::post('/admin/system/acceptance/{acceptanceRun}/signoff', [AdminAcceptanceController::class, 'signoff'])->middleware('throttle:10,1');
        Route::post('/admin/system/acceptance/{acceptanceRun}/seal', [AdminAcceptanceController::class, 'seal'])->middleware('throttle:5,1');
        Route::get('/admin/system/go-live', [AdminGoLiveController::class, 'index']);
        Route::post('/admin/system/go-live', [AdminGoLiveController::class, 'open'])->middleware('throttle:5,1');
        Route::post('/admin/system/go-live/{window}/observe', [AdminGoLiveController::class, 'observe'])->middleware('throttle:12,1');
        Route::post('/admin/system/go-live/{window}/signoff', [AdminGoLiveController::class, 'signoff'])->middleware('throttle:10,1');
        Route::post('/admin/system/go-live/{window}/rollback', [AdminGoLiveController::class, 'rollback'])->middleware('throttle:5,1');
        Route::post('/admin/system/dr-drills', [AdminAcceptanceController::class, 'recordDrill'])->middleware('throttle:5,1');
        Route::post('/admin/system/incidents', [AdminAcceptanceController::class, 'createIncident'])->middleware('throttle:10,1');
        Route::post('/admin/system/incidents/{incident}/resolve', [AdminAcceptanceController::class, 'resolveIncident'])->middleware('throttle:10,1');

    });
});
