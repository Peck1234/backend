<?php
// Creates the `medication` database on this server's MySQL and loads
// medication.sql into it. Skips the load if the database already has tables,
// so re-running setup.ps1 never wipes data entered on the server.
// Password comes from the MEDCART_DB_PASS env var (setup.ps1 asks for it).

$sqlFile = $argv[1] ?? __DIR__ . '/medication.sql';
$pass = getenv('MEDCART_DB_PASS');
$port = getenv('MEDCART_DB_PORT') ?: '3306';
$db = getenv('MEDCART_DB_NAME') ?: 'medication';

try {
    $pdo = new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4", 'root', $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "FAILED: cannot connect to MySQL: " . $e->getMessage() . "\n");
    exit(1);
}

$pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$db`");

$tables = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = " . $pdo->quote($db))->fetchColumn();
if ($tables > 0 && !getenv('MEDCART_FORCE_IMPORT')) {
    echo "SKIP: database already has $tables tables - keeping existing data\n";
    exit(0);
}

// Run statement by statement so an error anywhere is reported, not just in
// the first statement (multi-statement exec() silently drops later errors).
$sql = file_get_contents($sqlFile);
$statements = preg_split('/;\s*\n/', $sql);
$ran = 0;
foreach ($statements as $stmt) {
    $trimmed = trim($stmt);
    if ($trimmed === '' || strpos($trimmed, '--') === 0 && strpos($trimmed, "\n") === false) {
        continue;
    }
    try {
        $pdo->exec($trimmed);
        $ran++;
    } catch (PDOException $e) {
        fwrite(STDERR, "FAILED on statement:\n" . substr($trimmed, 0, 300) . "\n" . $e->getMessage() . "\n");
        exit(1);
    }
}

$patients = $pdo->query('SELECT COUNT(*) FROM patients')->fetchColumn();
$nurses = $pdo->query('SELECT COUNT(*) FROM nurses')->fetchColumn();
echo "OK: imported ($ran statements) - patients=$patients nurses=$nurses\n";
