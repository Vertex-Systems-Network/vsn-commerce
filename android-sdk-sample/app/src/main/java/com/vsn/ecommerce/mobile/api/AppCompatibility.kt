package com.vsn.ecommerce.mobile.api

/** Small client-side model for the public /api/mobile/v1/config compatibility contract. */
data class AndroidCompatibility(
    val minimumVersion: String,
    val latestVersion: String,
    val minimumSdk: Int,
    val storeUrl: String?,
    val updateAvailable: Boolean?,
)

/** Documents CompatibilityDecision interface behavior for the Android integration sample. */
sealed interface CompatibilityDecision {
    /** Documents Supported object behavior for the Android integration sample. */
    data object Supported : CompatibilityDecision
    /** Documents UpdateAvailable class behavior for the Android integration sample. */
    data class UpdateAvailable(val latestVersion: String, val storeUrl: String?) : CompatibilityDecision
    /** Documents UpdateRequired class behavior for the Android integration sample. */
    data class UpdateRequired(val minimumVersion: String, val storeUrl: String?) : CompatibilityDecision
    /** Documents UnsupportedSdk class behavior for the Android integration sample. */
    data class UnsupportedSdk(val minimumSdk: Int) : CompatibilityDecision
}
