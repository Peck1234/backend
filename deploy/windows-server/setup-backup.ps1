# One-time setup of the nightly care URU database backup on the university server.
# Run in PowerShell as Administrator, from this folder:
#   powershell -NoProfile -ExecutionPolicy Bypass -File .\setup-backup.ps1
#
# Installs C:\medcart\backup.ps1, schedules it every hour (task
# "MedCart-Backup", runs as SYSTEM, no login needed), runs one backup now.
#
# To restore a backup later (replaces ALL current data with the backup's):
#   1. Unzip C:\medcart\backups\medcart-<date>.zip
#   2. In PowerShell as Administrator:
#        $env:MEDCART_DB_PASS = '<MySQL root password>'
#        $env:MEDCART_FORCE_IMPORT = '1'
#        & C:\medcart\php\php.exe -c C:\medcart\php\php.ini C:\medcart\import-db.php <unzipped folder>\medication.sql
#      (copy the zip's uploads\ folder back to C:\medcart\backend\storage\app\public too)

$ErrorActionPreference = 'Stop'
$Root = 'C:\medcart'
function Ok($m)  { Write-Host "  OK: $m" -ForegroundColor Green }
function Bad($m) { Write-Host "  FAILED: $m" -ForegroundColor Red }

$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) { Bad "Run PowerShell as Administrator, then run this script again."; exit 1 }
if (-not (Test-Path "$Root\backend\.env")) { Bad "$Root\backend\.env not found - run the main setup.ps1 first."; exit 1 }

# Find mysqldump.exe next to the running MySQL server's own mysqld.exe.
$dump = $null
$svc = Get-CimInstance Win32_Service | Where-Object { $_.PathName -match 'mysqld' } | Select-Object -First 1
if ($svc -and $svc.PathName -match '"?([^"]*\\)mysqld(-nt)?\.exe') {
    $candidate = Join-Path $Matches[1] 'mysqldump.exe'
    if (Test-Path $candidate) { $dump = $candidate }
}
if (-not $dump) {
    $dump = Get-ChildItem 'C:\Program Files\MySQL', 'C:\Program Files (x86)\MySQL', 'C:\Program Files\MariaDB*', 'C:\xampp\mysql\bin' `
        -Recurse -Filter mysqldump.exe -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty FullName
}
if (-not $dump) { Bad "Could not find mysqldump.exe on this server. Send a screenshot of this window."; exit 1 }
Set-Content -Path "$Root\mysqldump-path.txt" -Value $dump -Encoding ASCII
Ok "found $dump"

Copy-Item "$PSScriptRoot\backup.ps1" "$Root\backup.ps1" -Force
Ok "installed $Root\backup.ps1"

if (Get-ScheduledTask -TaskName 'MedCart-Backup' -ErrorAction SilentlyContinue) {
    Unregister-ScheduledTask -TaskName 'MedCart-Backup' -Confirm:$false
}
$action = New-ScheduledTaskAction -Execute 'powershell.exe' `
    -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$Root\backup.ps1`""
# Every hour: a daily trigger at midnight that repeats hourly for 24 hours.
$trigger = New-ScheduledTaskTrigger -Daily -At 12:00am
$trigger.Repetition = (New-ScheduledTaskTrigger -Once -At 12:00am -RepetitionInterval (New-TimeSpan -Hours 1) -RepetitionDuration (New-TimeSpan -Days 1)).Repetition
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 1) -AllowStartIfOnBatteries
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
Register-ScheduledTask -TaskName 'MedCart-Backup' -Action $action -Trigger $trigger -Settings $settings -Principal $principal | Out-Null
Ok "scheduled every hour (missed runs catch up when the server is next on)"

Write-Host ""
Write-Host "  Running a first backup now..."
$result = & powershell -NoProfile -ExecutionPolicy Bypass -File "$Root\backup.ps1"
if ($LASTEXITCODE -eq 0) {
    Ok ($result | Select-Object -Last 1)
    Write-Host ""
    Write-Host " DONE - backups are saved in $Root\backups and kept for 30 days" -ForegroundColor Green
} else {
    Bad ($result | Select-Object -Last 1)
}
