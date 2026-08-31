package com.smmturk.app

import android.annotation.SuppressLint
import android.content.ActivityNotFoundException
import android.content.Intent
import android.graphics.Color
import android.net.Uri
import android.os.Bundle
import android.webkit.CookieManager
import android.webkit.JavascriptInterface
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.FrameLayout
import androidx.activity.OnBackPressedCallback
import androidx.appcompat.app.AppCompatActivity
import androidx.browser.customtabs.CustomTabsIntent
import androidx.core.view.WindowCompat

class NativeBridge(private val activity: MainActivity) {
    @JavascriptInterface
    fun startGoogle(url: String) {
        activity.runOnUiThread { activity.openCustomTab(url) }
    }

    @JavascriptInterface
    fun openExternal(url: String) {
        activity.runOnUiThread { activity.openCustomTab(url) }
    }
}

class MainActivity : AppCompatActivity() {
    private lateinit var webView: WebView

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        WindowCompat.setDecorFitsSystemWindows(window, false)
        window.statusBarColor = Color.parseColor("#E30A17")
        window.navigationBarColor = Color.parseColor("#1a0a0e")

        webView = WebView(this).apply {
            layoutParams = FrameLayout.LayoutParams(
                FrameLayout.LayoutParams.MATCH_PARENT,
                FrameLayout.LayoutParams.MATCH_PARENT
            )
            setBackgroundColor(Color.parseColor("#1a0a0e"))
            settings.javaScriptEnabled = true
            settings.domStorageEnabled = true
            settings.cacheMode = WebSettings.LOAD_DEFAULT
            settings.mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
            settings.userAgentString = settings.userAgentString + " SmmTurkApp/1.0 Android"
            addJavascriptInterface(NativeBridge(this@MainActivity), "Android")
            webChromeClient = WebChromeClient()
            webViewClient = object : WebViewClient() {
                override fun shouldOverrideUrlLoading(view: WebView?, request: WebResourceRequest?): Boolean {
                    val uri = request?.url ?: return false
                    return handleExternal(uri)
                }

                override fun onPageFinished(view: WebView?, url: String?) {
                    view?.evaluateJavascript(BRIDGE_JS, null)
                }

                override fun onReceivedError(
                    view: WebView?,
                    request: WebResourceRequest?,
                    error: WebResourceError?
                ) {
                    if (request?.isForMainFrame == true) {
                        view?.loadDataWithBaseURL(
                            null,
                            ERROR_HTML,
                            "text/html",
                            "UTF-8",
                            null
                        )
                    }
                }
            }
            CookieManager.getInstance().setAcceptCookie(true)
            CookieManager.getInstance().setAcceptThirdPartyCookies(this, true)
        }
        setContentView(webView)

        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                if (webView.canGoBack()) webView.goBack() else finish()
            }
        })

        loadFromIntent(intent)
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        loadFromIntent(intent)
    }

    private fun loadFromIntent(intent: Intent) {
        val data = intent.data
        if (data != null && data.scheme == "smmturk") {
            val token = data.getQueryParameter("token").orEmpty()
            val dest = if (token.isNotEmpty()) {
                BuildConfig.APP_URL.trimEnd('/') + "/?oauth_token=" + Uri.encode(token)
            } else {
                BuildConfig.APP_URL
            }
            webView.loadUrl(dest)
            return
        }
        webView.loadUrl(BuildConfig.APP_URL)
    }

    fun openCustomTab(url: String) {
        val uri = Uri.parse(url)
        try {
            CustomTabsIntent.Builder().setShowTitle(true).build().launchUrl(this, uri)
        } catch (_: ActivityNotFoundException) {
            startActivity(Intent(Intent.ACTION_VIEW, uri))
        }
    }

    private fun handleExternal(uri: Uri): Boolean {
        val host = uri.host.orEmpty()
        val scheme = uri.scheme.orEmpty()
        if (scheme == "smmturk") {
            loadFromIntent(Intent(Intent.ACTION_VIEW, uri))
            return true
        }
        if (scheme == "http" || scheme == "https") {
            val appHost = Uri.parse(BuildConfig.APP_URL).host.orEmpty()
            if (host == appHost || host.endsWith(".google.com") || host == "accounts.google.com") {
                return false
            }
            openCustomTab(uri.toString())
            return true
        }
        return false
    }

    companion object {
        private const val BRIDGE_JS = """
            window.SmmTurkNative = {
              platform: 'android',
              startGoogle: function(url) { Android.startGoogle(String(url)); },
              openExternal: function(url) { Android.openExternal(String(url)); }
            };
        """
        private const val ERROR_HTML = """
            <html><body style="font-family:sans-serif;background:#1a0a0e;color:#fff;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;text-align:center;padding:24px">
            <div><h1 style="color:#E30A17">Connection needed</h1><p>SMM Turk needs internet to load your panel. Check the network and try again.</p></div>
            </body></html>
        """
    }
}
