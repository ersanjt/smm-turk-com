package com.smmturk.app.data

import android.content.Context

class SessionStore(context: Context) {
    private val prefs = context.applicationContext.getSharedPreferences("smm_turk", Context.MODE_PRIVATE)

    var apiKey: String
        get() = prefs.getString("api_key", "") ?: ""
        set(value) { prefs.edit().putString("api_key", value).apply() }

    var username: String
        get() = prefs.getString("username", "") ?: ""
        set(value) { prefs.edit().putString("username", value).apply() }

    var email: String
        get() = prefs.getString("email", "") ?: ""
        set(value) { prefs.edit().putString("email", value).apply() }

    var isDemo: Boolean
        get() = prefs.getBoolean("demo", false)
        set(value) { prefs.edit().putBoolean("demo", value).apply() }

    val isLoggedIn: Boolean
        get() = apiKey.isNotBlank() || isDemo

    fun saveUser(user: UserProfile, demo: Boolean = false) {
        prefs.edit()
            .putString("api_key", user.apiKey)
            .putString("username", user.username)
            .putString("email", user.email)
            .putBoolean("demo", demo)
            .apply()
    }

    fun clear() {
        prefs.edit().clear().apply()
    }
}
