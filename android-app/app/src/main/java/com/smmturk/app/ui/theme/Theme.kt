package com.smmturk.app.ui.theme

import android.app.Activity
import android.content.Context
import android.content.ContextWrapper
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.SideEffect
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp
import androidx.core.view.WindowCompat

val BrandRed = Color(0xFFE30A17)
val BrandRedDark = Color(0xFFB90812)
val BrandRedLight = Color(0xFFFF4757)
val Ink = Color(0xFF1A0A0E)
val Muted = Color(0xFF6B4A50)
val PageBg = Color(0xFFFAFAFA)
val CardBorder = Color(0xFFF0E6E8)
val SuccessGreen = Color(0xFF10B981)
val WarnOrange = Color(0xFFF59E0B)

private val LightColors = lightColorScheme(
    primary = BrandRed,
    onPrimary = Color.White,
    primaryContainer = Color(0xFFFFE8EA),
    onPrimaryContainer = BrandRedDark,
    secondary = BrandRedLight,
    background = PageBg,
    surface = Color.White,
    surfaceVariant = Color(0xFFFFF6F7),
    onBackground = Ink,
    onSurface = Ink,
    onSurfaceVariant = Muted,
    outline = CardBorder,
    error = Color(0xFFDC2626),
)

private val AppTypography = Typography(
    headlineLarge = TextStyle(
        fontFamily = FontFamily.SansSerif,
        fontWeight = FontWeight.ExtraBold,
        fontSize = 28.sp,
        letterSpacing = (-0.5).sp,
        color = Ink,
    ),
    headlineMedium = TextStyle(
        fontFamily = FontFamily.SansSerif,
        fontWeight = FontWeight.Bold,
        fontSize = 22.sp,
        color = Ink,
    ),
    titleLarge = TextStyle(
        fontFamily = FontFamily.SansSerif,
        fontWeight = FontWeight.Bold,
        fontSize = 18.sp,
        color = Ink,
    ),
    titleMedium = TextStyle(
        fontFamily = FontFamily.SansSerif,
        fontWeight = FontWeight.SemiBold,
        fontSize = 16.sp,
        color = Ink,
    ),
    bodyLarge = TextStyle(
        fontFamily = FontFamily.SansSerif,
        fontWeight = FontWeight.Normal,
        fontSize = 16.sp,
        color = Ink,
    ),
    bodyMedium = TextStyle(
        fontFamily = FontFamily.SansSerif,
        fontSize = 14.sp,
        color = Ink,
    ),
    labelLarge = TextStyle(
        fontFamily = FontFamily.SansSerif,
        fontWeight = FontWeight.SemiBold,
        fontSize = 13.sp,
        letterSpacing = 0.3.sp,
    ),
)

private tailrec fun findActivity(context: Context): Activity? = when (context) {
    is Activity -> context
    is ContextWrapper -> findActivity(context.baseContext)
    else -> null
}

@Composable
fun SmmTurkTheme(content: @Composable () -> Unit) {
    val view = LocalView.current
    val activity = findActivity(LocalContext.current)
    if (activity != null && !view.isInEditMode) {
        SideEffect {
            val w = activity.window
            WindowCompat.getInsetsController(w, w.decorView).isAppearanceLightStatusBars = true
        }
    }
    MaterialTheme(
        colorScheme = LightColors,
        typography = AppTypography,
        content = content,
    )
}
