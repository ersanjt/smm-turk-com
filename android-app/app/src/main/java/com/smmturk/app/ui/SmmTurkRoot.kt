package com.smmturk.app.ui

import android.content.Intent
import android.net.Uri
import androidx.activity.ComponentActivity
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.AccountCircle
import androidx.compose.material.icons.outlined.Home
import androidx.compose.material.icons.outlined.Inventory2
import androidx.compose.material.icons.outlined.ReceiptLong
import androidx.compose.material.icons.outlined.ShoppingCart
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationBarItemDefaults
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.core.util.Consumer
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import com.smmturk.app.AppViewModel
import com.smmturk.app.BuildConfig
import com.smmturk.app.MainTab
import com.smmturk.app.ui.account.AccountScreen
import com.smmturk.app.ui.auth.AuthScreen
import com.smmturk.app.ui.auth.GoogleSignInLauncher
import com.smmturk.app.ui.components.BrandLogo
import com.smmturk.app.ui.home.HomeScreen
import com.smmturk.app.ui.order.NewOrderScreen
import com.smmturk.app.ui.orders.OrdersScreen
import com.smmturk.app.ui.services.ServicesScreen
import com.smmturk.app.ui.theme.BrandRed
import com.smmturk.app.ui.theme.Muted
import com.smmturk.app.ui.theme.PageBg

@Composable
fun SmmTurkRoot(viewModel: AppViewModel = viewModel()) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val snackbar = remember { SnackbarHostState() }
    val context = LocalContext.current
    val activity = context as? ComponentActivity

    DisposableEffect(activity, viewModel) {
        if (activity == null) {
            return@DisposableEffect onDispose { }
        }
        val handle: (Intent?) -> Unit = { intent ->
            val data = intent?.data
            if (GoogleSignInLauncher.isGoogleCallback(data)) {
                val err = data?.getQueryParameter("error")
                val token = data?.getQueryParameter("token")
                when {
                    !err.isNullOrBlank() -> viewModel.googleAuthFailed(err)
                    !token.isNullOrBlank() -> viewModel.finishGoogleAuth(token)
                }
            }
        }
        handle(activity.intent)
        val listener = Consumer<Intent> { incoming ->
            activity.intent = incoming
            handle(incoming)
        }
        activity.addOnNewIntentListener(listener)
        onDispose { activity.removeOnNewIntentListener(listener) }
    }

    LaunchedEffect(state.notice, state.error) {
        val msg = state.notice ?: state.error
        if (!msg.isNullOrBlank() && state.loggedIn) {
            snackbar.showSnackbar(msg)
            viewModel.clearError()
        }
    }

    if (state.booting) {
        Box(Modifier.fillMaxSize().background(PageBg), contentAlignment = Alignment.Center) {
            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                BrandLogo(size = 72.dp)
                Spacer(Modifier.height(20.dp))
                CircularProgressIndicator(color = BrandRed)
            }
        }
        return
    }

    if (!state.loggedIn) {
        AuthScreen(
            loading = state.authLoading,
            error = state.error,
            notice = state.notice,
            needs2fa = state.needs2fa,
            onLogin = viewModel::login,
            onRegister = viewModel::register,
            onApiKey = viewModel::loginWithApiKey,
            onGoogle = viewModel::clearError,
            onDemo = viewModel::enterDemo,
        )
        return
    }

    Scaffold(
        containerColor = PageBg,
        contentWindowInsets = WindowInsets(0, 0, 0, 0),
        snackbarHost = { SnackbarHost(snackbar) },
        bottomBar = {
            NavigationBar(containerColor = Color.White) {
                MainTab.entries.forEach { tab ->
                    NavigationBarItem(
                        selected = state.tab == tab,
                        onClick = { viewModel.selectTab(tab) },
                        icon = {
                            Icon(
                                imageVector = when (tab) {
                                    MainTab.Home -> Icons.Outlined.Home
                                    MainTab.NewOrder -> Icons.Outlined.ShoppingCart
                                    MainTab.Orders -> Icons.Outlined.ReceiptLong
                                    MainTab.Services -> Icons.Outlined.Inventory2
                                    MainTab.Account -> Icons.Outlined.AccountCircle
                                },
                                contentDescription = tab.label,
                            )
                        },
                        label = { Text(tab.label) },
                        colors = NavigationBarItemDefaults.colors(
                            selectedIconColor = BrandRed,
                            selectedTextColor = BrandRed,
                            indicatorColor = Color(0xFFFFE8EA),
                            unselectedIconColor = Muted,
                            unselectedTextColor = Muted,
                        ),
                    )
                }
            }
        },
    ) { padding ->
        Box(Modifier.fillMaxSize().padding(padding)) {
            when (state.tab) {
                MainTab.Home -> HomeScreen(
                    state = state,
                    onRefresh = viewModel::refreshAll,
                    onNewOrder = { viewModel.selectTab(MainTab.NewOrder) },
                    onFunds = { openUrl(context, "${BuildConfig.SITE_URL}/funds") },
                    onSeeOrders = { viewModel.selectTab(MainTab.Orders) },
                )
                MainTab.NewOrder -> NewOrderScreen(
                    state = state,
                    onCategory = { viewModel.loadServices(state.serviceQuery, it) },
                    onQuery = { viewModel.loadServices(it, state.serviceCategory) },
                    onSubmit = { id, link, qty, coupon, done -> viewModel.placeOrder(id, link, qty, coupon, done) },
                )
                MainTab.Orders -> OrdersScreen(
                    state = state,
                    onFilter = viewModel::loadOrders,
                    onNewOrder = { viewModel.selectTab(MainTab.NewOrder) },
                )
                MainTab.Services -> ServicesScreen(
                    state = state,
                    onCategory = { viewModel.loadServices(state.serviceQuery, it) },
                    onQuery = { viewModel.loadServices(it, state.serviceCategory) },
                    onOrder = { viewModel.selectTab(MainTab.NewOrder) },
                )
                MainTab.Account -> AccountScreen(
                    state = state,
                    onLogout = viewModel::logout,
                    onFunds = { openUrl(context, "${BuildConfig.SITE_URL}/funds") },
                    onSite = { openUrl(context, BuildConfig.SITE_URL) },
                )
            }
        }
    }
}

private val MainTab.label: String
    get() = when (this) {
        MainTab.Home -> "Home"
        MainTab.NewOrder -> "Order"
        MainTab.Orders -> "Orders"
        MainTab.Services -> "Services"
        MainTab.Account -> "Account"
    }

private fun openUrl(context: android.content.Context, url: String) {
    context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
}
