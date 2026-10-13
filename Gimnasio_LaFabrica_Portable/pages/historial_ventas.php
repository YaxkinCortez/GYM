<?php
require_once __DIR__ . '/../includes/config.php';
verificarAutenticacion();
$usuario = obtenerUsuarioActual();

// Obtener ventas globales (o ventas tradicionales si no hubiera global_sales)
$ventas = obtenerTodas("
    SELECT gs.*, u.name as vendedor, COALESCE(c.fullName, 'Público General') as cliente
    FROM global_sales gs
    LEFT JOIN users u ON gs.userId = u.id
    LEFT JOIN clients c ON gs.clientId = c.id
    ORDER BY gs.id DESC
    LIMIT 100
");

// Si aún no hay ventas en global_sales, consultar sales
if (empty($ventas)) {
    $ventas = obtenerTodas("
        SELECT s.id, printf('%04d', s.id) as folio, s.createdAt, s.finalAmount as totalAmount, 
               s.finalAmount as totalInternal, 0 as totalExternal, s.paymentMethod, u.name as vendedor, 'Público General' as cliente
        FROM sales s
        LEFT JOIN users u ON s.userId = u.id
        ORDER BY s.id DESC
        LIMIT 100
    ");
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Ventas - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/navbar.php'; ?>
    <div class="container-fluid"><div class="row">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <main class="col-md-10 ms-sm-auto px-md-4 main-content">
        <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="fas fa-receipt me-2"></i>Historial de Ventas Globales</h1>
                <p class="text-muted mb-0 small">Consulta de transacciones con folio autoincrementable y desglose de productos</p>
            </div>
            <a href="/pages/ventas.php" class="btn btn-primary"><i class="fas fa-plus me-1"></i> Nueva Venta (POS)</a>
        </div>

        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Folio</th>
                            <th>Fecha y Hora</th>
                            <th>Atendió (Cajero)</th>
                            <th>Cliente</th>
                            <th>Subtotal Interno</th>
                            <th>Subtotal Externo</th>
                            <th>Método</th>
                            <th class="text-end">Total Cobrado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($ventas)): ?>
                            <tr><td colspan="8" class="text-center py-5 text-muted">No se han registrado ventas aún.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($ventas as $v): ?>
                        <tr>
                            <td><span class="badge bg-dark font-monospace fs-6">#<?php echo htmlspecialchars($v['folio']); ?></span></td>
                            <td class="font-monospace text-muted small"><?php echo $v['createdAt']; ?></td>
                            <td><i class="fas fa-user-circle text-muted me-1"></i><?php echo htmlspecialchars($v['vendedor'] ?? 'Admin'); ?></td>
                            <td class="fw-bold"><?php echo htmlspecialchars($v['cliente']); ?></td>
                            <td class="font-monospace text-primary">$<?php echo number_format($v['totalInternal'] ?? 0, 2); ?></td>
                            <td class="font-monospace text-warning-emphasis">
                                <?php if (!empty($v['totalExternal']) && $v['totalExternal'] > 0): ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning">
                                        <i class="fas fa-tag me-1"></i>$<?php echo number_format($v['totalExternal'], 2); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">$0.00</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-light text-dark border text-uppercase"><?php echo htmlspecialchars($v['paymentMethod']); ?></span></td>
                            <td class="text-end fw-bold text-success font-monospace fs-6">$<?php echo number_format($v['totalAmount'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main></div></div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
