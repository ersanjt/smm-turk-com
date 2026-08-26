package com.smmturk.app.ui.services

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.FilterChip
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.smmturk.app.UiState
import com.smmturk.app.ui.components.brandChipColors
import com.smmturk.app.ui.components.ScreenHeader
import com.smmturk.app.ui.theme.BrandRed
import com.smmturk.app.ui.theme.Muted

@Composable
fun ServicesScreen(
    state: UiState,
    onCategory: (String) -> Unit,
    onQuery: (String) -> Unit,
    onOrder: () -> Unit,
) {
    Column(Modifier.fillMaxSize().statusBarsPadding()) {
        ScreenHeader(title = "Services", modifier = Modifier.padding(horizontal = 20.dp, vertical = 8.dp))
        OutlinedTextField(
            value = state.serviceQuery,
            onValueChange = onQuery,
            label = { Text("Search") },
            modifier = Modifier.fillMaxWidth().padding(horizontal = 20.dp),
            singleLine = true,
        )
        LazyRow(
            contentPadding = PaddingValues(horizontal = 20.dp, vertical = 12.dp),
            horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            item {
                FilterChip(selected = state.serviceCategory.isBlank(), onClick = { onCategory("") }, label = { Text("All") }, colors = brandChipColors())
            }
            items(state.categories) { cat ->
                FilterChip(selected = state.serviceCategory == cat, onClick = { onCategory(cat) }, label = { Text(cat) }, colors = brandChipColors())
            }
        }
        LazyColumn(
            contentPadding = PaddingValues(horizontal = 20.dp, vertical = 8.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            items(state.services, key = { it.id }) { svc ->
                Card(
                    colors = CardDefaults.cardColors(containerColor = Color.White),
                    shape = RoundedCornerShape(16.dp),
                    elevation = CardDefaults.cardElevation(1.dp),
                ) {
                    Column(Modifier.padding(16.dp)) {
                        Text(svc.category.uppercase(), color = BrandRed, fontSize = 10.sp, fontWeight = FontWeight.Bold, letterSpacing = 0.8.sp)
                        Text(svc.name, fontWeight = FontWeight.SemiBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                            Text("$${svc.rate} / 1k · ${svc.min}–${svc.max}", color = Muted, fontSize = 12.sp)
                            TextButton(onClick = onOrder) { Text("Order", color = BrandRed, fontWeight = FontWeight.Bold) }
                        }
                    }
                }
            }
        }
    }
}
