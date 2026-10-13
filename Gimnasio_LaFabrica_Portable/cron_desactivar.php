<?php
require_once __DIR__ . '/includes/config.php';
// Actualiza en SQLite a inactivas las que ya caducaron
$pdo->exec("UPDATE clientmemberships SET isActive = 0 WHERE endDate < date('now', 'localtime') AND isActive = 1");
echo "[OK] Membresías vencidas actualizadas a inactivas en SQLite.\n";
?>