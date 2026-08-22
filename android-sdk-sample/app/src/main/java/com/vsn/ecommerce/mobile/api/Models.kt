package com.vsn.ecommerce.mobile.api

/** Documents DeviceContext class behavior for the Android integration sample. */
data class DeviceContext(
    val deviceId: String,
    val deviceName: String,
    val appVersion: String,
    val osVersion: String? = null,
)

/** Documents LoginRequest class behavior for the Android integration sample. */
data class LoginRequest(
    val email: String,
    val password: String,
    val deviceId: String,
    val deviceName: String,
    val appVersion: String,
    val osVersion: String? = null,
)

/** Documents RefreshRequest class behavior for the Android integration sample. */
data class RefreshRequest(
    val refreshToken: String,
    val deviceId: String,
    val deviceName: String,
    val appVersion: String,
    val osVersion: String? = null,
)

/** Documents AuthTokens class behavior for the Android integration sample. */
data class AuthTokens(
    val tokenType: String,
    val accessToken: String,
    val accessExpiresAt: String,
    val refreshToken: String,
    val refreshExpiresAt: String,
    val sessionId: String,
    val refreshGeneration: Int = 0,
)

/** Documents AuthData class behavior for the Android integration sample. */
data class AuthData(val auth: AuthTokens)
/** Documents RefreshEnvelope class behavior for the Android integration sample. */
data class RefreshEnvelope(val data: AuthData)

/** Documents LoginData class behavior for the Android integration sample. */
data class LoginData(
    val user: Map<String, Any?>,
    val auth: AuthTokens,
)

/** Documents LoginEnvelope class behavior for the Android integration sample. */
data class LoginEnvelope(val data: LoginData)

/** Documents CartItemRequest class behavior for the Android integration sample. */
data class CartItemRequest(val variantId: String, val quantity: Int)
/** Documents CartQuantityRequest class behavior for the Android integration sample. */
data class CartQuantityRequest(val quantity: Int)
/** Documents PushTokenRequest class behavior for the Android integration sample. */
data class PushTokenRequest(val provider: String = "fcm", val token: String)
