package com.smmturk.app.data

data class UserProfile(
    val id: Int,
    val username: String,
    val email: String,
    val balance: String,
    val currency: String = "USD",
    val role: String = "user",
    val apiKey: String = "",
)

data class DashboardStats(
    val ordersTotal: Int = 0,
    val ordersCompleted: Int = 0,
    val ordersOpen: Int = 0,
)

data class SmmOrder(
    val id: Int,
    val serviceId: Int,
    val service: String,
    val category: String,
    val link: String,
    val quantity: Int,
    val charge: String,
    val status: String,
    val startCount: String = "0",
    val remains: String = "0",
    val createdAt: String = "",
)

data class SmmService(
    val id: Int,
    val name: String,
    val type: String,
    val category: String,
    val rate: String,
    val min: String,
    val max: String,
    val refill: Boolean = false,
    val cancel: Boolean = false,
)

data class LoginResult(
    val user: UserProfile,
    val needs2fa: Boolean = false,
    val verifyRequired: Boolean = false,
    val message: String = "",
)

class ApiException(message: String, val needs2fa: Boolean = false) : Exception(message)
