package com.smmturk.app.ui.orders

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.smmturk.app.UiState
import com.smmturk.app.ui.components.OrderCard
import com.smmturk.app.ui.components.brandChipColors
import com.smmturk.app.ui.components.ScreenHeader
import com.smmturk.app.ui.theme.BrandRed
import com.smmturk.app.ui.theme.Muted

private val Filters = listOf(
    "" to "All",
    "Pending" to "Pending",
    "Processing" to "Processing",
    "In progress" to "In progress",
    "Completed" to "Completed",
    "Partial" to "Partial",
    "Cancelled" to "Cancelled",
)

@Composable
fun OrdersScreen(
    state: UiState,
    onFilter: (String) -> Unit,
    onNewOrder: () -> Unit,
) {
    Column(Modifier.fillMaxSize().statusBarsPadding()) {
        ScreenHeader(title = "My orders", modifier = Modifier.padding(horizontal = 20.dp, vertical = 8.dp))
        LazyRow(
            contentPadding = PaddingValues(horizontal = 20.dp),
            horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            items(Filters) { (value, label) ->
                FilterChip(selected = state.orderFilter == value, onClick = { onFilter(value) }, label = { Text(label) }, colors = brandChipColors())
            }
        }
        if (state.orders.isEmpty()) {
            Column(Modifier.padding(32.dp)) {
                Text("No orders in this filter.", color = Muted)
                TextButton(onClick = onNewOrder) { Text("Place an order", color = BrandRed, fontWeight = FontWeight.Bold) }
            }
        } else {
            LazyColumn(
                contentPadding = PaddingValues(20.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp),
                modifier = Modifier.fillMaxWidth(),
            ) {
                items(state.orders, key = { it.id }) { OrderCard(it) }
                item { Spacer(Modifier.height(12.dp)) }
            }
        }
    }
}
