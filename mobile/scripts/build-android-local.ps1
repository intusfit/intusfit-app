# Gera o AAB assinado do Android no proprio computador (sem gastar minutos do Codemagic).
# Uso (PowerShell, dentro da pasta mobile):  .\scripts\build-android-local.ps1
# A senha da chave e pedida na hora, nao fica salva em lugar nenhum: o keystore.properties
# (ignorado pelo Git) e criado so durante o build e apagado no fim.
$ErrorActionPreference = 'Stop'
$mobile = Split-Path -Parent $PSScriptRoot
$jks = 'C:/Users/User/IntusChaves/intus-upload.jks'
if (-not (Test-Path $jks)) { throw "Chave nao encontrada em $jks" }
$sec = Read-Host 'Senha da chave de envio (intus-upload)' -AsSecureString
$senha = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec))
$props = Join-Path $mobile 'android\keystore.properties'
Push-Location $mobile
try {
  npm run build:web
  npx cap sync android
  Set-Content -Path $props -Encoding ascii -Value @(
    "storeFile=$jks", "storePassword=$senha", "keyAlias=intus-upload", "keyPassword=$senha")
  # Codigo de versao: minutos desde 1970 (mesma regra do Codemagic; o Play exige um numero maior a cada envio)
  $env:ANDROID_VERSION_CODE = [string][int]([DateTimeOffset]::UtcNow.ToUnixTimeSeconds() / 60)
  Push-Location android
  .\gradlew.bat bundleRelease
  Pop-Location
  $aab = Join-Path $mobile 'android\app\build\outputs\bundle\release\app-release.aab'
  if (Test-Path $aab) { Write-Host "`nPRONTO: $aab" -ForegroundColor Green } else { throw 'AAB nao foi gerado' }
} finally {
  Pop-Location
  if (Test-Path $props) { Remove-Item $props -Force }
}
