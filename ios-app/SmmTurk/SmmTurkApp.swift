import SwiftUI
import WebKit

@main
struct SmmTurkApp: App {
    var body: some Scene {
        WindowGroup {
            AppWebView()
                .ignoresSafeArea()
                .preferredColorScheme(.dark)
                .onOpenURL { url in
                    NotificationCenter.default.post(name: .smmTurkOpenURL, object: url)
                }
        }
    }
}

extension Notification.Name {
    static let smmTurkOpenURL = Notification.Name("smmTurkOpenURL")
}

struct AppWebView: UIViewRepresentable {
    func makeCoordinator() -> Coordinator { Coordinator() }

    func makeUIView(context: Context) -> WKWebView {
        let config = WKWebViewConfiguration()
        config.defaultWebpagePreferences.allowsContentJavaScript = true
        config.websiteDataStore = .default()
        let user = WKUserContentController()
        let js = """
        window.SmmTurkNative = {
          platform: 'ios',
          startGoogle: function(url) { window.webkit.messageHandlers.native.postMessage({type:'google', url:String(url)}); },
          openExternal: function(url) { window.webkit.messageHandlers.native.postMessage({type:'open', url:String(url)}); }
        };
        """
        user.addUserScript(WKUserScript(source: js, injectionTime: .atDocumentStart, forMainFrameOnly: true))
        user.add(context.coordinator, name: "native")
        config.userContentController = user

        let view = WKWebView(frame: .zero, configuration: config)
        view.scrollView.contentInsetAdjustmentBehavior = .never
        view.backgroundColor = UIColor(red: 0.10, green: 0.04, blue: 0.05, alpha: 1)
        view.isOpaque = false
        view.navigationDelegate = context.coordinator
        view.uiDelegate = context.coordinator
        if let url = URL(string: "https://smm-turk.com/m/") {
            view.load(URLRequest(url: url))
        }
        context.coordinator.webView = view
        NotificationCenter.default.addObserver(forName: .smmTurkOpenURL, object: nil, queue: .main) { note in
            if let url = note.object as? URL {
                context.coordinator.applyDeepLink(url)
            }
        }
        return view
    }

    func updateUIView(_ uiView: WKWebView, context: Context) {}

    final class Coordinator: NSObject, WKNavigationDelegate, WKUIDelegate, WKScriptMessageHandler {
        weak var webView: WKWebView?

        func userContentController(_ userContentController: WKUserContentController, didReceive message: WKScriptMessage) {
            guard let body = message.body as? [String: Any], let type = body["type"] as? String, let urlString = body["url"] as? String, let url = URL(string: urlString) else { return }
            UIApplication.shared.open(url)
        }

        func webView(_ webView: WKWebView, decidePolicyFor navigationAction: WKNavigationAction, decisionHandler: @escaping (WKNavigationActionPolicy) -> Void) {
            if let url = navigationAction.request.url, url.scheme == "smmturk" {
                applyDeepLink(url)
                decisionHandler(.cancel)
                return
            }
            decisionHandler(.allow)
        }

        func applyDeepLink(_ url: URL) {
            guard let token = URLComponents(url: url, resolvingAgainstBaseURL: false)?.queryItems?.first(where: { $0.name == "token" })?.value,
                  let dest = URL(string: "https://smm-turk.com/m/?oauth_token=\(token.addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) ?? token)") else { return }
            webView?.load(URLRequest(url: dest))
        }
    }
}
