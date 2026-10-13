<?php
require_once __DIR__ . '/includes/config.php';
$destDir = DB_DIR . DIRECTORY_SEPARATOR . 'backups';
if (!is_dir($destDir)) mkdir($destDir, 0777, true);
$destFile = $destDir . DIRECTORY_SEPARATOR . 'gimnasio_' . date('Y-m-d_H-i-s') . '.sqlite';
if (copy(DB_FILE, $destFile)) {
    echo "[OK] Respaldo automático generado: " . basename($destFile) . "\n";
} else {
    echo "[ERROR] No se pudo crear el archivo de respaldo.\n";
}
?>