import UIKit
import AVFoundation
import Capacitor

@UIApplicationMain
class AppDelegate: UIResponder, UIApplicationDelegate {

    var window: UIWindow?

    func application(_ application: UIApplication, didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?) -> Bool {
        // Override point for customization after application launch.
        configurarAudio()
        return true
    }

    // Programas de cardio: os bips e a voz precisam tocar POR CIMA da música do aluno (Spotify etc.) e mesmo com o botão lateral de silêncio ligado.
    // A categoria .playback ignora o botão de silêncio e a opção .mixWithOthers deixa os outros apps tocando. Com o modo de áudio em segundo plano
    // ("audio" no Info.plist) e o áudio da página rodando, o app continua vivo com a tela bloqueada.
    private func configurarAudio() {
        do {
            let sessao = AVAudioSession.sharedInstance()
            try sessao.setCategory(.playback, mode: .default, options: [.mixWithOthers])
            try sessao.setActive(true)
        } catch {
            print("Intus: nao consegui configurar a sessao de audio: \(error)")
        }
    }

    func applicationWillResignActive(_ application: UIApplication) {
        // Sent when the application is about to move from active to inactive state. This can occur for certain types of temporary interruptions (such as an incoming phone call or SMS message) or when the user quits the application and it begins the transition to the background state.
        // Use this method to pause ongoing tasks, disable timers, and invalidate graphics rendering callbacks. Games should use this method to pause the game.
    }

    func applicationDidEnterBackground(_ application: UIApplication) {
        // Use this method to release shared resources, save user data, invalidate timers, and store enough application state information to restore your application to its current state in case it is terminated later.
        // If your application supports background execution, this method is called instead of applicationWillTerminate: when the user quits.
    }

    func applicationWillEnterForeground(_ application: UIApplication) {
        // Called as part of the transition from the background to the active state; here you can undo many of the changes made on entering the background.
    }

    func applicationDidBecomeActive(_ application: UIApplication) {
        // Restart any tasks that were paused (or not yet started) while the application was inactive. If the application was previously in the background, optionally refresh the user interface.
        configurarAudio()   // o sistema pode ter desfeito a sessão (ligação, outro app): refaz
    }

    func applicationWillTerminate(_ application: UIApplication) {
        // Called when the application is about to terminate. Save data if appropriate. See also applicationDidEnterBackground:.
    }

    func application(_ application: UIApplication,
                     configurationForConnecting connectingSceneSession: UISceneSession,
                     options: UIScene.ConnectionOptions) -> UISceneConfiguration {
        let config = UISceneConfiguration(name: "Default Configuration",
                                          sessionRole: connectingSceneSession.role)
        config.delegateClass = SceneDelegate.self
        return config
    }
}
