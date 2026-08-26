package com.smmturk.app.ui.home

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.smmturk.app.UiState
import com.smmturk.app.ui.components.OrderCard
import com.smmturk.app.ui.components.ScreenHeader
import com.smmturk.app.ui.theme.BrandRed
import com.smmturk.app.ui.theme.Ink
import com.smmturk.app.ui.theme.Muted

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun HomeScreen(
    state: UiState,
    onRefresh: () -> Unit,
    onNewOrder: () -> Unit,
    onFunds: () -> Unit,
    onSeeOrders: () -> Unit,
) {
    PullToRefreshBox(isRefreshing = state.loading, onRefresh = onRefresh, modifier = Modifier.fillMaxSize()) {
        Column(
            Modifier
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .statusBarsPadding()
                .padding(20.dp),
        ) {
            ScreenHeader(
                title = state.user?.username ?: "SMM Turk",
                subtitle = if (state.demo) "Preview mode — sample data" else "Welcome back",
            )

            Spacer(Modifier.height(18.dp))
            Card(
                shape = RoundedCornerShape(22.dp),
                colors = CardDefaults.cardColors(containerColor = Color.Transparent),
            ) {
                Column(
                    Modifier
                        .fillMaxWidth()
                        .background(Brush.linearGradient(listOf(BrandRed, Color(0xFF9A0610))))
                        .padding(20.dp),
                ) {
                    Text("BALANCE", color = Color.White.copy(alpha = 0.75f), fontSize = 11.sp, fontWeight = FontWeight.Bold, letterSpacing = 1.2.sp)
                    Text("$${state.user?.balance ?: "0.00000"}", color = Color.White, fontSize = 32.sp, fontWeight = FontWeight.Black)
                    Text(state.user?.currency ?: "USD", color = Color.White.copy(alpha = 0.8f), fontSize = 12.sp)
                    Spacer(Modifier.height(14.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Button(
                            onClick = onNewOrder,
                            colors = ButtonDefaults.buttonColors(containerColor = Color.White, contentColor = BrandRed),
                            shape = RoundedCornerShape(12.dp),
                        ) { Text("New order", fontWeight = FontWeight.Bold) }
                        TextButton(onClick = onFunds) { Text("Add funds", color = Color.White, fontWeight = FontWeight.SemiBold) }
                    }
                }
            }

            Spacer(Modifier.height(18.dp))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                StatMini("Total", state.stats.ordersTotal.toString(), Modifier.weight(1f))
                StatMini("Done", state.stats.ordersCompleted.toString(), Modifier.weight(1f))
                StatMini("Open", state.stats.ordersOpen.toString(), Modifier.weight(1f))
            }

            Spacer(Modifier.height(22.dp))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                Text("Recent orders", fontWeight = FontWeight.Bold, fontSize = 16.sp)
                TextButton(onClick = onSeeOrders) { Text("See all", color = BrandRed) }
            }
            if (state.recentOrders.isEmpty()) {
                Text("No orders yet. Place your first order to grow.", color = Muted, fontSize = 13.sp)
            } else {
                Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    state.recentOrders.take(5).forEach { OrderCard(it) }
                }
            }
            Spacer(Modifier.height(24.dp))
        }
    }
}

@Composable
private fun StatMini(label: String, value: String, modifier: Modifier = Modifier) {
    Card(modifier = modifier, shape = RoundedCornerShape(16.dp), colors = CardDefaults.cardColors(containerColor = Color.White)) {
        Column(Modifier.padding(14.dp)) {
            Text(label.uppercase(), color = Muted, fontSize = 10.sp, fontWeight = FontWeight.Bold, letterSpacing = 0.8.sp)
            Text(value, fontSize = 20.sp, fontWeight = FontWeight.ExtraBold, color = Ink)
        }
    }
}
