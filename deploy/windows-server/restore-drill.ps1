# Restore drill: proves the latest backup can actually be restored, WITHOUT
# touching live data. Restores the newest C:\medcart\backups zip into a separate
# throwaway database, compares row counts table by table against the live
# database, then deletes the throwaway database.
#
# Run in PowerShell as Administrator:
#   powershell -NoProfile -ExecutionPolicy Bypass -File .\restore-drill.ps1

param(
    [string]$Root = 'C:\medcart',
    [string]$EnvFile = '',
    [string]$Php = '',
    [string]$PhpIni = '',
    [string]$ImportScript = ''
)

$ErrorActionPreference = 'Stop'
if (-not $EnvFile)      { $EnvFile = "$Root\backend\.env" }
if (-not $Php)          { $Php = "$Root\php\php.exe" }
if (-not $PhpIni)       { $PhpIni = "$Root\php\php.ini" }
if (-not $ImportScript) { $ImportScript = "$Root\import-db.php" }
$DrillDb = 'medcart_restore_drill'

function EnvValue($key) {
    $m = Select-String -Path $EnvFile -Pattern "^$key=(.*)$" | Select-Object -First 1
    if (-not $m) { return '' }
    $v = $m.Matches[0].Groups[1].Value.Trim()
    if ($v -match "^'(.*)'$" -or $v -match '^"(.*)"$') { $v = $Matches[1] }
    return $v
}

$latest = Get-ChildItem "$Root\backups\medcart-*.zip" -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending | Select-Object -First 1
if (-not $latest) { Write-Host "  FAILED: no backup found in $Root\backups" -ForegroundColor Red; exit 1 }

Write-Host ""
Write-Host "== Restore drill ==" -ForegroundColor Cyan
Write-Host "  Backup used : $($latest.Name)  (made $($latest.LastWriteTime.ToString('yyyy-MM-dd HH:mm')))"
Write-Host "  Live data is NOT touched - restoring into a separate test database '$DrillDb'"

$tmp = Join-Path $env:TEMP "medcart-drill-$(Get-Random)"
$env:MEDCART_DB_PASS = EnvValue 'DB_PASSWORD'
$env:MEDCART_DB_PORT = (EnvValue 'DB_PORT'); if (-not $env:MEDCART_DB_PORT) { $env:MEDCART_DB_PORT = '3306' }
$liveDb = EnvValue 'DB_DATABASE'; if (-not $liveDb) { $liveDb = 'medication' }
try {
    Expand-Archive $latest.FullName $tmp -Force

    $env:MEDCART_DB_NAME = $DrillDb
    $env:MEDCART_FORCE_IMPORT = '1'
    $out = & $Php -c $PhpIni $ImportScript "$tmp\medication.sql" 2>&1
    if ($LASTEXITCODE -ne 0) { throw "restore failed: $out" }
    Write-Host "  OK: backup restored ($out)" -ForegroundColor Green

    $compare = @'
$pdo = new PDO("mysql:host=127.0.0.1;port=" . getenv("MEDCART_DB_PORT") . ";charset=utf8mb4", "root", getenv("MEDCART_DB_PASS"));
$live = getenv("MEDCART_LIVE_DB"); $drill = getenv("MEDCART_DB_NAME");
$tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = " . $pdo->quote($drill) . " ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
$diff = 0;
printf("\n  %-28s %8s %8s  %s\n", "table", "live", "backup", "");
foreach ($tables as $t) {
    if (in_array($t, ["migrations", "personal_access_tokens", "failed_jobs", "password_resets", "password_reset_codes"])) continue;
    $l = $pdo->query("SELECT COUNT(*) FROM `$live`.`$t`")->fetchColumn();
    $b = $pdo->query("SELECT COUNT(*) FROM `$drill`.`$t`")->fetchColumn();
    $note = $l == $b ? "same" : ($l > $b ? "+" . ($l - $b) . " added after backup" : "-" . ($b - $l) . " removed after backup");
    if ($l != $b) $diff++;
    printf("  %-28s %8d %8d  %s\n", $t, $l, $b, $note);
}
echo "\n  DIFF_TABLES=$diff\n";
'@
    $env:MEDCART_LIVE_DB = $liveDb
    $cmpFile = "$tmp\compare.php"
    Set-Content -Path $cmpFile -Value "<?php`n$compare" -Encoding ASCII
    $report = & $Php -c $PhpIni $cmpFile 2>&1
    $report | Where-Object { $_ -notmatch 'DIFF_TABLES=' } | ForEach-Object { Write-Host $_ }
    $diffTables = [int](($report | Select-String 'DIFF_TABLES=(\d+)').Matches[0].Groups[1].Value)

    Write-Host ""
    if ($diffTables -eq 0) {
        Write-Host "  RESULT: the backup matches live data exactly - a restore right now would lose nothing." -ForegroundColor Green
    } else {
        Write-Host "  RESULT: backup restores fine. Rows marked above were changed after $($latest.LastWriteTime.ToString('HH:mm')) -" -ForegroundColor Yellow
        Write-Host "          restoring this backup right now would lose exactly those changes." -ForegroundColor Yellow
    }
} catch {
    Write-Host "  FAILED: $($_.Exception.Message)" -ForegroundColor Red
    $failed = $true
} finally {
    $drop = "<?php `$p = new PDO('mysql:host=127.0.0.1;port=' . getenv('MEDCART_DB_PORT'), 'root', getenv('MEDCART_DB_PASS')); `$p->exec('DROP DATABASE IF EXISTS $DrillDb');"
    $dropFile = Join-Path $env:TEMP "medcart-drill-drop.php"
    Set-Content -Path $dropFile -Value $drop -Encoding ASCII
    & $Php -c $PhpIni $dropFile | Out-Null
    Remove-Item $dropFile, $tmp -Recurse -Force -ErrorAction SilentlyContinue
    foreach ($v in 'MEDCART_DB_PASS', 'MEDCART_DB_PORT', 'MEDCART_DB_NAME', 'MEDCART_FORCE_IMPORT', 'MEDCART_LIVE_DB') { Remove-Item "Env:\$v" -ErrorAction SilentlyContinue }
    Write-Host "  Test database deleted, temporary files removed."
}
if ($failed) { exit 1 }
