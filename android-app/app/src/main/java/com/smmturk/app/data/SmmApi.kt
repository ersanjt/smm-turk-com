package com.smmturk.app.data

import com.smmturk.app.BuildConfig
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.FormBody
import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONArray
import org.json.JSONObject
import java.util.concurrent.TimeUnit

class SmmApi(
    private val mobileUrl: String = BuildConfig.MOBILE_API_URL,
    private val v2Url: String = BuildConfig.V2_API_URL,
) {
    private val client = OkHttpClient.Builder()
        .connectTimeout(25, TimeUnit.SECONDS)
        .readTimeout(25, TimeUnit.SECONDS)
        .build()

    suspend fun login(email: String, password: String, totp: String = ""): LoginResult = withContext(Dispatchers.IO) {
        val fields = mutableMapOf(
            "action" to "login",
            "email" to email,
            "password" to password,
        )
        if (totp.isNotBlank()) fields["totp"] = totp
        val json = post(mobileUrl, fields)
        if (json.optBoolean("needs_2fa")) {
            throw ApiException(json.optString("error", "Two-factor code required"), needs2fa = true)
        }
        if (!json.optBoolean("success", json.has("api_key"))) {
            throw ApiException(json.optString("error", "Login failed"))
        }
        LoginResult(user = parseUser(json.optJSONObject("user"), json.optString("api_key")))
    }

    suspend fun register(username: String, email: String, password: String, ref: String = ""): LoginResult =
        withContext(Dispatchers.IO) {
            val json = post(
                mobileUrl,
                mapOf(
                    "action" to "register",
                    "username" to username,
                    "email" to email,
                    "password" to password,
                    "ref" to ref,
                ),
            )
            if (!json.optBoolean("success")) {
                throw ApiException(json.optString("error", "Registration failed"))
            }
            if (json.optBoolean("verify_required")) {
                return@withContext LoginResult(
                    user = UserProfile(0, username, email, "0.00000"),
                    verifyRequired = true,
                    message = json.optString("message", "Verify your email, then sign in."),
                )
            }
            val key = json.optString("api_key")
            if (key.isBlank()) {
                return@withContext LoginResult(
                    user = UserProfile(0, username, email, "0.00000"),
                    message = json.optString("message", "Account created. Sign in now."),
                )
            }
            LoginResult(user = parseUser(json.optJSONObject("user"), key))
        }

    suspend fun finishGoogle(token: String): LoginResult = withContext(Dispatchers.IO) {
        val json = post(mobileUrl, mapOf("action" to "google_finish", "token" to token))
        if (!json.optBoolean("success", json.has("api_key"))) {
            throw ApiException(json.optString("error", "Google sign-in failed"))
        }
        LoginResult(user = parseUser(json.optJSONObject("user"), json.optString("api_key")))
    }

    suspend fun loginWithApiKey(apiKey: String): UserProfile = withContext(Dispatchers.IO) {
        runCatching {
            val json = post(mobileUrl, mapOf("action" to "me"), apiKey)
            if (json.optBoolean("success")) parseUser(json.optJSONObject("user"), apiKey) else error("no")
        }.getOrElse {
            val json = post(v2Url, mapOf("action" to "balance", "key" to apiKey))
            if (json.has("error")) throw ApiException(json.optString("error", "Invalid API key"))
            UserProfile(
                id = 0,
                username = "Reseller",
                email = "",
                balance = json.optString("balance", "0"),
                currency = json.optString("currency", "USD"),
                apiKey = apiKey,
            )
        }
    }

    suspend fun dashboard(apiKey: String): Triple<UserProfile, DashboardStats, List<SmmOrder>> = withContext(Dispatchers.IO) {
        runCatching {
            val json = post(mobileUrl, mapOf("action" to "me"), apiKey)
            if (!json.optBoolean("success")) error("fallback")
            val user = parseUser(json.optJSONObject("user"), apiKey)
            val statsObj = json.optJSONObject("stats") ?: JSONObject()
            val stats = DashboardStats(
                ordersTotal = statsObj.optInt("orders_total"),
                ordersCompleted = statsObj.optInt("orders_completed"),
                ordersOpen = statsObj.optInt("orders_open"),
            )
            Triple(user, stats, parseOrders(json.optJSONArray("recent_orders")))
        }.getOrElse {
            val bal = post(v2Url, mapOf("action" to "balance", "key" to apiKey))
            if (bal.has("error")) throw ApiException(bal.optString("error", "Could not load account"))
            val user = UserProfile(0, "Account", "", bal.optString("balance", "0"), apiKey = apiKey)
            val ordersJson = runCatching { post(v2Url, mapOf("action" to "orders", "key" to apiKey, "limit" to "8")) }.getOrNull()
            val orders = ordersJson?.optJSONArray("orders")?.let(::parseOrders) ?: emptyList()
            Triple(user, DashboardStats(orders.size, orders.count { it.status == "Completed" }, orders.count { it.status in OPEN_STATUSES }), orders)
        }
    }

    suspend fun services(apiKey: String, query: String = "", category: String = ""): Pair<List<String>, List<SmmService>> =
        withContext(Dispatchers.IO) {
            runCatching {
                val fields = mutableMapOf("action" to "services")
                if (query.isNotBlank()) fields["q"] = query
                if (category.isNotBlank()) fields["category"] = category
                val json = post(mobileUrl, fields, apiKey)
                if (!json.optBoolean("success") && !json.has("services")) error("fallback")
                parseCategories(json.optJSONArray("categories")) to parseServices(json.optJSONArray("services"))
            }.getOrElse {
                val json = post(v2Url, mapOf("action" to "services", "key" to apiKey))
                if (json.has("error")) throw ApiException(json.optString("error", "Could not load services"))
                val list = parseServices(jsonToArray(json))
                val cats = list.map { it.category }.filter { it.isNotBlank() }.distinct().sorted()
                val filtered = list.filter {
                    (category.isBlank() || it.category == category) &&
                        (query.isBlank() || it.name.contains(query, true) || it.category.contains(query, true))
                }
                cats to filtered.take(400)
            }
        }

    suspend fun orders(apiKey: String, status: String = "", page: Int = 1): Pair<Int, List<SmmOrder>> =
        withContext(Dispatchers.IO) {
            runCatching {
                val json = post(
                    mobileUrl,
                    mapOf("action" to "orders", "status" to status, "page" to page.toString(), "limit" to "20"),
                    apiKey,
                )
                if (!json.optBoolean("success") && !json.has("orders")) error("fallback")
                json.optInt("total") to parseOrders(json.optJSONArray("orders"))
            }.getOrElse {
                val fields = mutableMapOf("action" to "orders", "key" to apiKey, "page" to page.toString(), "limit" to "20")
                if (status.isNotBlank()) fields["status"] = status
                val json = post(v2Url, fields)
                if (json.has("error")) throw ApiException(json.optString("error", "Could not load orders"))
                json.optInt("total") to parseOrders(json.optJSONArray("orders"))
            }
        }

    suspend fun addOrder(apiKey: String, serviceId: Int, link: String, quantity: Int, coupon: String = ""): Pair<Int, String> =
        withContext(Dispatchers.IO) {
            val fields = mutableMapOf(
                "action" to "add",
                "service" to serviceId.toString(),
                "link" to link,
                "quantity" to quantity.toString(),
            )
            if (coupon.isNotBlank()) fields["coupon"] = coupon
            val mobile = runCatching { post(mobileUrl, fields, apiKey) }.getOrNull()
            if (mobile != null && (mobile.optBoolean("success") || mobile.has("order") || mobile.has("error"))) {
                val looksLikeHtml = mobile.optString("error").contains("<html", ignoreCase = true)
                if (!looksLikeHtml) {
                    if (mobile.optBoolean("success") || (mobile.has("order") && !mobile.has("error"))) {
                        return@withContext mobile.optInt("order") to mobile.optString("charge")
                    }
                    throw ApiException(mobile.optString("error", "Order failed"))
                }
            }
            val v2Fields = fields.toMutableMap().apply { put("key", apiKey) }
            val json = post(v2Url, v2Fields)
            if (json.has("error")) throw ApiException(json.optString("error", "Order failed"))
            json.optInt("order") to json.optString("charge")
        }

    private fun post(url: String, fields: Map<String, String>, apiKey: String? = null): JSONObject {
        val body = FormBody.Builder().also { b -> fields.forEach { (k, v) -> b.add(k, v) } }.build()
        val req = Request.Builder().url(url).post(body).apply {
            if (!apiKey.isNullOrBlank()) header("X-API-Key", apiKey)
        }.build()
        client.newCall(req).execute().use { resp ->
            val text = resp.body?.string().orEmpty().ifBlank { "{}" }
            val trimmed = text.trim()
            return when {
                trimmed.startsWith("[") -> JSONObject().put("services", JSONArray(trimmed))
                trimmed.startsWith("{") -> JSONObject(trimmed)
                else -> JSONObject().put("error", trimmed.take(180).ifBlank { "Unexpected response" })
            }
        }
    }

    private fun parseUser(obj: JSONObject?, apiKey: String): UserProfile {
        val o = obj ?: JSONObject()
        return UserProfile(
            id = o.optInt("id"),
            username = o.optString("username", "User"),
            email = o.optString("email"),
            balance = o.optString("balance", "0.00000"),
            currency = o.optString("currency", "USD"),
            role = o.optString("role", "user"),
            apiKey = o.optString("api_key").ifBlank { apiKey },
        )
    }

    private fun parseOrders(arr: JSONArray?): List<SmmOrder> {
        if (arr == null) return emptyList()
        return buildList {
            for (i in 0 until arr.length()) {
                val o = arr.optJSONObject(i) ?: continue
                add(
                    SmmOrder(
                        id = o.optInt("id"),
                        serviceId = o.optInt("service_id"),
                        service = o.optString("service").ifBlank { o.optString("service_name") },
                        category = o.optString("category"),
                        link = o.optString("link"),
                        quantity = o.optInt("quantity"),
                        charge = o.optString("charge"),
                        status = o.optString("status"),
                        startCount = o.optString("start_count", "0"),
                        remains = o.optString("remains", "0"),
                        createdAt = o.optString("created_at"),
                    ),
                )
            }
        }
    }

    private fun parseServices(arr: JSONArray?): List<SmmService> {
        if (arr == null) return emptyList()
        return buildList {
            for (i in 0 until arr.length()) {
                val o = arr.optJSONObject(i) ?: continue
                add(
                    SmmService(
                        id = o.optInt("service", o.optInt("service_id")),
                        name = o.optString("name"),
                        type = o.optString("type", "Default"),
                        category = o.optString("category"),
                        rate = o.optString("rate"),
                        min = o.optString("min"),
                        max = o.optString("max"),
                        refill = o.optBoolean("refill"),
                        cancel = o.optBoolean("cancel"),
                    ),
                )
            }
        }
    }

    private fun parseCategories(arr: JSONArray?): List<String> {
        if (arr == null) return emptyList()
        return buildList {
            for (i in 0 until arr.length()) add(arr.optString(i))
        }.filter { it.isNotBlank() }
    }

    private fun jsonToArray(json: JSONObject): JSONArray {
        if (json.has("services")) return json.optJSONArray("services") ?: JSONArray()
        return JSONArray()
    }

    companion object {
        private val OPEN_STATUSES = setOf("Pending", "Processing", "In progress")

        fun demoUser() = UserProfile(
            id = 1,
            username = "Demo",
            email = "demo@smm-turk.com",
            balance = "48.50000",
            apiKey = "demo",
        )

        fun demoStats() = DashboardStats(12, 7, 3)

        fun demoOrders(): List<SmmOrder> = listOf(
            SmmOrder(10482, 101, "Instagram Followers [Real]", "Instagram", "https://instagram.com/brand", 1000, "0.90000", "In progress", "1200", "400", "2026-08-26 09:12"),
            SmmOrder(10471, 204, "TikTok Views", "TikTok", "https://tiktok.com/@clip", 50000, "1.25000", "Completed", "0", "0", "2026-08-25 18:40"),
            SmmOrder(10455, 310, "YouTube Likes", "YouTube", "https://youtube.com/watch?v=abc", 500, "0.65000", "Pending", "0", "500", "2026-08-25 11:02"),
            SmmOrder(10440, 101, "Instagram Followers [Real]", "Instagram", "https://instagram.com/shop", 2500, "2.25000", "Completed", "8900", "0", "2026-08-24 16:20"),
            SmmOrder(10412, 418, "Telegram Members", "Telegram", "https://t.me/channel", 300, "1.80000", "Partial", "100", "80", "2026-08-23 08:55"),
        )

        fun demoServices(): Pair<List<String>, List<SmmService>> {
            val list = listOf(
                SmmService(101, "Instagram Followers [Real]", "Default", "Instagram", "0.90000", "50", "10000", refill = true, cancel = true),
                SmmService(102, "Instagram Likes", "Default", "Instagram", "0.18000", "20", "50000", refill = true),
                SmmService(103, "Instagram Views", "Default", "Instagram", "0.04000", "100", "1000000"),
                SmmService(204, "TikTok Views", "Default", "TikTok", "0.02500", "100", "5000000"),
                SmmService(205, "TikTok Followers", "Default", "TikTok", "1.20000", "50", "20000", refill = true),
                SmmService(310, "YouTube Likes", "Default", "YouTube", "1.30000", "20", "20000"),
                SmmService(311, "YouTube Subscribers", "Default", "YouTube", "4.50000", "50", "5000", refill = true),
                SmmService(418, "Telegram Members", "Default", "Telegram", "6.00000", "100", "10000"),
                SmmService(501, "X / Twitter Followers", "Default", "Twitter", "2.10000", "50", "15000"),
            )
            return list.map { it.category }.distinct() to list
        }
    }
}
