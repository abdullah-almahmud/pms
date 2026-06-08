<?php
require_once __DIR__ . '/../config/db.php';

$sqlFile = __DIR__ . '/seed_updates.sql';
if (!file_exists($sqlFile)) {
    die("File not found: " . $sqlFile);
}

$sql = file_get_contents($sqlFile);

try {
    $pdo->exec($sql);
    echo "SQL executed successfully.\n";
} catch (PDOException $e) {
    echo "Error executing SQL: " . $e->getMessage() . "\n";
}
