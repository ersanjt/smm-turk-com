package com.smmturk.app.ui.order

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.FilterChip
import androidx.compose.material3.MenuAnchorType
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.smmturk.app.UiState
import com.smmturk.app.ui.components.brandChipColors
import com.smmturk.app.ui.components.ScreenHeader
import com.smmturk.app.ui.theme.BrandRed
import com.smmturk.app.ui.theme.Muted

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
fun NewOrderScreen(
    state: UiState,
    onCategory: (String) -> Unit,
    onQuery: (String) -> Unit,
    onSubmit: (Int, String, Int, String, (String) -> Unit) -> Unit,
) {
    var categoryExpanded by remember { mutableStateOf(false) }
    var serviceExpanded by remember { mutableStateOf(false) }
    var selectedCategory by remember { mutableStateOf(state.serviceCategory) }
    var selectedId by remember { mutableStateOf(state.services.firstOrNull()?.id ?: 0) }
    var link by remember { mutableStateOf("") }
    var quantity by remember { mutableStateOf("") }
    var coupon by remember { mutableStateOf("") }
    var localMsg by remember { mutableStateOf<String?>(null) }

    val selected = state.services.firstOrNull { it.id == selectedId } ?: state.services.firstOrNull()
    val qty = quantity.toIntOrNull() ?: 0
    val rate = selected?.rate?.toDoubleOrNull() ?: 0.0
    val charge = if (qty > 0) rate * qty / 1000.0 else 0.0

    Column(
        Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .statusBarsPadding()
            .padding(20.dp),
    ) {
        ScreenHeader(title = "New order", subtitle = "Pick a service, paste a link, go.")
        Spacer(Modifier.height(16.dp))

        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            FilterChip(selected = selectedCategory.isBlank(), onClick = {
                selectedCategory = ""
                onCategory("")
            }, label = { Text("All") }, colors = brandChipColors())
            state.categories.take(8).forEach { cat ->
                FilterChip(selected = selectedCategory == cat, onClick = {
                    selectedCategory = cat
                    onCategory(cat)
                }, label = { Text(cat) }, colors = brandChipColors())
            }
        }

        Spacer(Modifier.height(12.dp))
        OutlinedTextField(
            value = state.serviceQuery,
            onValueChange = onQuery,
            label = { Text("Search services") },
            modifier = Modifier.fillMaxWidth(),
            singleLine = true,
        )
        Spacer(Modifier.height(12.dp))

        ExposedDropdownMenuBox(expanded = serviceExpanded, onExpandedChange = { serviceExpanded = it }) {
            OutlinedTextField(
                value = selected?.let { "#${it.id}  ${it.name}" } ?: "Choose a service",
                onValueChange = {},
                readOnly = true,
                label = { Text("Service") },
                trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(serviceExpanded) },
                modifier = Modifier.fillMaxWidth().menuAnchor(MenuAnchorType.PrimaryNotEditable),
            )
            ExposedDropdownMenu(expanded = serviceExpanded, onDismissRequest = { serviceExpanded = false }) {
                state.services.take(80).forEach { svc ->
                    DropdownMenuItem(
                        text = { Text("#${svc.id}  ${svc.name}  ·  $${svc.rate}") },
                        onClick = {
                            selectedId = svc.id
                            serviceExpanded = false
                        },
                    )
                }
            }
        }

        if (selected != null) {
            Text(
                "Min ${selected.min} · Max ${selected.max} · $${selected.rate} / 1k",
                color = Muted,
                fontSize = 12.sp,
                modifier = Modifier.padding(top = 8.dp),
            )
        }

        Spacer(Modifier.height(12.dp))
        OutlinedTextField(link, { link = it }, label = { Text("Link") }, modifier = Modifier.fillMaxWidth(), singleLine = true)
        Spacer(Modifier.height(12.dp))
        OutlinedTextField(
            quantity, { quantity = it.filter { ch -> ch.isDigit() } },
            label = { Text("Quantity") },
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
            modifier = Modifier.fillMaxWidth(),
            singleLine = true,
        )
        Spacer(Modifier.height(12.dp))
        OutlinedTextField(coupon, { coupon = it }, label = { Text("Coupon (optional)") }, modifier = Modifier.fillMaxWidth(), singleLine = true)

        Spacer(Modifier.height(16.dp))
        Text("Estimated charge", color = Muted, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
        Text("$" + "%.5f".format(charge), fontSize = 26.sp, fontWeight = FontWeight.Black, color = BrandRed)

        if (!localMsg.isNullOrBlank()) {
            Text(localMsg!!, color = if (localMsg!!.startsWith("Order")) ColorSuccess else BrandRed, fontSize = 13.sp, modifier = Modifier.padding(top = 8.dp))
        }

        Spacer(Modifier.height(16.dp))
        Button(
            onClick = {
                val svc = selected
                if (svc == null) {
                    localMsg = "Choose a service"
                    return@Button
                }
                if (link.isBlank() || qty <= 0) {
                    localMsg = "Enter a link and quantity"
                    return@Button
                }
                onSubmit(svc.id, link, qty, coupon) { msg ->
                    if (msg.isNotBlank()) localMsg = msg
                }
            },
            enabled = !state.loading,
            modifier = Modifier.fillMaxWidth().height(52.dp),
            shape = RoundedCornerShape(14.dp),
            colors = ButtonDefaults.buttonColors(containerColor = BrandRed, contentColor = Color.White),
        ) {
            Text("Place order", fontWeight = FontWeight.Bold)
        }
        Spacer(Modifier.height(24.dp))
    }
}

private val ColorSuccess = androidx.compose.ui.graphics.Color(0xFF047857)
