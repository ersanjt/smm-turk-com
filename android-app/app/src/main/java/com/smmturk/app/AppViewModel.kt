package com.smmturk.app

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.smmturk.app.data.ApiException
import com.smmturk.app.data.DashboardStats
import com.smmturk.app.data.SessionStore
import com.smmturk.app.data.SmmApi
import com.smmturk.app.data.SmmOrder
import com.smmturk.app.data.SmmService
import com.smmturk.app.data.UserProfile
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

enum class MainTab { Home, NewOrder, Orders, Services, Account }

data class UiState(
    val booting: Boolean = true,
    val loggedIn: Boolean = false,
    val demo: Boolean = false,
    val tab: MainTab = MainTab.Home,
    val loading: Boolean = false,
    val authLoading: Boolean = false,
    val error: String? = null,
    val notice: String? = null,
    val needs2fa: Boolean = false,
    val user: UserProfile? = null,
    val stats: DashboardStats = DashboardStats(),
    val recentOrders: List<SmmOrder> = emptyList(),
    val orders: List<SmmOrder> = emptyList(),
    val orderFilter: String = "",
    val categories: List<String> = emptyList(),
    val services: List<SmmService> = emptyList(),
    val serviceQuery: String = "",
    val serviceCategory: String = "",
)

class AppViewModel(application: Application) : AndroidViewModel(application) {
    private val session = SessionStore(application)
    private val api = SmmApi()

    private val _state = MutableStateFlow(UiState())
    val state: StateFlow<UiState> = _state

    init {
        if (session.isLoggedIn) {
            _state.update { it.copy(loggedIn = true, demo = session.isDemo, booting = false) }
            refreshAll()
        } else {
            _state.update { it.copy(booting = false) }
        }
    }

    fun selectTab(tab: MainTab) {
        _state.update { it.copy(tab = tab, error = null) }
        if (tab == MainTab.Orders) loadOrders()
        if (tab == MainTab.Services || tab == MainTab.NewOrder) loadServices()
    }

    fun clearError() {
        _state.update { it.copy(error = null, notice = null) }
    }

    fun login(email: String, password: String, totp: String) {
        viewModelScope.launch {
            _state.update { it.copy(authLoading = true, error = null, needs2fa = false) }
            runCatching { api.login(email.trim(), password, totp.trim()) }
                .onSuccess { result ->
                    session.saveUser(result.user)
                    _state.update {
                        it.copy(
                            authLoading = false,
                            loggedIn = true,
                            demo = false,
                            user = result.user,
                            needs2fa = false,
                        )
                    }
                    refreshAll()
                }
                .onFailure { e ->
                    val twoFa = (e as? ApiException)?.needs2fa == true
                    _state.update {
                        it.copy(authLoading = false, needs2fa = twoFa || it.needs2fa, error = e.message)
                    }
                }
        }
    }

    fun register(username: String, email: String, password: String) {
        viewModelScope.launch {
            _state.update { it.copy(authLoading = true, error = null) }
            runCatching { api.register(username.trim(), email.trim(), password) }
                .onSuccess { result ->
                    if (result.verifyRequired || result.user.apiKey.isBlank()) {
                        _state.update {
                            it.copy(
                                authLoading = false,
                                notice = result.message.ifBlank { "Account created. Sign in after verifying email." },
                            )
                        }
                    } else {
                        session.saveUser(result.user)
                        _state.update {
                            it.copy(authLoading = false, loggedIn = true, demo = false, user = result.user)
                        }
                        refreshAll()
                    }
                }
                .onFailure { e ->
                    _state.update { it.copy(authLoading = false, error = e.message) }
                }
        }
    }

    fun loginWithApiKey(key: String) {
        viewModelScope.launch {
            _state.update { it.copy(authLoading = true, error = null) }
            runCatching { api.loginWithApiKey(key.trim()) }
                .onSuccess { user ->
                    session.saveUser(user)
                    _state.update { it.copy(authLoading = false, loggedIn = true, demo = false, user = user) }
                    refreshAll()
                }
                .onFailure { e ->
                    _state.update { it.copy(authLoading = false, error = e.message ?: "Invalid API key") }
                }
        }
    }

    fun finishGoogleAuth(token: String) {
        val clean = token.trim()
        if (clean.isBlank() || clean == lastGoogleToken) return
        lastGoogleToken = clean
        viewModelScope.launch {
            _state.update { it.copy(authLoading = true, error = null) }
            runCatching { api.finishGoogle(clean) }
                .onSuccess { result ->
                    session.saveUser(result.user)
                    _state.update {
                        it.copy(authLoading = false, loggedIn = true, demo = false, user = result.user, needs2fa = false)
                    }
                    refreshAll()
                }
                .onFailure { e ->
                    lastGoogleToken = ""
                    _state.update { it.copy(authLoading = false, error = e.message ?: "Google sign-in failed") }
                }
        }
    }

    fun googleAuthFailed(message: String) {
        _state.update { it.copy(authLoading = false, error = message) }
    }

    private var lastGoogleToken: String = ""

    fun enterDemo() {
        session.saveUser(SmmApi.demoUser(), demo = true)
        _state.update {
            it.copy(
                loggedIn = true,
                demo = true,
                user = SmmApi.demoUser(),
                stats = SmmApi.demoStats(),
                recentOrders = SmmApi.demoOrders(),
                orders = SmmApi.demoOrders(),
                categories = SmmApi.demoServices().first,
                services = SmmApi.demoServices().second,
            )
        }
    }

    fun logout() {
        lastGoogleToken = ""
        session.clear()
        _state.value = UiState(booting = false)
    }

    fun refreshAll() {
        if (session.isDemo) {
            _state.update {
                it.copy(
                    user = SmmApi.demoUser(),
                    stats = SmmApi.demoStats(),
                    recentOrders = SmmApi.demoOrders(),
                    orders = SmmApi.demoOrders(),
                    categories = SmmApi.demoServices().first,
                    services = SmmApi.demoServices().second,
                    loading = false,
                )
            }
            return
        }
        val key = session.apiKey
        if (key.isBlank()) return
        viewModelScope.launch {
            _state.update { it.copy(loading = true, error = null) }
            runCatching { api.dashboard(key) }
                .onSuccess { (user, stats, recent) ->
                    session.saveUser(user)
                    _state.update {
                        it.copy(loading = false, user = user, stats = stats, recentOrders = recent)
                    }
                }
                .onFailure { e ->
                    _state.update { it.copy(loading = false, error = e.message) }
                }
            loadServices()
            loadOrders()
        }
    }

    fun loadOrders(status: String = _state.value.orderFilter) {
        if (session.isDemo) {
            val all = SmmApi.demoOrders()
            _state.update {
                it.copy(
                    orderFilter = status,
                    orders = if (status.isBlank()) all else all.filter { o -> o.status == status },
                )
            }
            return
        }
        val key = session.apiKey
        if (key.isBlank()) return
        viewModelScope.launch {
            runCatching { api.orders(key, status) }
                .onSuccess { (_, list) ->
                    _state.update { it.copy(orderFilter = status, orders = list) }
                }
                .onFailure { e ->
                    _state.update { it.copy(error = e.message) }
                }
        }
    }

    fun loadServices(query: String = _state.value.serviceQuery, category: String = _state.value.serviceCategory) {
        if (session.isDemo) {
            val (cats, list) = SmmApi.demoServices()
            _state.update {
                it.copy(
                    serviceQuery = query,
                    serviceCategory = category,
                    categories = cats,
                    services = list.filter {
                        (category.isBlank() || it.category == category) &&
                            (query.isBlank() || it.name.contains(query, true) || it.category.contains(query, true))
                    },
                )
            }
            return
        }
        val key = session.apiKey
        if (key.isBlank()) return
        viewModelScope.launch {
            runCatching { api.services(key, query, category) }
                .onSuccess { (cats, list) ->
                    _state.update {
                        it.copy(serviceQuery = query, serviceCategory = category, categories = cats, services = list)
                    }
                }
                .onFailure { e ->
                    _state.update { it.copy(error = e.message) }
                }
        }
    }

    fun placeOrder(serviceId: Int, link: String, quantity: Int, coupon: String, onDone: (String) -> Unit) {
        if (session.isDemo) {
            onDone("Preview mode — connect a real account to place orders.")
            return
        }
        val key = session.apiKey
        viewModelScope.launch {
            _state.update { it.copy(loading = true, error = null) }
            runCatching { api.addOrder(key, serviceId, link.trim(), quantity, coupon.trim()) }
                .onSuccess { (id, charge) ->
                    val msg = if (charge.isNotBlank()) "Order #$id placed · $$charge" else "Order #$id placed"
                    _state.update { it.copy(loading = false, tab = MainTab.Orders, notice = msg) }
                    refreshAll()
                    onDone(msg)
                }
                .onFailure { e ->
                    _state.update { it.copy(loading = false, error = e.message) }
                    onDone("")
                }
        }
    }
}
