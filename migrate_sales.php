<?php
require_once __DIR__ . '/config/database.php';
$pdo = Database::conn();

try {
    $pdo->exec("ALTER TABLE sales ADD COLUMN additional_fee DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER discount_amount;");
    echo "Column additional_fee added.\n";
} catch (Exception $e) {
    echo "Error adding additional_fee: " . $e->getMessage() . "\n";
}

try {
    $pdo->exec("ALTER TABLE sales ADD COLUMN additional_fee_label VARCHAR(100) DEFAULT NULL AFTER additional_fee;");
    echo "Column additional_fee_label added.\n";
} catch (Exception $e) {
    echo "Error adding additional_fee_label: " . $e->getMessage() . "\n";
}
