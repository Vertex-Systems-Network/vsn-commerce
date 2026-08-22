package com.vsn.ecommerce.mobile.api

import java.io.IOException
import kotlinx.coroutines.runBlocking
import okhttp3.Authenticator
import okhttp3.Request
import okhttp3.Response
import okhttp3.Route
import retrofit2.HttpException

/** Documents TokenAuthenticator class behavior for the Android integration sample. */
class TokenAuthenticator(
    private val tokenStore: TokenStore,
    /** This API instance MUST use a client without TokenAuthenticator to prevent recursive refresh. */
    private val refreshApi: MobileApi,
    private val identity: AndroidClientIdentity,
) : Authenticator {
    private val lock = Any()

    /** Documents authenticate fun behavior for the Android integration sample. */
    override fun authenticate(route: Route?, response: Response): Request? = synchronized(lock) {
        if (responseCount(response) >= 2) return@synchronized null

        val requestToken = response.request.header("Authorization")?.removePrefix("Bearer ")
        val currentToken = tokenStore.accessToken()
        if (!currentToken.isNullOrBlank() && currentToken != requestToken) {
            return@synchronized response.request.newBuilder()
                .header("Authorization", "Bearer $currentToken")
                .build()
        }

        val refresh = tokenStore.refreshToken() ?: return@synchronized null
        val rotated = try {
            runBlocking {
                refreshApi.refresh(
                    RefreshRequest(
                        refreshToken = refresh,
                        deviceId = identity.deviceId,
                        deviceName = identity.deviceName,
                        appVersion = identity.appVersion,
                        osVersion = identity.osVersion,
                    )
                ).data.auth
            }
        } catch (error: HttpException) {
            // 401/422 means the server rejected the refresh credential (expired/replayed/revoked/device mismatch).
            // 426 means the app must update; keep credentials so an upgrade can resume the same account.
            if (error.code() == 401 || error.code() == 422) tokenStore.clear()
            return@synchronized null
        } catch (_: IOException) {
            // Network outage is not evidence that credentials are invalid.
            return@synchronized null
        } catch (_: Exception) {
            return@synchronized null
        }

        tokenStore.save(rotated)
        response.request.newBuilder()
            .header("Authorization", "Bearer ${rotated.accessToken}")
            .build()
    }

    /** Documents responseCount fun behavior for the Android integration sample. */
    private fun responseCount(response: Response): Int {
        var count = 1
        var prior = response.priorResponse
        while (prior != null) {
            count++
            prior = prior.priorResponse
        }
        return count
    }
}
