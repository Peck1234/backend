# care URU backend - one-time setup for the university Windows server.
#
# Run in PowerShell as Administrator, from the extracted bundle folder:
#   powershell -NoProfile -ExecutionPolicy Bypass -File .\setup.ps1
#
# The bundle folder (not in git) holds, next to this script and import-db.php:
#   backend\         this repo, including vendor\ and .env
#   php\             a Windows PHP 7.4 build (XAMPP's), php.ini paths set to C:\medcart\php
#   ngrok\ngrok.exe  + ngrok\ngrok.yml (contains the ngrok authtoken - never commit)
#   medication.sql   mysqldump of the data to load (patient data - never commit)
# backup.ps1 / setup-backup.ps1 / restore-drill.ps1 in this folder set up the
# hourly backups once this has run.
#
# Safe to run again: it never wipes the database (import is skipped once the
# database has tables), and it replaces its own scheduled tasks.
#
# What it does:
#   1. Copies this bundle to C:\medcart
#   2. Makes sure PHP runs (installs the Visual C++ runtime if missing)
#   3. Creates the `medication` database on this server's MySQL and loads data
#   4. Points the backend's .env at this server's MySQL
#   5. Registers two tasks that start automatically with Windows, no login needed:
#        MedCart-Backend  - Laravel on http://127.0.0.1:8000 (local only)
#        MedCart-Tunnel   - ngrok, publishes it as https://fling-reappear-reshape.ngrok-free.dev
#   6. Checks both work
#
# IIS and everything else already on this server are left untouched.

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$Root      = 'C:\medcart'
$PublicUrl = 'https://fling-reappear-reshape.ngrok-free.dev'
$Php       = "$Root\php\php.exe"
$PhpIni    = "$Root\php\php.ini"

function Section($t) { Write-Host ""; Write-Host "== $t ==" -ForegroundColor Cyan }
function Ok($m)      { Write-Host "  OK: $m" -ForegroundColor Green }
function Bad($m)     { Write-Host "  FAILED: $m" -ForegroundColor Red }
function Info($m)    { Write-Host "  $m" -ForegroundColor Yellow }

$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Bad "Run PowerShell as Administrator (right-click PowerShell > Run as administrator), then run this script again."
    exit 1
}

# ---------------------------------------------------------------------------
Section "1. Copy files to $Root"
if ((Resolve-Path $PSScriptRoot).Path.TrimEnd('\') -ne $Root) {
    robocopy $PSScriptRoot $Root /E /NFL /NDL /NJH /NJS /NP | Out-Null
    if ($LASTEXITCODE -ge 8) { Bad "copy failed (robocopy exit $LASTEXITCODE)"; exit 1 }
}
New-Item -ItemType Directory -Force "$Root\tmp", "$Root\logs" | Out-Null
Ok "files are in $Root"

# ---------------------------------------------------------------------------
Section "2. PHP"
function Test-Php {
    $ErrorActionPreference = 'Continue'
    try { (& $Php -c $PhpIni -r "echo 'PHP_RUNS_OK';" 2>&1 | Out-String) -match 'PHP_RUNS_OK' } catch { $false }
}
if (-not (Test-Php)) {
    Info "PHP cannot start yet - installing the Microsoft Visual C++ runtime it needs..."
    $vc = "$Root\tmp\vc_redist.x64.exe"
    Invoke-WebRequest -Uri 'https://aka.ms/vs/17/release/vc_redist.x64.exe' -OutFile $vc -UseBasicParsing
    Start-Process -FilePath $vc -ArgumentList '/install', '/quiet', '/norestart' -Wait
}
if (-not (Test-Php)) { Bad "PHP still does not start. Send a screenshot of: & '$Php' -v"; exit 1 }
$phpVer = & $Php -c $PhpIni -r "echo PHP_VERSION;"
$exts = & $Php -c $PhpIni -r "echo implode(',', array_filter(['pdo_mysql','mbstring','openssl','fileinfo','curl'], fn(`$e) => !extension_loaded(`$e)));"
if ($exts) { Bad "PHP is missing extensions: $exts"; exit 1 }
Ok "PHP $phpVer with all required extensions"

# ---------------------------------------------------------------------------
Section "3. Database"
$sec = Read-Host "  Enter this server's MySQL root password" -AsSecureString
$dbPass = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec))
$env:MEDCART_DB_PASS = $dbPass
& $Php -c $PhpIni "$Root\import-db.php" "$Root\medication.sql"
$importExit = $LASTEXITCODE
Remove-Item Env:\MEDCART_DB_PASS
if ($importExit -ne 0) { Bad "database step failed (see message above). Wrong password, or MySQL not running on port 3306?"; exit 1 }

# ---------------------------------------------------------------------------
Section "4. Backend settings (.env)"
$envFile = "$Root\backend\.env"
$quotedPass = if ($dbPass -match "'") { '"' + $dbPass + '"' } else { "'" + $dbPass + "'" }
$settings = [ordered]@{
    APP_ENV     = 'production'
    APP_DEBUG   = 'false'
    APP_URL     = $PublicUrl
    DB_HOST     = '127.0.0.1'
    DB_PORT     = '3306'
    DB_DATABASE = 'medication'
    DB_USERNAME = 'root'
    DB_PASSWORD = $quotedPass
}
$lines = [IO.File]::ReadAllLines($envFile)
foreach ($key in $settings.Keys) {
    $found = $false
    for ($i = 0; $i -lt $lines.Length; $i++) {
        if ($lines[$i] -match "^$key=") { $lines[$i] = "$key=$($settings[$key])"; $found = $true }
    }
    if (-not $found) { $lines += "$key=$($settings[$key])" }
}
# Written without a BOM - a BOM makes Laravel misread the first line.
[IO.File]::WriteAllLines($envFile, $lines, (New-Object Text.UTF8Encoding $false))
$dbPass = $null

Push-Location "$Root\backend"
& $Php -c $PhpIni artisan config:clear | Out-Null
& $Php -c $PhpIni artisan route:clear | Out-Null
& $Php -c $PhpIni artisan view:clear | Out-Null
if (Test-Path "$Root\backend\public\storage") { cmd /c rmdir "$Root\backend\public\storage" 2>$null; Remove-Item "$Root\backend\public\storage" -Recurse -Force -ErrorAction SilentlyContinue }
& $Php -c $PhpIni artisan storage:link | Out-Null
& $Php -c $PhpIni artisan migrate --force
$migrateExit = $LASTEXITCODE
Pop-Location
if ($migrateExit -ne 0) { Bad "migrate failed (see above)"; exit 1 }
Ok ".env points at this server's MySQL; public URL $PublicUrl"

# ---------------------------------------------------------------------------
Section "5. Auto-start tasks"
# Windows Defender sometimes quarantines ngrok.exe as a "remote access tool".
try { Add-MpPreference -ExclusionPath "$Root\ngrok" -ErrorAction Stop; Ok "Defender exclusion added for $Root\ngrok" } catch { Info "could not add Defender exclusion ($($_.Exception.Message)) - continuing" }

$settingsSet = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 999 `
    -RestartInterval (New-TimeSpan -Minutes 1) -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest

$backendCmd = "`"$Php`" -c `"$PhpIni`" -S 127.0.0.1:8000 -t `"$Root\backend\public`" `"$Root\backend\server.php`" >> `"$Root\logs\backend.log`" 2>&1"
# /s /c "..." - the outer quote pair is what lets cmd keep every inner quoted path intact.
$backendAction = New-ScheduledTaskAction -Execute 'cmd.exe' -Argument "/s /c `"$backendCmd`"" -WorkingDirectory "$Root\backend\public"
$backendTrigger = New-ScheduledTaskTrigger -AtStartup

$tunnelCmd = "`"$Root\ngrok\ngrok.exe`" http 127.0.0.1:8000 --url=$PublicUrl --config `"$Root\ngrok\ngrok.yml`" --log=stdout >> `"$Root\logs\ngrok.log`" 2>&1"
$tunnelAction = New-ScheduledTaskAction -Execute 'cmd.exe' -Argument "/s /c `"$tunnelCmd`"" -WorkingDirectory "$Root\ngrok"
$tunnelTrigger = New-ScheduledTaskTrigger -AtStartup
$tunnelTrigger.Delay = 'PT30S'

foreach ($name in 'MedCart-Backend', 'MedCart-Tunnel') {
    if (Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue) {
        Stop-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
        Unregister-ScheduledTask -TaskName $name -Confirm:$false
    }
}
Get-Process php, ngrok -ErrorAction SilentlyContinue | Where-Object { $_.Path -like "$Root\*" } | Stop-Process -Force -ErrorAction SilentlyContinue

Register-ScheduledTask -TaskName 'MedCart-Backend' -Action $backendAction -Trigger $backendTrigger -Settings $settingsSet -Principal $principal | Out-Null
Register-ScheduledTask -TaskName 'MedCart-Tunnel' -Action $tunnelAction -Trigger $tunnelTrigger -Settings $settingsSet -Principal $principal | Out-Null
Start-ScheduledTask -TaskName 'MedCart-Backend'
Start-ScheduledTask -TaskName 'MedCart-Tunnel'
Ok "MedCart-Backend and MedCart-Tunnel registered and started"

# ---------------------------------------------------------------------------
Section "6. Check"
$localOk = $false
for ($i = 0; $i -lt 30 -and -not $localOk; $i++) {
    try { $localOk = (Invoke-WebRequest 'http://127.0.0.1:8000/api/health' -UseBasicParsing -TimeoutSec 3).StatusCode -eq 200 } catch { Start-Sleep 1 }
}
if ($localOk) { Ok "backend answers on this server" } else { Bad "backend not answering - see $Root\logs\backend.log" }

$publicOk = $false
for ($i = 0; $i -lt 45 -and -not $publicOk; $i++) {
    try {
        $r = Invoke-WebRequest "$PublicUrl/api/health" -UseBasicParsing -TimeoutSec 10 -Headers @{ 'ngrok-skip-browser-warning' = 'true' }
        $publicOk = $r.StatusCode -eq 200 -and $r.Content -notmatch '<html'
    } catch { Start-Sleep 2 }
}
if ($publicOk) {
    Ok "reachable from the internet at $PublicUrl"
} else {
    Bad "not reachable at $PublicUrl yet - see $Root\logs\ngrok.log"
    Info "If the log says the endpoint is already online, ngrok is still running on the old PC - stop it there, then run:"
    Info "  Start-ScheduledTask -TaskName MedCart-Tunnel"
}

Write-Host ""
if ($localOk -and $publicOk) {
    Write-Host "================================================================" -ForegroundColor Green
    Write-Host " DONE - care URU backend is running on this server" -ForegroundColor Green
    Write-Host " It starts by itself whenever the server restarts." -ForegroundColor Green
    Write-Host "================================================================" -ForegroundColor Green
} else {
    Write-Host " NOT FINISHED - see FAILED lines above" -ForegroundColor Red
}
