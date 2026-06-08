<?php
require_once __DIR__ . '/config/db.php';

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cost_records (
            id INT PRIMARY KEY AUTO_INCREMENT,
            company_id INT NULL,
            client_name VARCHAR(150),
            invoice_type ENUM('berth', 'storage', 'transport', 'other') DEFAULT 'other',
            ref_id INT NULL,
            description VARCHAR(255) NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            invoice_id INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (company_id) REFERENCES companies(id),
            FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
        );
        
        ALTER TABLE invoices MODIFY COLUMN invoice_type ENUM('berth','storage','transport','consolidated','other') NOT NULL DEFAULT 'other';
    ");
    echo "Tables created successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
