package com.smmturk.app.ui.auth

import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.smmturk.app.R
import com.smmturk.app.ui.components.BrandLogo
import com.smmturk.app.ui.theme.BrandRed
import com.smmturk.app.ui.theme.BrandRedDark
import com.smmturk.app.ui.theme.BrandRedLight
import com.smmturk.app.ui.theme.Ink
import com.smmturk.app.ui.theme.Muted

@Composable
fun AuthScreen(
    loading: Boolean,
    error: String?,
    notice: String?,
    needs2fa: Boolean,
    onLogin: (String, String, String) -> Unit,
    onRegister: (String, String, String) -> Unit,
    onApiKey: (String) -> Unit,
    onGoogle: () -> Unit,
    onDemo: () -> Unit,
) {
    var mode by rememberSaveable { mutableStateOf("login") }
    var email by rememberSaveable { mutableStateOf("") }
    var username by rememberSaveable { mutableStateOf("") }
    var password by rememberSaveable { mutableStateOf("") }
    var totp by rememberSaveable { mutableStateOf("") }
    var apiKey by rememberSaveable { mutableStateOf("") }
    val context = LocalContext.current

    Column(
        Modifier
            .fillMaxSize()
            .background(Color(0xFFFAFAFA))
            .imePadding()
            .verticalScroll(rememberScrollState()),
    ) {
        Box(
            Modifier
                .fillMaxWidth()
                .background(Brush.verticalGradient(listOf(Ink, Color(0xFF2A1016), BrandRedDark)))
                .statusBarsPadding()
                .padding(horizontal = 24.dp, vertical = 36.dp),
        ) {
            Column {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    BrandLogo(size = 64.dp)
                }
                Spacer(Modifier.height(18.dp))
                Row(verticalAlignment = Alignment.Bottom) {
                    Text("SMM ", color = Color.White, fontSize = 28.sp, fontWeight = FontWeight.ExtraBold, letterSpacing = (-0.8).sp)
                    Text("TURK", color = BrandRedLight, fontSize = 28.sp, fontWeight = FontWeight.ExtraBold, letterSpacing = (-0.8).sp)
                }
                Text(
                    "Grow Instagram, TikTok, YouTube & more — from your phone.",
                    color = Color.White.copy(alpha = 0.78f),
                    fontSize = 14.sp,
                    modifier = Modifier.padding(top = 8.dp),
                )
            }
        }

        Column(Modifier.padding(20.dp)) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                ModeChip("Sign in", mode == "login") { mode = "login" }
                ModeChip("Register", mode == "register") { mode = "register" }
                ModeChip("API key", mode == "key") { mode = "key" }
            }

            Spacer(Modifier.height(20.dp))

            if (!error.isNullOrBlank()) {
                Text(error, color = BrandRed, fontSize = 13.sp, modifier = Modifier.padding(bottom = 12.dp))
            }
            if (!notice.isNullOrBlank()) {
                Text(notice, color = Color(0xFF047857), fontSize = 13.sp, modifier = Modifier.padding(bottom = 12.dp))
            }

            if (mode != "key") {
                GoogleAuthButton(
                    label = if (mode == "register") "Sign up with Google" else "Sign in with Google",
                    enabled = !loading,
                    onClick = {
                        onGoogle()
                        GoogleSignInLauncher.start(context)
                    },
                )
                Row(
                    Modifier.fillMaxWidth().padding(vertical = 16.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    HorizontalDivider(Modifier.weight(1f), color = Color(0xFFE5E7EB))
                    Text(
                        if (mode == "register") " or register with email " else " or ",
                        color = Muted,
                        fontSize = 13.sp,
                        fontWeight = FontWeight.SemiBold,
                    )
                    HorizontalDivider(Modifier.weight(1f), color = Color(0xFFE5E7EB))
                }
            }

            val fieldColors = OutlinedTextFieldDefaults.colors(
                focusedBorderColor = BrandRed,
                cursorColor = BrandRed,
                focusedLabelColor = BrandRed,
            )

            if (mode == "register") {
                OutlinedTextField(username, { username = it }, label = { Text("Username") }, singleLine = true, modifier = Modifier.fillMaxWidth(), colors = fieldColors)
                Spacer(Modifier.height(10.dp))
            }
            if (mode != "key") {
                OutlinedTextField(
                    email, { email = it },
                    label = { Text(if (mode == "login") "Email or username" else "Email") },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
                    modifier = Modifier.fillMaxWidth(),
                    colors = fieldColors,
                )
                Spacer(Modifier.height(10.dp))
                OutlinedTextField(
                    password, { password = it },
                    label = { Text("Password") },
                    singleLine = true,
                    visualTransformation = PasswordVisualTransformation(),
                    modifier = Modifier.fillMaxWidth(),
                    colors = fieldColors,
                )
            } else {
                OutlinedTextField(
                    apiKey, { apiKey = it },
                    label = { Text("API key") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                    colors = fieldColors,
                )
                Text(
                    "Find this in Account Settings on the website after you sign in.",
                    color = Muted,
                    fontSize = 12.sp,
                    modifier = Modifier.padding(top = 8.dp),
                )
            }

            if (needs2fa && mode == "login") {
                Spacer(Modifier.height(10.dp))
                OutlinedTextField(
                    totp, { totp = it },
                    label = { Text("Authenticator code") },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                    modifier = Modifier.fillMaxWidth(),
                    colors = fieldColors,
                )
            }

            Spacer(Modifier.height(20.dp))
            Button(
                onClick = {
                    when (mode) {
                        "login" -> onLogin(email, password, totp)
                        "register" -> onRegister(username, email, password)
                        else -> onApiKey(apiKey)
                    }
                },
                enabled = !loading,
                modifier = Modifier.fillMaxWidth().height(52.dp),
                shape = RoundedCornerShape(14.dp),
                colors = ButtonDefaults.buttonColors(containerColor = BrandRed, contentColor = Color.White),
            ) {
                if (loading) {
                    CircularProgressIndicator(Modifier.size(22.dp), color = Color.White, strokeWidth = 2.dp)
                } else {
                    Text(
                        when (mode) {
                            "login" -> "Sign in"
                            "register" -> "Create account"
                            else -> "Continue with API key"
                        },
                        fontWeight = FontWeight.Bold,
                    )
                }
            }

            OutlinedButton(
                onClick = onDemo,
                enabled = !loading,
                modifier = Modifier.fillMaxWidth().padding(top = 10.dp).height(48.dp),
                shape = RoundedCornerShape(14.dp),
            ) {
                Text("Preview the app", fontWeight = FontWeight.SemiBold, color = Ink)
            }

            TextButton(onClick = onDemo, modifier = Modifier.fillMaxWidth()) {
                Text("No account yet? Tour the interface first", color = Muted, textAlign = TextAlign.Center)
            }
        }
    }
}

@Composable
private fun GoogleAuthButton(label: String, enabled: Boolean, onClick: () -> Unit) {
    OutlinedButton(
        onClick = onClick,
        enabled = enabled,
        modifier = Modifier.fillMaxWidth().height(52.dp),
        shape = RoundedCornerShape(12.dp),
        colors = ButtonDefaults.outlinedButtonColors(containerColor = Color.White, contentColor = Ink),
        border = androidx.compose.foundation.BorderStroke(2.dp, Color(0xFFE5E7EB)),
    ) {
        Image(
            painter = painterResource(R.drawable.ic_google),
            contentDescription = null,
            modifier = Modifier.size(20.dp),
        )
        Spacer(Modifier.width(10.dp))
        Text(label, fontWeight = FontWeight.Bold, fontSize = 15.sp)
    }
}

@Composable
private fun ModeChip(label: String, selected: Boolean, onClick: () -> Unit) {
    TextButton(
        onClick = onClick,
        modifier = Modifier
            .clip(RoundedCornerShape(20.dp))
            .background(if (selected) BrandRed else Color(0xFFF0E6E8)),
    ) {
        Text(label, color = if (selected) Color.White else Ink, fontWeight = FontWeight.SemiBold, fontSize = 13.sp)
    }
}
