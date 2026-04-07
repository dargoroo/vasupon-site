<?php
require_once dirname(__DIR__) . '/bootstrap.php';

try {
    $pdo = app_pdo();
    echo "DB OK";
} catch (Throwable $e) {
    echo "DB FAIL: " . $e->getMessage();
}
