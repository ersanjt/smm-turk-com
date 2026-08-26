package com.smmturk.app.ui.auth

import android.content.Context
import android.content.Intent
import android.net.Uri
import androidx.browser.customtabs.CustomTabsIntent
import com.smmturk.app.BuildConfig

object GoogleSignInLauncher {
    fun start(context: Context) {
        val uri = Uri.parse(BuildConfig.SITE_URL.trimEnd('/') + "/login-google?mobile=1")
        try {
            CustomTabsIntent.Builder()
                .setShowTitle(true)
                .setUrlBarHidingEnabled(true)
                .build()
                .launchUrl(context, uri)
        } catch (_: Exception) {
            context.startActivity(Intent(Intent.ACTION_VIEW, uri))
        }
    }

    fun isGoogleCallback(data: Uri?): Boolean {
        return data != null && data.scheme == "smmturk" && data.host == "google-auth"
    }
}
