<?php
require_once __DIR__ . '/../includes/config.php';
verificarAutenticacion();
$usuario = obtenerUsuarioActual();

// Consulta consolidada ultra rápida en SQLite
$stats = obtenerFila("
    SELECT
        (SELECT COALESCE(SUM(subtotal), 0) FROM saleitems WHERE date(createdAt) = date('now', 'localtime')) as ingProductosInternos,
        (SELECT COALESCE(SUM(subtotal), 0) FROM ventas_externas_realizadas WHERE date(createdAt) = date('now', 'localtime')) as ingProductosExternos,
        (SELECT COALESCE(SUM(cost), 0) FROM membership_sales WHERE date(saleDate) = date('now', 'localtime')) as ingMembresias,
        (SELECT COALESCE(SUM(membershipCost), 0) FROM temp_visits WHERE date(checkInTime) = date('now', 'localtime')) as ingPasesDiarios,
        (SELECT COUNT(*) FROM visits WHERE visitDate = date('now', 'localtime')) as visitasSocios,
        (SELECT COUNT(*) FROM temp_visits WHERE date(checkInTime) = date('now', 'localtime')) as visitasTemp,
        (SELECT COUNT(*) FROM clients) as totalClientes,
        (SELECT COUNT(DISTINCT clientId) FROM clientmemberships WHERE isActive = 1 AND endDate >= date('now', 'localtime')) as clientesActivos,
        (SELECT COUNT(*) FROM products WHERE quantity <= minStock) as bajoStock
");

$ingProductosInternos = (float)($stats['ingProductosInternos'] ?? 0);
$ingProductosExternos = (float)($stats['ingProductosExternos'] ?? 0);
$ingresosMembresias   = (float)($stats['ingMembresias'] ?? 0);
$ingresosPasesDiarios = (float)($stats['ingPasesDiarios'] ?? 0);
$ingresoHoy           = $ingProductosInternos + $ingProductosExternos + $ingresosMembresias + $ingresosPasesDiarios;

$visitasClientes    = (int)($stats['visitasSocios'] ?? 0);
$visitasTemporales  = (int)($stats['visitasTemp'] ?? 0);
$visitasHoy         = $visitasClientes + $visitasTemporales;
$totalClientes      = (int)($stats['totalClientes'] ?? 0);
$clientesActivos    = (int)($stats['clientesActivos'] ?? 0);
$bajoStock          = (int)($stats['bajoStock'] ?? 0);

// Membresías Vencidas y Por Vencer en SQLite (usando julianday)
$vencidas = (int)obtenerFila("
    SELECT COUNT(*) as t FROM (
        SELECT clientId, MAX(endDate) as maxEnd FROM clientmemberships GROUP BY clientId HAVING maxEnd < date('now', 'localtime')
    )
")['t'];

$porVencer = (int)obtenerFila("
    SELECT COUNT(*) as t FROM (
        SELECT clientId, MAX(endDate) as maxEnd FROM clientmemberships GROUP BY clientId 
        HAVING CAST(julianday(maxEnd) - julianday(date('now', 'localtime')) AS INTEGER) BETWEEN 1 AND 5
    )
")['t'];

$listaBajoStock = obtenerTodas("SELECT name, quantity, minStock FROM products WHERE quantity <= minStock ORDER BY quantity ASC");

$listaPorVencer = obtenerTodas("
    SELECT c.fullName, m.name as membership_name, cm.maxEnd as endDate, 
           CAST(julianday(cm.maxEnd) - julianday(date('now', 'localtime')) AS INTEGER) as dias
    FROM clients c
    JOIN (SELECT clientId, membershipId, MAX(endDate) as maxEnd FROM clientmemberships GROUP BY clientId) cm ON c.id = cm.clientId
    JOIN memberships m ON cm.membershipId = m.id
    WHERE CAST(julianday(cm.maxEnd) - julianday(date('now', 'localtime')) AS INTEGER) BETWEEN 1 AND 5
    ORDER BY cm.maxEnd ASC
");

$listaVencidas = obtenerTodas("
    SELECT c.fullName, m.name as membership_name, cm.maxEnd as endDate,
           CAST(julianday(date('now', 'localtime')) - julianday(cm.maxEnd) AS INTEGER) as dias
    FROM clients c
    JOIN (SELECT clientId, membershipId, MAX(endDate) as maxEnd FROM clientmemberships GROUP BY clientId) cm ON c.id = cm.clientId
    JOIN memberships m ON cm.membershipId = m.id
    WHERE cm.maxEnd < date('now', 'localtime')
    ORDER BY cm.maxEnd DESC
");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo APP_NAME; ?></title>
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
                <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h1><i class="fas fa-chart-line me-2"></i>Dashboard</h1>
                        <p class="text-muted mb-0 small">Resumen general y métricas en tiempo real</p>
                    </div>
                    <span class="badge bg-secondary font-monospace fs-6"><?php echo date('d/m/Y H:i'); ?></span>
                </div>

                <!-- FILA 1: INGRESOS Y VENTAS DEL DÍA (DESGLOSE INTERNO / EXTERNO / MEMBRESÍAS / PASES) -->
                <div class="mb-2">
                    <h5 class="fw-bold text-dark mb-3"><i class="fas fa-coins me-2 text-warning"></i>Ingresos y Ventas de Hoy</h5>
                    <div class="row g-3 mb-4">
                        <!-- 1. Venta Productos Internos -->
                        <div class="col-xl-3 col-md-6">
                            <div class="stat-card border-start border-4 border-primary h-100 shadow-sm">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="text-muted small fw-bold text-uppercase">Productos Internos</div>
                                        <h3 class="text-primary font-monospace mt-1 mb-0">$<?php echo number_format($ingProductosInternos, 2); ?></h3>
                                    </div>
                                    <i class="fas fa-store text-primary fa-2x opacity-75"></i>
                                </div>
                                <small class="text-muted d-block mt-2">Tienda oficial del gimnasio</small>
                            </div>
                        </div>

                        <!-- 2. Venta Membresías -->
                        <div class="col-xl-3 col-md-6">
                            <div class="stat-card border-start border-4 border-success h-100 shadow-sm">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="text-muted small fw-bold text-uppercase">Venta Membresías</div>
                                        <h3 class="text-success font-monospace mt-1 mb-0">$<?php echo number_format($ingresosMembresias, 2); ?></h3>
                                    </div>
                                    <i class="fas fa-id-card text-success fa-2x opacity-75"></i>
                                </div>
                                <small class="text-muted d-block mt-2">Inscripciones y renovaciones</small>
                            </div>
                        </div>

                        <!-- 3. Venta Pases Diarios -->
                        <div class="col-xl-3 col-md-6">
                            <div class="stat-card border-start border-4 border-info h-100 shadow-sm">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="text-muted small fw-bold text-uppercase">Pases Diarios</div>
                                        <h3 class="text-info font-monospace mt-1 mb-0">$<?php echo number_format($ingresosPasesDiarios, 2); ?></h3>
                                    </div>
                                    <i class="fas fa-ticket-alt text-info fa-2x opacity-75"></i>
                                </div>
                                <small class="text-muted d-block mt-2">Visitas temporales y día suelto</small>
                            </div>
                        </div>

                        <!-- 4. RECUADRO ESPECÍFICO: VENTAS EXTRAS / EXTERNAS DEL DÍA -->
                        <div class="col-xl-3 col-md-6">
                            <div class="stat-card border border-2 border-warning bg-warning-subtle h-100 shadow-sm">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="text-warning-emphasis small fw-bold text-uppercase">
                                            <i class="fas fa-tag me-1"></i> Ventas Extras del Día
                                        </div>
                                        <h3 class="text-warning-emphasis font-monospace mt-1 mb-0">$<?php echo number_format($ingProductosExternos, 2); ?></h3>
                                    </div>
                                    <i class="fas fa-cubes text-warning-emphasis fa-2x"></i>
                                </div>
                                <small class="text-dark-emphasis d-block mt-2">Cocas, scoops, suplementos externos</small>
                            </div>
                        </div>
                    </div>

                    <!-- TOTAL GLOBAL DEL DÍA -->
                    <div class="p-3 bg-white rounded border border-success-subtle shadow-sm mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-3">
                            <div class="p-3 bg-success text-white rounded-circle">
                                <i class="fas fa-cash-register fa-2x"></i>
                            </div>
                            <div>
                                <div class="text-muted small fw-bold text-uppercase">TOTAL GLOBAL COBRADO HOY</div>
                                <div class="fs-2 fw-bold text-success font-monospace mb-0">$<?php echo number_format($ingresoHoy, 2); ?></div>
                            </div>
                        </div>
                        <div class="text-muted small text-end">
                            <div>Internos ($<?php echo number_format($ingProductosInternos, 2); ?>) + Membresías ($<?php echo number_format($ingresosMembresias, 2); ?>)</div>
                            <div>Pases ($<?php echo number_format($ingresosPasesDiarios, 2); ?>) + Extras ($<?php echo number_format($ingProductosExternos, 2); ?>)</div>
                        </div>
                    </div>
                </div>

                <!-- FILA 2: OPERATIVIDAD, ACCESOS Y ALERTAS -->
                <div class="d-flex flex-wrap gap-3 justify-content-center mb-4">
                    <div class="stat-card" style="flex:1 1 200px; max-width:260px;">
                        <i class="fas fa-users text-primary fa-2x"></i>
                        <h3><?php echo $totalClientes; ?></h3>
                        <p>Clientes Registrados</p>
                    </div>
                    <div class="stat-card" style="flex:1 1 200px; max-width:260px;">
                        <i class="fas fa-check-circle text-success fa-2x"></i>
                        <h3><?php echo $clientesActivos; ?></h3>
                        <p>Membresías Activas</p>
                    </div>
                    <div class="stat-card" style="flex:1 1 200px; max-width:260px;">
                        <i class="fas fa-walking text-info fa-2x"></i>
                        <h3><?php echo $visitasHoy; ?></h3>
                        <p>Visitas Hoy</p>
                        <small class="text-muted">Socios: <?php echo $visitasClientes; ?> | Pases: <?php echo $visitasTemporales; ?></small>
                    </div>
                    <div class="stat-card cursor-pointer border-danger" data-bs-toggle="modal" data-bs-target="#modalBajoStock" style="flex:1 1 200px; max-width:260px;">
                        <i class="fas fa-exclamation-triangle text-danger fa-2x"></i>
                        <h3 class="text-danger"><?php echo $bajoStock; ?></h3>
                        <p>Productos Bajo Stock</p>
                        <small class="text-muted">Click para ver lista</small>
                    </div>
                    <div class="stat-card cursor-pointer border-warning" data-bs-toggle="modal" data-bs-target="#modalPorVencer" style="flex:1 1 200px; max-width:260px;">
                        <i class="fas fa-hourglass-half text-warning fa-2x"></i>
                        <h3 class="text-warning"><?php echo $porVencer; ?></h3>
                        <p>Por Vencer (1-5 días)</p>
                        <small class="text-muted">Click para ver lista</small>
                    </div>
                    <div class="stat-card cursor-pointer border-danger" data-bs-toggle="modal" data-bs-target="#modalVencidas" style="flex:1 1 200px; max-width:260px;">
                        <i class="fas fa-times-circle text-danger fa-2x"></i>
                        <h3 class="text-danger"><?php echo $vencidas; ?></h3>
                        <p>Membresías Vencidas</p>
                        <small class="text-muted">Click para ver lista</small>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- MODAL BAJO STOCK -->
    <div class="modal fade" id="modalBajoStock" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Productos Bajo Stock</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light"><tr><th>Producto</th><th class="text-center">Stock</th><th class="text-center">Mínimo</th></tr></thead>
                        <tbody>
                            <?php foreach ($listaBajoStock as $p): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($p['name']); ?></td>
                                <td class="text-center fw-bold text-danger"><?php echo $p['quantity']; ?></td>
                                <td class="text-center"><?php echo $p['minStock']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL POR VENCER -->
    <div class="modal fade" id="modalPorVencer" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="fas fa-hourglass-half me-2"></i>Por Vencer (1-5 días)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light"><tr><th>Socio</th><th>Plan</th><th>Vence</th><th class="text-center">Días</th></tr></thead>
                        <tbody>
                            <?php foreach ($listaPorVencer as $pv): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($pv['fullName']); ?></td>
                                <td><?php echo htmlspecialchars($pv['membership_name']); ?></td>
                                <td><?php echo $pv['endDate']; ?></td>
                                <td class="text-center fw-bold text-warning"><?php echo $pv['dias']; ?> d</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL VENCIDAS -->
    <div class="modal fade" id="modalVencidas" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-times-circle me-2"></i>Membresías Vencidas</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light"><tr><th>Socio</th><th>Plan</th><th>Venció</th><th class="text-center">Atraso</th></tr></thead>
                        <tbody>
                            <?php foreach ($listaVencidas as $v): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($v['fullName']); ?></td>
                                <td><?php echo htmlspecialchars($v['membership_name']); ?></td>
                                <td><?php echo $v['endDate']; ?></td>
                                <td class="text-center fw-bold text-danger"><?php echo abs($v['dias']); ?> d</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>