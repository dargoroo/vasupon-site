<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

require_once dirname(__DIR__) . '/bootstrap.php';

$dbName = app_config('DB_NAME', 'your_database_name');
$dbUser = app_config('DB_USER', 'your_database_user');
$dbPass = app_config('DB_PASS', '');

$hosts = [
    'localhost',
    '127.0.0.1',
    'teach.rbru.ac.th',
    'vasupon-p.rbru.ac.th',
    'mysql',
    'db',
];

echo "db_host_probe.php\n";
echo "Time: " . date('c') . "\n";
echo "PHP: " . PHP_VERSION . "\n";
echo "DB_NAME: {$dbName}\n";
echo "DB_USER: {$dbUser}\n\n";

foreach ($hosts as $host) {
    try {
        $pdo = new PDO(
            "mysql:host={$host};dbname={$dbName};charset=utf8",
            $dbUser,
            $dbPass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 3,
            ]
        );

        $stmt = $pdo->query("SELECT DATABASE() AS db_name");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "[OK] {$host}";
        if (!empty($row['db_name'])) {
            echo " => connected to " . $row['db_name'];
        }
        echo "\n";
    } catch (Throwable $e) {
        echo "[FAIL] {$host} => " . $e->getMessage() . "\n";
    }
}

echo "\nDone\n";
