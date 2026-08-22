package com.vsn.ecommerce.mobile.api

import okhttp3.Interceptor
import okhttp3.Response

/** Documents AuthInterceptor class behavior for the Android integration sample. */
class AuthInterceptor(
    private val tokenStore: TokenStore,
    private val identity: AndroidClientIdentity,
) : Interceptor {
    /** Documents intercept fun behavior for the Android integration sample. */
    override fun intercept(chain: Interceptor.Chain): Response {
        val builder = chain.request().newBuilder()
            .header("Accept", "application/json")
            .header("X-VSN-Client", "android")
            .header("X-App-Version", identity.appVersion)
            .header("X-Device-Id", identity.deviceId)

        tokenStore.accessToken()?.let { builder.header("Authorization", "Bearer $it") }
        return chain.proceed(builder.build())
    }
}
