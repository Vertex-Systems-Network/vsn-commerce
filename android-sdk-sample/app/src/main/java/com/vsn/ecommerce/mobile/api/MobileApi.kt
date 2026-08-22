package com.vsn.ecommerce.mobile.api

import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.PATCH
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Path
import retrofit2.http.Query

/** Documents MobileApi interface behavior for the Android integration sample. */
interface MobileApi {
    @GET("api/mobile/v1/config")
    /** Documents config fun behavior for the Android integration sample. */
    suspend fun config(): Map<String, Any?>

    @POST("api/mobile/v1/auth/login")
    /** Documents login fun behavior for the Android integration sample. */
    suspend fun login(@Body request: LoginRequest): LoginEnvelope

    @POST("api/mobile/v1/auth/refresh")
    /** Documents refresh fun behavior for the Android integration sample. */
    suspend fun refresh(@Body request: RefreshRequest): RefreshEnvelope

    @GET("api/mobile/v1/bootstrap")
    /** Documents bootstrap fun behavior for the Android integration sample. */
    suspend fun bootstrap(): Map<String, Any?>

    @GET("api/mobile/v1/auth/me")
    /** Documents me fun behavior for the Android integration sample. */
    suspend fun me(): Map<String, Any?>

    @POST("api/mobile/v1/auth/logout")
    /** Documents logout fun behavior for the Android integration sample. */
    suspend fun logout(): Map<String, Any?>

    @POST("api/mobile/v1/auth/logout-all")
    /** Documents logoutAll fun behavior for the Android integration sample. */
    suspend fun logoutAll(): Map<String, Any?>

    @GET("api/mobile/v1/sessions")
    /** Documents sessions fun behavior for the Android integration sample. */
    suspend fun sessions(): Map<String, Any?>

    @DELETE("api/mobile/v1/sessions/{sessionId}")
    /** Documents revokeSession fun behavior for the Android integration sample. */
    suspend fun revokeSession(@Path("sessionId") sessionId: String): Map<String, Any?>

    @PUT("api/mobile/v1/device/push-token")
    /** Documents updatePushToken fun behavior for the Android integration sample. */
    suspend fun updatePushToken(@Body body: PushTokenRequest): Map<String, Any?>

    @DELETE("api/mobile/v1/device/push-token")
    /** Documents removePushToken fun behavior for the Android integration sample. */
    suspend fun removePushToken(): Map<String, Any?>
}

/**
 * The Android bearer token authenticates the existing same-origin customer API too.
 * Keep this interface on the authenticated OkHttp client containing AuthInterceptor + TokenAuthenticator.
 */
interface VSNCustomerApi {
    @GET("api/v1/products")
    /** Documents products fun behavior for the Android integration sample. */
    suspend fun products(@Query("q") query: String? = null, @Query("page") page: Int = 1): Map<String, Any?>

    @GET("api/v1/products/{product}")
    /** Documents product fun behavior for the Android integration sample. */
    suspend fun product(@Path("product") product: String): Map<String, Any?>

    @GET("api/v1/search/suggestions")
    /** Documents suggestions fun behavior for the Android integration sample. */
    suspend fun suggestions(@Query("q") query: String): Map<String, Any?>

    @GET("api/v1/cart")
    /** Documents cart fun behavior for the Android integration sample. */
    suspend fun cart(): Map<String, Any?>

    @POST("api/v1/cart/items")
    /** Documents addCartItem fun behavior for the Android integration sample. */
    suspend fun addCartItem(@Body request: CartItemRequest): Map<String, Any?>

    @PATCH("api/v1/cart/items/{item}")
    /** Documents updateCartItem fun behavior for the Android integration sample. */
    suspend fun updateCartItem(@Path("item") item: String, @Body request: CartQuantityRequest): Map<String, Any?>

    @DELETE("api/v1/cart/items/{item}")
    /** Documents deleteCartItem fun behavior for the Android integration sample. */
    suspend fun deleteCartItem(@Path("item") item: String): Map<String, Any?>

    @GET("api/v1/checkout/options")
    /** Documents checkoutOptions fun behavior for the Android integration sample. */
    suspend fun checkoutOptions(): Map<String, Any?>

    @GET("api/v1/checkout/current")
    /** Documents currentCheckout fun behavior for the Android integration sample. */
    suspend fun currentCheckout(): Map<String, Any?>

    @POST("api/v1/checkout/sessions")
    /** Documents createCheckout fun behavior for the Android integration sample. */
    suspend fun createCheckout(@Body body: Map<String, Any?>): Map<String, Any?>

    @GET("api/v1/checkout/sessions/{checkout}")
    /** Documents checkout fun behavior for the Android integration sample. */
    suspend fun checkout(@Path("checkout") checkout: String): Map<String, Any?>

    @POST("api/v1/checkout/sessions/{checkout}/payments")
    /** Documents createPayment fun behavior for the Android integration sample. */
    suspend fun createPayment(@Path("checkout") checkout: String, @Body body: Map<String, Any?>): Map<String, Any?>

    @POST("api/v1/checkout/sessions/{checkout}/order")
    /** Documents placeOrder fun behavior for the Android integration sample. */
    suspend fun placeOrder(@Path("checkout") checkout: String, @Body body: Map<String, Any?> = emptyMap()): Map<String, Any?>

    @GET("api/v1/payments/{payment}")
    /** Documents payment fun behavior for the Android integration sample. */
    suspend fun payment(@Path("payment") payment: String): Map<String, Any?>

    @POST("api/v1/payments/{payment}/refresh-provider")
    /** Documents refreshPayment fun behavior for the Android integration sample. */
    suspend fun refreshPayment(@Path("payment") payment: String): Map<String, Any?>

    @GET("api/v1/orders")
    /** Documents orders fun behavior for the Android integration sample. */
    suspend fun orders(@Query("page") page: Int = 1): Map<String, Any?>

    @GET("api/v1/orders/{order}")
    /** Documents order fun behavior for the Android integration sample. */
    suspend fun order(@Path("order") order: String): Map<String, Any?>

    @GET("api/v1/returns")
    /** Documents returns fun behavior for the Android integration sample. */
    suspend fun returns(): Map<String, Any?>

    @POST("api/v1/returns")
    /** Documents createReturn fun behavior for the Android integration sample. */
    suspend fun createReturn(@Body body: Map<String, Any?>): Map<String, Any?>

    @POST("api/v1/returns/{returnId}/ship")
    /** Documents shipReturn fun behavior for the Android integration sample. */
    suspend fun shipReturn(@Path("returnId") returnId: String, @Body body: Map<String, Any?>): Map<String, Any?>

    @POST("api/v1/returns/{returnId}/cancel")
    /** Documents cancelReturn fun behavior for the Android integration sample. */
    suspend fun cancelReturn(@Path("returnId") returnId: String): Map<String, Any?>

    @GET("api/v1/wallet")
    /** Documents wallet fun behavior for the Android integration sample. */
    suspend fun wallet(): Map<String, Any?>

    @GET("api/v1/wishlist")
    /** Documents wishlist fun behavior for the Android integration sample. */
    suspend fun wishlist(): Map<String, Any?>

    @GET("api/v1/notifications")
    /** Documents notifications fun behavior for the Android integration sample. */
    suspend fun notifications(): Map<String, Any?>

    @POST("api/v1/notifications/read-all")
    /** Documents readAllNotifications fun behavior for the Android integration sample. */
    suspend fun readAllNotifications(): Map<String, Any?>

    @GET("api/v1/security")
    /** Documents security fun behavior for the Android integration sample. */
    suspend fun security(): Map<String, Any?>
}
