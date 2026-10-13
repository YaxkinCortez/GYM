<?php
require_once __DIR__ . '/../includes/config.php';
verificarAutenticacion();
$usuario = obtenerUsuarioActual();

if ($usuario['role'] !== 'admin') {
    header("Location: acceso_restringido.php");
    exit();
}

$mensaje = ''; $tipo_mensaje = '';

// Descargar archivo de base de datos directamente
if (isset($_GET['accion']) && $_GET['accion'] === 'descargar') {
    if (file_exists(DB_FILE)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/x-sqlite3');
        header('Content-Disposition: attachment; filename="gimnasio_backup_' . date('Y-m-d_H-i-s') . '.sqlite"');
        header('Content-Length: ' . filesize(DB_FILE));
        readfile(DB_FILE);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'ejecutar_backup') {
    $dest = DB_DIR . DIRECTORY_SEPARATOR . 'backups';
    if (!is_dir($dest)) mkdir($dest, 0777, true);
    $nombre = 'gimnasio_backup_' . date('Y-m-d_H-i-s') . '.sqlite';
    if (copy(DB_FILE, $dest . DIRECTORY_SEPARATOR . $nombre)) {
        $mensaje = "✅ Respaldo atómico instantáneo generado: <code>$nombre</code>";
        $tipo_mensaje = 'success';
    } else {
        $mensaje = "❌ Error al copiar la base de datos.";
        $tipo_mensaje = 'danger';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Respaldos - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/navbar.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include __DIR__ . '/../includes/sidebar.php'; ?>
            <main class="col-md-10 ms-sm-auto px-md-4 main-content">
                <div class="page-header"><h1><i class="fas fa-database me-2"></i>Respaldos SQLite</h1></div>
                <?php if ($mensaje): ?><div class="alert alert-<?php echo $tipo_mensaje; ?>"><?php echo $mensaje; ?></div><?php endif; ?>

                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="card h-100 p-4 border-success">
                            <h5 class="fw-bold text-success"><i class="fas fa-download me-2"></i>Descarga Directa de Base de Datos</h5>
                            <p class="text-muted small">Al usar SQLite, la base de datos completa reside en <code>database/gimnasio.sqlite</code>. Puedes descargar una copia fiel con un clic.</p>
                            <a href="?accion=descargar" class="btn btn-success btn-lg mt-auto py-3 fw-bold">
                                <i class="fas fa-file-download me-1"></i> Descargar Archivo .sqlite
                            </a>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100 p-4 border-primary">
                            <h5 class="fw-bold text-primary"><i class="fas fa-copy me-2"></i>Respaldo a Carpeta Local</h5>
                            <p class="text-muted small">Guarda una copia fechada instantánea dentro de la carpeta interna <code>database/backups/</code>.</p>
                            <form method="POST" class="mt-auto">
                                <input type="hidden" name="accion" value="ejecutar_backup">
                                <button type="submit" class="btn btn-primary btn-lg w-100 py-3 fw-bold">
                                    <i class="fas fa-save me-1"></i> Ejecutar Copia Ahora
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>