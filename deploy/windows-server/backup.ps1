# Daily backup of the care URU database + uploaded files (nurse profile photos).
# Registered by setup-backup.ps1 to run every hour as SYSTEM. Safe to run by
# hand anytime:  powershell -NoProfile -ExecutionPolicy Bypass -File C:\medcart\backup.ps1
#
# Output: C:\medcart\backups\medcart-YYYY-MM-DD_HHmm.zip containing
#   medication.sql  - full database dump (restore with import-db.php, see setup-backup.ps1)
#   uploads\        - storage\app\public (profile photos)
# Keeps the last $KeepDays days of zips. Result of every run goes to C:\medcart\logs\backup.log.

param(
    [string]$Root = 'C:\medcart',
    [string]$EnvFile = '',
    [string]$MysqldumpPath = '',
    [int]$KeepDays = 30
)

$ErrorActionPreference = 'Stop'
if (-not $EnvFile) { $EnvFile = "$Root\backend\.env" }
if (-not $MysqldumpPath) { $MysqldumpPath = (Get-Content "$Root\mysqldump-path.txt" -ErrorAction Stop).Trim() }

$BackupDir = "$Root\backups"
$LogFile = "$Root\logs\backup.log"
New-Item -ItemType Directory -Force $BackupDir, "$Root\logs" | Out-Null
function Log($m) { "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') $m" | Add-Content -Path $LogFile -Encoding UTF8 }

function EnvValue($key) {
    $m = Select-String -Path $EnvFile -Pattern "^$key=(.*)$" | Select-Object -First 1
    if (-not $m) { return '' }
    $v = $m.Matches[0].Groups[1].Value.Trim()
    if ($v -match "^'(.*)'$" -or $v -match '^"(.*)"$') { $v = $Matches[1] }
    return $v
}

$stamp = Get-Date -Format 'yyyy-MM-dd_HHmm'
$work = "$BackupDir\tmp-$stamp"
$cnf = "$work\client.cnf"
try {
    New-Item -ItemType Directory -Force $work | Out-Null

    # Credentials go in a temporary option file, never on the command line
    # (command lines are visible to every process on the machine).
    $pw = (EnvValue 'DB_PASSWORD') -replace '\\', '\\' -replace '"', '\"'
    $port = EnvValue 'DB_PORT'; if (-not $port) { $port = '3306' }
    $user = EnvValue 'DB_USERNAME'; if (-not $user) { $user = 'root' }
    $db = EnvValue 'DB_DATABASE'; if (-not $db) { $db = 'medication' }
    [IO.File]::WriteAllText($cnf, "[client]`r`nuser=$user`r`npassword=`"$pw`"`r`nhost=127.0.0.1`r`nport=$port`r`n", (New-Object Text.UTF8Encoding $false))

    $sql = "$work\medication.sql"
    $err = "$work\mysqldump-errors.txt"
    $p = Start-Process -FilePath $MysqldumpPath -NoNewWindow -Wait -PassThru -RedirectStandardError $err -ArgumentList @(
        "--defaults-extra-file=`"$cnf`"", '--single-transaction', '--routines',
        '--default-character-set=utf8mb4', "--result-file=`"$sql`"", $db)
    Remove-Item $cnf -Force

    if ($p.ExitCode -ne 0 -or -not (Test-Path $sql) -or (Get-Item $sql).Length -lt 1000) {
        throw "mysqldump failed (exit $($p.ExitCode)): $((Get-Content $err -Raw -ErrorAction SilentlyContinue))"
    }
    Remove-Item $err -Force -ErrorAction SilentlyContinue

    $uploads = "$Root\backend\storage\app\public"
    if (Test-Path $uploads) { Copy-Item $uploads "$work\uploads" -Recurse }

    $zip = "$BackupDir\medcart-$stamp.zip"
    Compress-Archive -Path "$work\*" -DestinationPath $zip -Force
    Remove-Item $work -Recurse -Force

    $old = Get-ChildItem $BackupDir -Filter 'medcart-*.zip' | Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-$KeepDays) }
    $old | Remove-Item -Force

    Log ("OK {0} ({1:N0} KB), removed {2} backup(s) older than {3} days" -f $zip, ((Get-Item $zip).Length / 1KB), @($old).Count, $KeepDays)
    Write-Output "OK $zip"
} catch {
    if (Test-Path $cnf) { Remove-Item $cnf -Force }
    if (Test-Path $work) { Remove-Item $work -Recurse -Force }
    Log "FAILED $($_.Exception.Message)"
    Write-Output "FAILED $($_.Exception.Message)"
    exit 1
}
