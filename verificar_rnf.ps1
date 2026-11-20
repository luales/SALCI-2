# =====================================================================
# SALCI · Comprobación rápida de los RNF01-RNF06 en este equipo
# Uso:  powershell -ExecutionPolicy Bypass -File verificar_rnf.ps1
# Requiere: Apache/XAMPP corriendo y SQL Server .\SQLEXPRESS01 activo.
# =====================================================================

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8   # curl.exe devuelve UTF-8

$base      = 'http://localhost/salci'
$instancia = '.\SQLEXPRESS01'
$db        = 'prueba'
$repo      = $PSScriptRoot
$ok        = 0
$mal       = 0

function Informar([bool]$pasa, [string]$id, [string]$mensaje) {
    if ($pasa) {
        $script:ok++
        Write-Host ("  OK    [{0}] {1}" -f $id, $mensaje) -ForegroundColor Green
    } else {
        $script:mal++
        Write-Host ("  REVISAR [{0}] {1}" -f $id, $mensaje) -ForegroundColor Red
    }
}

function Obtener([string]$url, $session = $null) {
    if ($session) {
        return Invoke-WebRequest $url -WebSession $session -UseBasicParsing -TimeoutSec 30
    }
    return Invoke-WebRequest $url -UseBasicParsing -TimeoutSec 30
}

Write-Host "`nSALCI · Verificación de requerimientos no funcionales`n" -ForegroundColor Cyan

# ---------- RNF01: cabecera de tiempo de respuesta ----------
try {
    $login = Obtener "$base/login.php"
    $rt = $login.Headers['X-Response-Time-Ms']
    $ms = 0.0
    $esNumerico = [double]::TryParse($rt, [ref]$ms)
    Informar ($esNumerico -and $ms -lt 500) 'RNF01' `
        "login.php devuelve X-Response-Time-Ms = $rt ms (objetivo < 500)"
} catch {
    Informar $false 'RNF01' "No responde $base/login.php : $($_.Exception.Message)"
}

# ---------- RNF04: endpoint de monitoreo ----------
try {
    $health = Obtener "$base/health.php"
    $json = $health.Content | ConvertFrom-Json
    Informar ($health.StatusCode -eq 200 -and $json.status -eq 'ok' -and $json.checks.database.name -eq $db) 'RNF04' `
        "health.php → 200, status=$($json.status), base=$($json.checks.database.name), PHP $($json.checks.php.version)"
} catch {
    Informar $false 'RNF04' "health.php falló: $($_.Exception.Message)"
}

# ---------- RNF03: login, hash salted y JWT ----------
try {
    $s = $null
    $g = Invoke-WebRequest "$base/login.php" -SessionVariable s -UseBasicParsing -TimeoutSec 30
    $csrf = [regex]::Match($g.Content, 'name="csrf" value="([^"]+)"').Groups[1].Value
    $null = Invoke-WebRequest "$base/login.php" -Method Post `
        -Body @{ csrf = $csrf; username = 'admin'; password = 'Salci2026!' } `
        -WebSession $s -UseBasicParsing -TimeoutSec 30
    $panel = Obtener "$base/index.php" $s
    $token = $panel.Content -match 'Token RF05 \(JWT HS256\)'
    Informar ($panel.Content -match 'Hola admin' -and $token) 'RNF03' `
        'admin autenticado con SHA-256+sal; panel valida el JWT HS256 de 24 h'
} catch {
    Informar $false 'RNF03' "Login/JWT falló: $($_.Exception.Message)"
}

# ---------- RNF06: tema slate-blue y errores con causa/accion ----------
try {
    $css = (Obtener "$base/assets/style.css").Content
    $js  = (Obtener "$base/assets/tema.js").Content
    $pag = (Obtener "$base/login.php").Content
    $tema_ok = $css -match 'data-tema="claro"' -and $css -match '#0b1220' `
        -and $js -match 'salci_tema' -and $pag -match 'data-tema-toggle'
    Informar $tema_ok 'RNF06' 'CSS slate-blue + conmutador de tema persistente servidos'
} catch {
    Informar $false 'RNF06' "No se pudo verificar el tema: $($_.Exception.Message)"
}

$tmpError = Join-Path $repo 'app\__rnf_check_tmp.php'
try {
    $contenido = @('<?php', 'declare(strict_types=1);',
        "require_once __DIR__ . '/config.php';",
        "throw new RuntimeException('Prueba RNF06');") -join "`n"
    # Sin BOM: un BOM delante de <?php ensuciaría la salida.
    [System.IO.File]::WriteAllText($tmpError, $contenido, (New-Object System.Text.UTF8Encoding($false)))

    # curl.exe lee el cuerpo incluso cuando el servidor devuelve 500.
    $cuerpo = & curl.exe -s -w "`nHTTP:%{http_code}" "$base/__rnf_check_tmp.php" 2>&1 | Out-String
    Informar ($cuerpo -match 'Causa:' -and $cuerpo -match 'Acción correctiva' -and $cuerpo -match 'HTTP:500') 'RNF06' `
        'Error no atrapado muestra Causa + Acción correctiva (HTTP 500)'
} catch {
    Informar $false 'RNF06' "Prueba de error falló: $($_.Exception.Message)"
} finally {
    if (Test-Path $tmpError) { Remove-Item $tmpError -Force }
}

# ---------- RNF02 y RNF05: FK/CHECK e indices B-Tree ----------
try {
    $rutaSql = Join-Path $repo 'db\10_verificacion_rnf.sql'
    $sql = (Get-Content $rutaSql -Raw) -replace 'USE SALCI;', "USE $db;"
    $tmpSql = Join-Path $env:TEMP 'salci_verificar_rnf.sql'
    $sql | Set-Content $tmpSql -Encoding utf8

    $salida = sqlcmd -S $instancia -E -C -b -f 65001 -i $tmpSql 2>&1 | Out-String
    $revisar = ($salida -split "`n" | Where-Object { $_ -match 'REVISAR' }).Count
    $filasOk = ($salida -split "`n" | Where-Object { $_ -match '\bOK\b' }).Count
    Informar ($LASTEXITCODE -eq 0 -and $revisar -eq 0 -and $filasOk -ge 6) 'RNF02/RNF05' `
        "SQL: $filasOk comprobaciones OK, $revisar a revisar (FK, CHECK, índices sku/username/sucursal_id)"
} catch {
    Informar $false 'RNF02/RNF05' "sqlcmd falló: $($_.Exception.Message)"
}

# ---------- RF13: auditoría automática en BD ----------
try {
    $q = "SET NOCOUNT ON; SELECT (SELECT COUNT(*) FROM sys.triggers WHERE name LIKE 'trg_aud_%') AS triggers, (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='registro_alertas' AND COLUMN_NAME IN ('created_at','created_by','updated_at','updated_by')) AS columnas;"
    $salida = sqlcmd -S $instancia -E -C -W -d $db -Q $q 2>&1 | Out-String
    $nums = [regex]::Matches($salida, '\d+') | ForEach-Object { [int]$_.Value }
    $triggers = if ($nums.Count -ge 2) { $nums[$nums.Count - 2] } else { 0 }
    $columnas = if ($nums.Count -ge 1) { $nums[$nums.Count - 1] } else { 0 }
    Informar ($triggers -ge 12 -and $columnas -eq 4) 'RF13' `
        "SQL: $triggers triggers AFTER UPDATE y $columnas/4 columnas en registro_alertas"
} catch {
    Informar $false 'RF13' "No se pudo verificar la auditoría: $($_.Exception.Message)"
}

Write-Host "`nResultado: $ok correctos, $mal a revisar`n" -ForegroundColor Cyan
if ($mal -gt 0) { exit 1 }
