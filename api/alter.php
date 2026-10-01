<?php
require_once __DIR__ . '/../config/database.php';
$db = Database::conn();
try {
    $db->exec('ALTER TABLE cash_settlements ADD COLUMN non_cash_details JSON NULL AFTER actual_cash;');
    file_put_contents('alter_log.txt', 'Success');
} catch (Exception $e) {
    file_put_contents('alter_log.txt', 'Error: ' . $e->getMessage());
}
