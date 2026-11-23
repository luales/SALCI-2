# =====================================================================
# SALCI · Comprobación rápida de los RNF01-RNF06 en cualquier SQL Express
#
# Uso:
#   powershell -ExecutionPolicy Bypass -File verificar_rnf.ps1
#   powershell -ExecutionPolicy Bypass -File verificar_rnf.ps1 -ServerInstance '.\SQLEXPRESS' -Database SALCI
#
# Si no se indican -ServerInstance/-Database, se leen de app/config.local.php
# (o de app/config.php). Si no hay DSN, se prueban las instancias habituales
# (.\SQLEXPRESS, .\SQLEXPRESS01, (local)\SQLEXPRESS, ...) y se elige la primera
# base de usuario disponible.
#
# Requiere: Apache/XAMPP corriendo, PHP y sqlcmd en el PATH.
# =====================================================================

param(
    [string] $ServerInstance = '',
    [string] $Database = '',
    [string] $BaseUrl = 'http://localhost/salci',
    [int] $LoginTimeoutSec = 5
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8   # curl.exe devuelve UTF-8

$repo = $PSScriptRoot
$ok   = 0
$mal  = 0

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

function Leer-Dsn {
    $rutas = @(
        (Join-Path $repo 'app\config.local.php'),
        (Join-Path $repo 'app\config.php')
    )
    # Solo acepta define(...) al inicio de línea: ignora comentarios y plantillas.
    $regex = "(?m)^\s*(?:defined\([^)]*\)\s*\|\|\s*)?define\(\s*['`"]DB_DSN['`"]\s*,\s*['`"](?<dsn>[^'`"]+)['`"]"

    foreach ($ruta in $rutas) {
        if (-not (Test-Path $ruta)) { continue }
        $contenido = Get-Content $ruta -Raw
        $m = [regex]::Match($contenido, $regex)
        if (-not $m.Success) { continue }

        $dsn = $m.Groups['dsn'].Value -replace '\\\\', '\'
        $s = [regex]::Match($dsn, 'Server=([^;]+)')
        $d = [regex]::Match($dsn, 'Database=([^;]+)')

        return @{
            Server   = if ($s.Success) { $s.Groups[1].Value.Trim() } else { '' }
            Database = if ($d.Success) { $d.Groups[1].Value.Trim() } else { '' }
        }
    }

    return @{ Server = ''; Database = '' }
}

function Test-SqlConexion {
    param([string]$Server, [string]$Db = '')

    if (-not (Get-Command sqlcmd -ErrorAction SilentlyContinue)) {
        throw 'sqlcmd no está en el PATH. Instala las herramientas de SQL Server Express.'
    }

    $comun = @('-S', $Server, '-E', '-C', '-l', "$LoginTimeoutSec", '-b')
    if ($Db) {
        $argumentos = $comun + @('-d', $Db, '-Q', 'SET NOCOUNT ON; SELECT DB_ID()')
    } else {
        $argumentos = $comun + @('-Q', 'SET NOCOUNT ON; SELECT 1')
    }

    $anterior = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $null = & sqlcmd @argumentos 2>&1
        $codigo = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $anterior
    }

    return ($codigo -eq 0)
}

function Obtener-Bases {
    param([string]$Server)

    $sql = "SET NOCOUNT ON; SELECT name FROM sys.databases WHERE name NOT IN ('master','tempdb','model','msdb') ORDER BY name"
    $anterior = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $salida = & sqlcmd -S $Server -E -C -l "$LoginTimeoutSec" -W -h -1 -Q $sql 2>$null
    } finally {
        $ErrorActionPreference = $anterior
    }

    return @($salida | ForEach-Object { $_.ToString().Trim() } |
        Where-Object { $_ -and $_ -notmatch '^[-]+$' })
}

Write-Host "`nSALCI · Verificación de requerimientos no funcionales`n" -ForegroundColor Cyan

# ---------- Resolución automática de instancia y base ----------
$serverFijado = $PSBoundParameters.ContainsKey('ServerInstance') -and $ServerInstance -ne ''
$dbFijada     = $PSBoundParameters.ContainsKey('Database') -and $Database -ne ''

$dsn = Leer-Dsn
if (-not $ServerInstance) { $ServerInstance = $dsn.Server }
if (-not $Database)       { $Database = $dsn.Database }

# El DSN de config.php es sólo un valor por defecto: si no responde en este
# equipo, se buscan otras instancias en lugar de dar por buena la configuración.
if ($ServerInstance -and -not $serverFijado -and -not (Test-SqlConexion $ServerInstance)) {
    Write-Host "La instancia del DSN ($ServerInstance) no responde; buscando otra..." -ForegroundColor Yellow
    $ServerInstance = ''
}

if (-not $ServerInstance) {
    Write-Host 'Buscando instancias habituales de SQL Express...' -ForegroundColor Yellow
    foreach ($candidata in @('.\SQLEXPRESS', '.\SQLEXPRESS01', '(local)\SQLEXPRESS',
                             'localhost\SQLEXPRESS', 'SQLEXPRESS')) {
        if (Test-SqlConexion $candidata) {
            $ServerInstance = $candidata
            break
        }
    }
}

if (-not $ServerInstance) {
    Write-Host 'No se pudo detectar la instancia de SQL Server.' -ForegroundColor Red
    Write-Host "Indícala con -ServerInstance, por ejemplo: -ServerInstance '.\SQLEXPRESS'`n" -ForegroundColor Red
    exit 1
}

if ($Database -and -not $dbFijada -and -not (Test-SqlConexion $ServerInstance $Database)) {
    Write-Host "La base del DSN ($Database) no existe en $ServerInstance; buscando otra..." -ForegroundColor Yellow
    $Database = ''
}

if (-not $Database) {
    foreach ($candidata in @('SALCI', 'prueba')) {
        if (Test-SqlConexion $ServerInstance $candidata) {
            $Database = $candidata
            break
        }
    }
    if (-not $Database) {
        $bases = @(Obtener-Bases $ServerInstance)
        if ($bases.Count -gt 0) { $Database = $bases[0] }
    }
}

if (-not $Database) {
    Write-Host "Se detectó $ServerInstance pero no hay una base SALCI/prueba visible." -ForegroundColor Red
    Write-Host 'Indica la base con -Database.<nombre>`n' -ForegroundColor Red
    exit 1
}

Write-Host "Instancia: $ServerInstance | Base: $Database | URL: $BaseUrl`n" -ForegroundColor DarkGray

# ---------- RNF01: cabecera de tiempo de respuesta ----------
try {
    $login = Obtener "$BaseUrl/login.php"
    $rt = $login.Headers['X-Response-Time-Ms']
    $ms = 0.0
    $esNumerico = [double]::TryParse($rt, [ref]$ms)
    Informar ($esNumerico -and $ms -lt 500) 'RNF01' `
        "login.php devuelve X-Response-Time-Ms = $rt ms (objetivo < 500)"
} catch {
    Informar $false 'RNF01' "No responde $BaseUrl/login.php : $($_.Exception.Message)"
}

# ---------- RNF04: endpoint de monitoreo ----------
try {
    $health = Obtener "$BaseUrl/health.php"
    $json = $health.Content | ConvertFrom-Json
    Informar ($health.StatusCode -eq 200 -and $json.status -eq 'ok' -and $json.checks.database.name -eq $Database) 'RNF04' `
        "health.php → 200, status=$($json.status), base=$($json.checks.database.name), PHP $($json.checks.php.version)"
} catch {
    Informar $false 'RNF04' "health.php falló: $($_.Exception.Message)"
}

# ---------- RNF03: login, hash salted y JWT ----------
try {
    $s = $null
    $g = Invoke-WebRequest "$BaseUrl/login.php" -SessionVariable s -UseBasicParsing -TimeoutSec 30
    $csrf = [regex]::Match($g.Content, 'name="csrf" value="([^"]+)"').Groups[1].Value
    $null = Invoke-WebRequest "$BaseUrl/login.php" -Method Post `
        -Body @{ csrf = $csrf; username = 'admin'; password = 'Salci2026!' } `
        -WebSession $s -UseBasicParsing -TimeoutSec 30
    $panel = Obtener "$BaseUrl/index.php" $s
    $token = ($panel.Content -match 'firmada') -and ($panel.Content -match 'expira')
    Informar ($panel.Content -match 'Hola admin' -and $token) 'RNF03' `
        'admin autenticado con SHA-256+sal; panel valida el JWT HS256 de 24 h'
} catch {
    Informar $false 'RNF03' "Login/JWT falló: $($_.Exception.Message)"
}

# ---------- RNF06: tema slate-blue y errores con causa/accion ----------
try {
    $css = (Obtener "$BaseUrl/assets/style.css").Content
    $js  = (Obtener "$BaseUrl/assets/tema.js").Content
    $pag = (Obtener "$BaseUrl/login.php").Content
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
    $cuerpo = & curl.exe -s -w "`nHTTP:%{http_code}" "$BaseUrl/__rnf_check_tmp.php" 2>&1 | Out-String
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
    $dbSeguro = $Database.Replace(']', ']]')
    $sql = (Get-Content $rutaSql -Raw).Replace('USE SALCI;', "USE [$dbSeguro];")
    $tmpSql = Join-Path $env:TEMP 'salci_verificar_rnf.sql'
    $sql | Set-Content $tmpSql -Encoding utf8

    $salida = sqlcmd -S $ServerInstance -E -C -b -f 65001 -i $tmpSql 2>&1 | Out-String
    $revisar = ($salida -split "`n" | Where-Object { $_ -match 'REVISAR' }).Count
    $filasOk = ($salida -split "`n" | Where-Object { $_ -match '\bOK\b' }).Count
    Informar ($LASTEXITCODE -eq 0 -and $revisar -eq 0 -and $filasOk -ge 6) 'RNF02/RNF05' `
        "SQL en ${Database}: $filasOk comprobaciones OK, $revisar a revisar (FK, CHECK, índices)"
} catch {
    Informar $false 'RNF02/RNF05' "sqlcmd falló en $ServerInstance/$Database : $($_.Exception.Message)"
}

# ---------- RF13: auditoría automática en BD ----------
try {
    $q = "SET NOCOUNT ON; SELECT (SELECT COUNT(*) FROM sys.triggers WHERE name LIKE 'trg_aud_%') AS triggers, (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='registro_alertas' AND COLUMN_NAME IN ('created_at','created_by','updated_at','updated_by')) AS columnas;"
    $salida = sqlcmd -S $ServerInstance -E -C -W -d $Database -Q $q 2>&1 | Out-String
    $nums = [regex]::Matches($salida, '\d+') | ForEach-Object { [int]$_.Value }
    $triggers = if ($nums.Count -ge 2) { $nums[$nums.Count - 2] } else { 0 }
    $columnas = if ($nums.Count -ge 1) { $nums[$nums.Count - 1] } else { 0 }
    Informar ($triggers -ge 12 -and $columnas -eq 4) 'RF13' `
        "SQL en ${Database}: $triggers triggers AFTER UPDATE y $columnas/4 columnas en registro_alertas"
} catch {
    Informar $false 'RF13' "No se pudo verificar la auditoría: $($_.Exception.Message)"
}

Write-Host "`nResultado: $ok correctos, $mal a revisar`n" -ForegroundColor Cyan
if ($mal -gt 0) { exit 1 }
