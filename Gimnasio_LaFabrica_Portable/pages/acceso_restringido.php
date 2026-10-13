<?php
require_once __DIR__ . '/../includes/config.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Acceso Restringido - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f8fafc; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card-restricted { max-width: 480px; padding: 40px; border-radius: 16px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.08); text-align: center; }
    </style>
</head>
<body>
    <div class="card card-restricted">
        <div class="text-danger mb-3" style="font-size: 3rem;"><i class="fas fa-shield-alt"></i></div>
        <h3 class="fw-bold">Acceso Restringido</h3>
        <p class="text-muted">Se requieren privilegios de <strong>Administrador</strong> para acceder a esta sección.</p>
        <a href="dashboard.php" class="btn btn-primary mt-3"><i class="fas fa-arrow-left me-1"></i> Volver al Dashboard</a>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>