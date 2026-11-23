import UIKit
import Capacitor

class SceneDelegate: UIResponder, UIWindowSceneDelegate {
    var window: UIWindow?

    func scene(_ scene: UIScene, willConnectTo session: UISceneSession, options connectionOptions: UIScene.ConnectionOptions) {
        guard let windowScene = scene as? UIWindowScene else { return }

        window = UIWindow(windowScene: windowScene)
        let controlador = CAPBridgeViewController()
        window?.rootViewController = controlador
        window?.makeKeyAndVisible()
        // Gesto de deslizar da borda da tela para voltar: o WKWebView não o liga sozinho. O app navega com history.pushState e trata o popstate,
        // então o gesto desfaz o último passo (tela, folha) como o botão de voltar do Android.
        DispatchQueue.main.async { controlador.webView?.allowsBackForwardNavigationGestures = true }

        SceneDelegateProxy.shared.scene(scene, willConnectTo: session, options: connectionOptions)
    }

    func scene(_ scene: UIScene, openURLContexts URLContexts: Set<UIOpenURLContext>) {
        SceneDelegateProxy.shared.scene(scene, openURLContexts: URLContexts)
    }

    func scene(_ scene: UIScene, continue userActivity: NSUserActivity) {
        SceneDelegateProxy.shared.scene(scene, continue: userActivity)
    }
}
