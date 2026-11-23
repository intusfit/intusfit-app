package br.com.intusfit.treino

import android.util.Log
import androidx.activity.result.ActivityResult
import androidx.health.connect.client.HealthConnectClient
import androidx.health.connect.client.PermissionController
import androidx.health.connect.client.permission.HealthPermission
import androidx.health.connect.client.records.ActiveCaloriesBurnedRecord
import androidx.health.connect.client.records.DistanceRecord
import androidx.health.connect.client.records.ExerciseSessionRecord
import androidx.health.connect.client.records.metadata.Metadata
import androidx.health.connect.client.units.Energy
import androidx.health.connect.client.units.Length
import com.getcapacitor.JSObject
import com.getcapacitor.Plugin
import com.getcapacitor.PluginCall
import com.getcapacitor.PluginMethod
import com.getcapacitor.annotation.ActivityCallback
import com.getcapacitor.annotation.CapacitorPlugin
import java.time.Instant
import java.time.ZoneId
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch

/**
 * Grava no Health Connect os treinos feitos no Intus Fit (sessao de exercicio, distancia e calorias ativas).
 * So escrita. A leitura continua no plugin @capgo/capacitor-health.
 */
@CapacitorPlugin(name = "IntusTreino")
class IntusTreinoPlugin : Plugin() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main.immediate)
    private val contrato = PermissionController.createRequestPermissionResultContract()

    private val permissoes: Set<String> = setOf(
        HealthPermission.getWritePermission(ExerciseSessionRecord::class),
        HealthPermission.getWritePermission(DistanceRecord::class),
        HealthPermission.getWritePermission(ActiveCaloriesBurnedRecord::class)
    )

    private fun cliente(): HealthConnectClient? {
        return try {
            if (HealthConnectClient.getSdkStatus(context) != HealthConnectClient.SDK_AVAILABLE) null
            else HealthConnectClient.getOrCreate(context)
        } catch (e: Exception) {
            null
        }
    }

    private fun tipoDe(t: String): Int = when (t) {
        "musculacao" -> ExerciseSessionRecord.EXERCISE_TYPE_STRENGTH_TRAINING
        "corrida" -> ExerciseSessionRecord.EXERCISE_TYPE_RUNNING
        "caminhada" -> ExerciseSessionRecord.EXERCISE_TYPE_WALKING
        "trilha" -> ExerciseSessionRecord.EXERCISE_TYPE_HIKING
        "bike" -> ExerciseSessionRecord.EXERCISE_TYPE_BIKING
        "natacao" -> ExerciseSessionRecord.EXERCISE_TYPE_SWIMMING_POOL
        "eliptico" -> ExerciseSessionRecord.EXERCISE_TYPE_ELLIPTICAL
        "escada" -> ExerciseSessionRecord.EXERCISE_TYPE_STAIR_CLIMBING
        "danca" -> ExerciseSessionRecord.EXERCISE_TYPE_DANCING
        "luta" -> ExerciseSessionRecord.EXERCISE_TYPE_MARTIAL_ARTS
        "futebol" -> ExerciseSessionRecord.EXERCISE_TYPE_SOCCER
        "raquete" -> ExerciseSessionRecord.EXERCISE_TYPE_TENNIS
        "corda" -> ExerciseSessionRecord.EXERCISE_TYPE_CALISTHENICS
        "casa" -> ExerciseSessionRecord.EXERCISE_TYPE_HIGH_INTENSITY_INTERVAL_TRAINING
        else -> ExerciseSessionRecord.EXERCISE_TYPE_OTHER_WORKOUT
    }

    @PluginMethod
    fun permitido(call: PluginCall) {
        val c = cliente()
        if (c == null) {
            call.resolve(JSObject().put("ok", false))
            return
        }
        scope.launch {
            try {
                val dadas = c.permissionController.getGrantedPermissions()
                call.resolve(JSObject().put("ok", dadas.contains(HealthPermission.getWritePermission(ExerciseSessionRecord::class))))
            } catch (e: Exception) {
                call.resolve(JSObject().put("ok", false))
            }
        }
    }

    @PluginMethod
    fun pedirPermissao(call: PluginCall) {
        val c = cliente()
        if (c == null) {
            call.resolve(JSObject().put("ok", false))
            return
        }
        scope.launch {
            try {
                val dadas = c.permissionController.getGrantedPermissions()
                if (dadas.containsAll(permissoes)) {
                    call.resolve(JSObject().put("ok", true))
                    return@launch
                }
                val intent = contrato.createIntent(context, permissoes)
                startActivityForResult(call, intent, "resultadoPermissao")
            } catch (e: Exception) {
                Log.e("IntusTreino", "permissao", e)
                call.reject(e.message ?: "Falha ao pedir permissao")
            }
        }
    }

    @ActivityCallback
    private fun resultadoPermissao(call: PluginCall?, resultado: ActivityResult) {
        if (call == null) return
        val c = cliente()
        if (c == null) {
            call.resolve(JSObject().put("ok", false))
            return
        }
        scope.launch {
            try {
                val dadas = c.permissionController.getGrantedPermissions()
                call.resolve(JSObject().put("ok", dadas.contains(HealthPermission.getWritePermission(ExerciseSessionRecord::class))))
            } catch (e: Exception) {
                call.resolve(JSObject().put("ok", false))
            }
        }
    }

    @PluginMethod
    fun salvar(call: PluginCall) {
        val id = call.getString("id")
        val tipo = call.getString("tipo")
        val iniStr = call.getString("inicio")
        val fimStr = call.getString("fim")
        if (id == null || tipo == null || iniStr == null || fimStr == null) {
            call.reject("Dados incompletos")
            return
        }
        val c = cliente()
        if (c == null) {
            call.reject("Health Connect indisponivel")
            return
        }
        val ini: Instant
        val fim: Instant
        try {
            ini = Instant.parse(iniStr)
            fim = Instant.parse(fimStr)
        } catch (e: Exception) {
            call.reject("Datas invalidas")
            return
        }
        if (!fim.isAfter(ini)) {
            call.reject("Datas invalidas")
            return
        }
        val kcal = call.getDouble("kcal") ?: 0.0
        val distancia = call.getDouble("distanciaM") ?: 0.0
        val titulo = call.getString("titulo")
        scope.launch {
            try {
                val dadas = c.permissionController.getGrantedPermissions()
                if (!dadas.contains(HealthPermission.getWritePermission(ExerciseSessionRecord::class))) {
                    call.reject("Sem permissao para gravar")
                    return@launch
                }
                val zona = ZoneId.systemDefault().rules.getOffset(ini)
                val zonaFim = ZoneId.systemDefault().rules.getOffset(fim)
                // clientRecordId evita duplicar: gravar de novo o mesmo treino atualiza o registro em vez de criar outro.
                val registros = ArrayList<androidx.health.connect.client.records.Record>()
                registros.add(
                    ExerciseSessionRecord(
                        startTime = ini, startZoneOffset = zona, endTime = fim, endZoneOffset = zonaFim,
                        metadata = Metadata.manualEntry(clientRecordId = "intus-" + id),
                        exerciseType = tipoDe(tipo), title = titulo
                    )
                )
                if (distancia > 0) {
                    registros.add(
                        DistanceRecord(
                            startTime = ini, startZoneOffset = zona, endTime = fim, endZoneOffset = zonaFim,
                            distance = Length.meters(distancia),
                            metadata = Metadata.manualEntry(clientRecordId = "intus-d-" + id)
                        )
                    )
                }
                if (kcal > 0) {
                    registros.add(
                        ActiveCaloriesBurnedRecord(
                            startTime = ini, startZoneOffset = zona, endTime = fim, endZoneOffset = zonaFim,
                            energy = Energy.kilocalories(kcal),
                            metadata = Metadata.manualEntry(clientRecordId = "intus-k-" + id)
                        )
                    )
                }
                c.insertRecords(registros)
                call.resolve(JSObject().put("salvo", true))
            } catch (e: Exception) {
                Log.e("IntusTreino", "salvar", e)
                call.reject(e.message ?: "Falha ao gravar o treino")
            }
        }
    }
}
