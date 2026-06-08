<?php
require_once __DIR__ . '/config/db.php';

try {
    $pdo->exec("
        ALTER TABLE invoices MODIFY COLUMN status ENUM('unpaid', 'partially_paid', 'paid', 'overdue') NOT NULL DEFAULT 'unpaid';
    ");
    echo "Invoices table altered successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
