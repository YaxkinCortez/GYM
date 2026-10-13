<?php
require_once __DIR__ . '/../includes/config.php';
verificarAutenticacion();
$usuario = obtenerUsuarioActual();
$historial = obtenerTodas("
    SELECT cm.*, c.fullName, m.name as plan
    FROM clientmemberships cm
    JOIN clients c ON cm.clientId = c.id
    JOIN memberships m ON cm.membershipId = m.id
    ORDER BY cm.id DESC LIMIT 50
");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Historial Membresías - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/navbar.php'; ?>
    <div class="container-fluid"><div class="row">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <main class="col-md-10 ms-sm-auto px-md-4 main-content">
        <div class="page-header"><h1><i class="fas fa-history me-2"></i>Historial de Membresías</h1></div>
        <div class="card"><table class="table table-hover mb-0">
            <thead class="table-light"><tr><th>Socio</th><th>Plan</th><th>Inicio</th><th>Vencimiento</th><th>Estado</th></tr></thead>
            <tbody>
                <?php foreach ($historial as $h): ?>
                <tr>
                    <td class="fw-bold"><?php echo htmlspecialchars($h['fullName']); ?></td>
                    <td><?php echo htmlspecialchars($h['plan']); ?></td>
                    <td><?php echo $h['startDate']; ?></td>
                    <td><?php echo $h['endDate']; ?></td>
                    <td><span class="badge <?php echo $h['isActive']?'bg-success':'bg-secondary'; ?>"><?php echo $h['isActive']?'Vigente':'Concluida'; ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </main></div></div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>