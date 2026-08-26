package com.smmturk.app.ui.components

import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.FilterChipDefaults
import androidx.compose.material3.SelectableChipColors
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.smmturk.app.R
import com.smmturk.app.data.SmmOrder
import com.smmturk.app.ui.theme.BrandRed
import com.smmturk.app.ui.theme.BrandRedDark
import com.smmturk.app.ui.theme.Ink
import com.smmturk.app.ui.theme.Muted
import com.smmturk.app.ui.theme.SuccessGreen
import com.smmturk.app.ui.theme.WarnOrange

@Composable
fun BrandLogo(size: Dp = 56.dp, modifier: Modifier = Modifier) {
    Image(
        painter = painterResource(R.drawable.ic_brand),
        contentDescription = "SMM Turk",
        contentScale = ContentScale.Crop,
        modifier = modifier
            .size(size)
            .clip(RoundedCornerShape(size * 0.23f)),
    )
}

@Composable
fun ScreenHeader(title: String, subtitle: String? = null, modifier: Modifier = Modifier) {
    Row(
        modifier = modifier.fillMaxWidth(),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        BrandLogo(size = 40.dp)
        Column {
            Text(title, fontSize = 22.sp, fontWeight = FontWeight.ExtraBold, color = Ink)
            if (!subtitle.isNullOrBlank()) {
                Text(subtitle, color = Muted, fontSize = 13.sp)
            }
        }
    }
}

@Composable
fun brandChipColors(): SelectableChipColors = FilterChipDefaults.filterChipColors(
    selectedContainerColor = Color(0xFFFFE8EA),
    selectedLabelColor = BrandRedDark,
    selectedLeadingIconColor = BrandRed,
)

fun statusColor(status: String): Color = when (status.lowercase()) {
    "completed" -> SuccessGreen
    "pending", "partial" -> WarnOrange
    "cancelled", "refunded" -> Muted
    else -> BrandRed
}

@Composable
fun StatusChip(status: String) {
    val color = statusColor(status)
    Box(
        Modifier
            .background(color.copy(alpha = 0.12f), RoundedCornerShape(20.dp))
            .padding(horizontal = 10.dp, vertical = 4.dp),
    ) {
        Text(status, color = color, fontSize = 11.sp, fontWeight = FontWeight.Bold)
    }
}

@Composable
fun OrderCard(order: SmmOrder, modifier: Modifier = Modifier) {
    Card(
        modifier = modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(containerColor = Color.White),
        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp),
        shape = RoundedCornerShape(16.dp),
    ) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                Text("#${order.id}", fontWeight = FontWeight.Bold, fontSize = 14.sp)
                StatusChip(order.status)
            }
            Text(order.service.ifBlank { "Service #${order.serviceId}" }, fontWeight = FontWeight.SemiBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
            if (order.link.isNotBlank()) {
                Text(order.link, color = Muted, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                Text("Qty ${order.quantity}", color = Muted, fontSize = 12.sp)
                Text("$${order.charge}", color = BrandRed, fontWeight = FontWeight.Bold, fontSize = 13.sp)
            }
        }
    }
}
