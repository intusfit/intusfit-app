import Foundation
import Capacitor
import HealthKit

/// Grava no app Saude os treinos feitos no Intus Fit. So escrita: treino, distancia e calorias ativas.
/// A leitura continua no plugin @capgo/capacitor-health.
@objc(IntusTreinoPlugin)
public class IntusTreinoPlugin: CAPPlugin, CAPBridgedPlugin {
    public let identifier = "IntusTreinoPlugin"
    public let jsName = "IntusTreino"
    public let pluginMethods: [CAPPluginMethod] = [
        CAPPluginMethod(name: "pedirPermissao", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "permitido", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "salvar", returnType: CAPPluginReturnPromise)
    ]

    private let store = HKHealthStore()

    private var tiposEscrita: Set<HKSampleType> {
        var s: Set<HKSampleType> = [HKObjectType.workoutType()]
        if let e = HKObjectType.quantityType(forIdentifier: .activeEnergyBurned) { s.insert(e) }
        for id in [HKQuantityTypeIdentifier.distanceWalkingRunning, .distanceCycling, .distanceSwimming] {
            if let d = HKObjectType.quantityType(forIdentifier: id) { s.insert(d) }
        }
        return s
    }

    private func tipoDe(_ t: String) -> (HKWorkoutActivityType, HKQuantityTypeIdentifier?) {
        switch t {
        case "musculacao": return (.traditionalStrengthTraining, nil)
        case "corrida": return (.running, .distanceWalkingRunning)
        case "caminhada": return (.walking, .distanceWalkingRunning)
        case "trilha": return (.hiking, .distanceWalkingRunning)
        case "bike": return (.cycling, .distanceCycling)
        case "natacao": return (.swimming, .distanceSwimming)
        case "eliptico": return (.elliptical, nil)
        case "escada": return (.stairClimbing, nil)
        case "danca": return (.socialDance, nil)
        case "luta": return (.martialArts, nil)
        case "futebol": return (.soccer, nil)
        case "raquete": return (.tennis, nil)
        case "corda": return (.jumpRope, nil)
        case "casa": return (.highIntensityIntervalTraining, nil)
        default: return (.other, nil)
        }
    }

    @objc func permitido(_ call: CAPPluginCall) {
        guard HKHealthStore.isHealthDataAvailable() else { call.resolve(["ok": false]); return }
        let st = store.authorizationStatus(for: HKObjectType.workoutType())
        call.resolve(["ok": st == .sharingAuthorized])
    }

    @objc func pedirPermissao(_ call: CAPPluginCall) {
        guard HKHealthStore.isHealthDataAvailable() else { call.resolve(["ok": false]); return }
        store.requestAuthorization(toShare: tiposEscrita, read: []) { [weak self] _, error in
            if let error = error { call.reject(error.localizedDescription); return }
            let st = self?.store.authorizationStatus(for: HKObjectType.workoutType())
            call.resolve(["ok": st == .sharingAuthorized])
        }
    }

    @objc func salvar(_ call: CAPPluginCall) {
        guard HKHealthStore.isHealthDataAvailable() else { call.reject("Saude indisponivel"); return }
        guard let id = call.getString("id"), let tipoStr = call.getString("tipo"),
              let iniStr = call.getString("inicio"), let fimStr = call.getString("fim") else { call.reject("Dados incompletos"); return }
        let f1 = ISO8601DateFormatter(); f1.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        let f2 = ISO8601DateFormatter(); f2.formatOptions = [.withInternetDateTime]
        guard let ini = f1.date(from: iniStr) ?? f2.date(from: iniStr), let fim = f1.date(from: fimStr) ?? f2.date(from: fimStr), fim > ini else { call.reject("Datas invalidas"); return }
        if store.authorizationStatus(for: HKObjectType.workoutType()) != .sharingAuthorized { call.reject("Sem permissao para gravar"); return }

        let (atividade, idDistancia) = tipoDe(tipoStr)
        let config = HKWorkoutConfiguration(); config.activityType = atividade
        let builder = HKWorkoutBuilder(healthStore: store, configuration: config, device: .local())
        let meta: [String: Any] = [HKMetadataKeyExternalUUID: "intus-" + id, HKMetadataKeySyncIdentifier: "intus-" + id, HKMetadataKeySyncVersion: 1]

        builder.beginCollection(withStart: ini) { ok, err in
            if !ok { call.reject(err?.localizedDescription ?? "Nao iniciou"); return }
            var amostras: [HKSample] = []
            if let kcal = call.getDouble("kcal"), kcal > 0, let t = HKObjectType.quantityType(forIdentifier: .activeEnergyBurned) {
                amostras.append(HKQuantitySample(type: t, quantity: HKQuantity(unit: .kilocalorie(), doubleValue: kcal), start: ini, end: fim))
            }
            if let m = call.getDouble("distanciaM"), m > 0, let idd = idDistancia, let t = HKObjectType.quantityType(forIdentifier: idd) {
                amostras.append(HKQuantitySample(type: t, quantity: HKQuantity(unit: .meter(), doubleValue: m), start: ini, end: fim))
            }
            let terminar = {
                builder.addMetadata(meta) { _, _ in
                    builder.endCollection(withEnd: fim) { _, _ in
                        builder.finishWorkout { w, err in
                            if let err = err { call.reject(err.localizedDescription) }
                            else if w == nil { call.reject("Treino nao gravado") }
                            else { call.resolve(["salvo": true]) }
                        }
                    }
                }
            }
            if amostras.isEmpty { terminar() } else { builder.add(amostras) { _, _ in terminar() } }
        }
    }
}
