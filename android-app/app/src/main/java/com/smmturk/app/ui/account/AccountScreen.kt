package com.smmturk.app.ui.account

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
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
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.smmturk.app.UiState
import com.smmturk.app.ui.components.BrandLogo
import com.smmturk.app.ui.theme.BrandRed
import com.smmturk.app.ui.theme.Ink
import com.smmturk.app.ui.theme.Muted

@Composable
fun AccountScreen(
    state: UiState,
    onLogout: () -> Unit,
    onFunds: () -> Unit,
    onSite: () -> Unit,
) {
    val context = LocalContext.current
    val user = state.user
    Column(
        Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .statusBarsPadding()
            .padding(20.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            BrandLogo(size = 40.dp)
            Text("Account", fontSize = 24.sp, fontWeight = FontWeight.ExtraBold, color = Ink, modifier = Modifier.padding(start = 12.dp))
        }
        Spacer(Modifier.height(16.dp))
        Card(colors = CardDefaults.cardColors(containerColor = Color.White), shape = RoundedCornerShape(18.dp)) {
            Column(Modifier.padding(18.dp)) {
                Text(user?.username ?: "User", fontWeight = FontWeight.Bold, fontSize = 18.sp)
                if (!user?.email.isNullOrBlank()) {
                    Text(user!!.email, color = Muted, fontSize = 13.sp)
                }
                Spacer(Modifier.height(8.dp))
                Text("Balance", color = Muted, fontSize = 12.sp)
                Text("$${user?.balance ?: "0.00000"} ${user?.currency ?: "USD"}", fontSize = 22.sp, fontWeight = FontWeight.Black, color = BrandRed)
                if (state.demo) {
                    Text("Preview account — not connected to the live panel.", color = Muted, fontSize = 12.sp, modifier = Modifier.padding(top = 8.dp))
                }
            }
        }

        if (!user?.apiKey.isNullOrBlank() && !state.demo) {
            Spacer(Modifier.height(12.dp))
            Card(colors = CardDefaults.cardColors(containerColor = Color.White), shape = RoundedCornerShape(18.dp)) {
                Column(Modifier.padding(18.dp)) {
                    Text("API key", fontWeight = FontWeight.Bold)
                    Text(user!!.apiKey, color = Muted, fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp))
                    TextButtonCopy(context, user.apiKey)
                }
            }
        }

        Spacer(Modifier.height(16.dp))
        Button(
            onClick = onFunds,
            modifier = Modifier.fillMaxWidth().height(48.dp),
            shape = RoundedCornerShape(14.dp),
            colors = ButtonDefaults.buttonColors(containerColor = BrandRed, contentColor = Color.White),
        ) { Text("Add funds (crypto)", fontWeight = FontWeight.Bold) }
        Spacer(Modifier.height(8.dp))
        OutlinedButton(onClick = onSite, modifier = Modifier.fillMaxWidth().height(48.dp), shape = RoundedCornerShape(14.dp)) {
            Text("Open smm-turk.com", color = Ink, fontWeight = FontWeight.SemiBold)
        }
        Spacer(Modifier.height(8.dp))
        OutlinedButton(onClick = onLogout, modifier = Modifier.fillMaxWidth().height(48.dp), shape = RoundedCornerShape(14.dp)) {
            Text("Log out", color = BrandRed, fontWeight = FontWeight.SemiBold)
        }
    }
}

@Composable
private fun TextButtonCopy(context: Context, text: String) {
    androidx.compose.material3.TextButton(onClick = {
        val cm = context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
        cm.setPrimaryClip(ClipData.newPlainText("API key", text))
    }) {
        Text("Copy key", color = BrandRed, fontWeight = FontWeight.Bold)
    }
}
