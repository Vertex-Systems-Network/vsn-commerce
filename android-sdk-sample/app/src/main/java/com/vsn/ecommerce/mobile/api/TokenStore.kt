package com.vsn.ecommerce.mobile.api

/**
 * Production implementation must persist these secrets using Android Keystore-backed encryption.
 * Do not use plain SharedPreferences.
 */
interface TokenStore {
    /** Documents accessToken fun behavior for the Android integration sample. */
    fun accessToken(): String?
    /** Documents refreshToken fun behavior for the Android integration sample. */
    fun refreshToken(): String?
    /** Documents save fun behavior for the Android integration sample. */
    fun save(tokens: AuthTokens)
    /** Documents clear fun behavior for the Android integration sample. */
    fun clear()
}

/** Documents AndroidClientIdentity class behavior for the Android integration sample. */
data class AndroidClientIdentity(
    val deviceId: String,
    val deviceName: String,
    val appVersion: String,
    val osVersion: String,
)
