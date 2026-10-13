<?php
require_once __DIR__ . '/../includes/config.php';
verificarAutenticacion();
$usuario = obtenerUsuarioActual();

if ($usuario['role'] !== 'admin' && $usuario['role'] !== 'administrador') {
    header("Location: acceso_restringido.php");
    exit();
}

$tipo = $_GET['tipo'] ?? 'global_sales';
$inicio = escapar($_GET['fecha_inicio'] ?? date('Y-m-01'));
$fin = escapar($_GET['fecha_fin'] ?? date('Y-m-d'));

// Exportación CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Reporte_' . $tipo . '_' . $inicio . '_al_' . $fin . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM para Excel

    if ($tipo === 'global_sales') {
        fputcsv($out, ['Folio', 'Fecha y Hora', 'Cajero / Atendio', 'Cliente', 'Subtotal Interno ($)', 'Subtotal Externo ($)', 'Total Cobrado ($)', 'Metodo de Pago']);
        $q = $pdo->prepare("
            SELECT gs.folio, gs.createdAt, u.name as cajero, COALESCE(c.fullName, 'Público General') as cliente, 
                   gs.totalInternal, gs.totalExternal, gs.totalAmount, gs.paymentMethod 
            FROM global_sales gs 
            LEFT JOIN users u ON gs.userId = u.id 
            LEFT JOIN clients c ON gs.clientId = c.id 
            WHERE date(gs.createdAt) BETWEEN :i AND :f 
            ORDER BY gs.id DESC
        ");
        $q->execute([':i' => $inicio, ':f' => $fin]);
        while ($r = $q->fetch()) {
            fputcsv($out, [
                '#' . $r['folio'], $r['createdAt'], $r['cajero'], $r['cliente'],
                number_format($r['totalInternal'], 2, '.', ''),
                number_format($r['totalExternal'], 2, '.', ''),
                number_format($r['totalAmount'], 2, '.', ''),
                ucfirst($r['paymentMethod'])
            ]);
        }
    } elseif ($tipo === 'ventas_internas') {
        fputcsv($out, ['ID', 'Folio Global', 'Fecha y Hora', 'Cajero', 'Producto Interno', 'Cantidad', 'Precio Unitario ($)', 'Subtotal ($)']);
        $q = $pdo->prepare("
            SELECT si.id, COALESCE(gs.folio, printf('%04d', si.saleId)) as folio, si.createdAt, u.name as cajero,
                   si.productName, si.quantity, si.unitPrice, si.subtotal 
            FROM saleitems si 
            LEFT JOIN global_sales gs ON si.globalSaleId = gs.id 
            LEFT JOIN users u ON si.userId = u.id 
            WHERE date(si.createdAt) BETWEEN :i AND :f 
            ORDER BY si.id DESC
        ");
        $q->execute([':i' => $inicio, ':f' => $fin]);
        while ($r = $q->fetch()) {
            fputcsv($out, [
                $r['id'], '#' . $r['folio'], $r['createdAt'], $r['cajero'] ?? 'Admin',
                $r['productName'], $r['quantity'],
                number_format($r['unitPrice'], 2, '.', ''),
                number_format($r['subtotal'], 2, '.', '')
            ]);
        }
    } elseif ($tipo === 'ventas_externas') {
        fputcsv($out, ['ID', 'Folio Global', 'Fecha y Hora', 'Cajero', 'Producto Externo', 'Cantidad', 'Precio Unitario ($)', 'Subtotal ($)']);
        $q = $pdo->prepare("
            SELECT ve.id, gs.folio, ve.createdAt, u.name as cajero, ve.productName, ve.quantity, ve.unitPrice, ve.subtotal 
            FROM ventas_externas_realizadas ve 
            LEFT JOIN global_sales gs ON ve.globalSaleId = gs.id 
            LEFT JOIN users u ON ve.userId = u.id 
            WHERE date(ve.createdAt) BETWEEN :i AND :f 
            ORDER BY ve.id DESC
        ");
        $q->execute([':i' => $inicio, ':f' => $fin]);
        while ($r = $q->fetch()) {
            fputcsv($out, [
                $r['id'], '#' . $r['folio'], $r['createdAt'], $r['cajero'] ?? 'Admin',
                $r['productName'], $r['quantity'],
                number_format($r['unitPrice'], 2, '.', ''),
                number_format($r['subtotal'], 2, '.', '')
            ]);
        }
    } elseif ($tipo === 'membresias') {
        fputcsv($out, ['ID', 'Fecha Venta', 'Socio', 'Plan de Membresia', 'Monto ($)']);
        $q = $pdo->prepare("
            SELECT ms.id, ms.saleDate, c.fullName as cliente, m.name as plan, ms.cost 
            FROM membership_sales ms 
            JOIN clients c ON ms.clientId = c.id 
            JOIN memberships m ON ms.membershipId = m.id 
            WHERE date(ms.saleDate) BETWEEN :i AND :f 
            ORDER BY ms.saleDate DESC
        ");
        $q->execute([':i' => $inicio, ':f' => $fin]);
        while ($r = $q->fetch()) {
            fputcsv($out, [$r['id'], $r['saleDate'], $r['cliente'], $r['plan'], number_format($r['cost'], 2, '.', '')]);
        }
    } elseif ($tipo === 'pases_diarios') {
        fputcsv($out, ['ID', 'Fecha y Hora', 'Visitante', 'Costo Pase ($)']);
        $q = $pdo->prepare("
            SELECT id, checkInTime, visitorName, membershipCost 
            FROM temp_visits 
            WHERE date(checkInTime) BETWEEN :i AND :f 
            ORDER BY checkInTime DESC
        ");
        $q->execute([':i' => $inicio, ':f' => $fin]);
        while ($r = $q->fetch()) {
            fputcsv($out, [$r['id'], $r['checkInTime'], $r['visitorName'], number_format($r['membershipCost'], 2, '.', '')]);
        }
    } elseif ($tipo === 'gastos') {
        fputcsv($out, ['ID', 'Fecha', 'Descripcion', 'Registrado Por', 'Monto ($)']);
        $q = $pdo->prepare("
            SELECT e.id, e.expenseDate, e.description, u.name as usuario, e.cost 
            FROM expenses e 
            LEFT JOIN users u ON e.userId = u.id 
            WHERE e.expenseDate BETWEEN :i AND :f 
            ORDER BY e.expenseDate DESC
        ");
        $q->execute([':i' => $inicio, ':f' => $fin]);
        while ($r = $q->fetch()) {
            fputcsv($out, [$r['id'], $r['expenseDate'], $r['description'], $r['usuario'] ?? 'Sistema', number_format($r['cost'], 2, '.', '')]);
        }
    }
    fclose($out);
    exit;
}

// Carga de datos para mostrar en pantalla según el tipo seleccionado
$filas = [];
$totalMonto = 0;
$totalRegistros = 0;
$totalesDesglose = ['interno' => 0, 'externo' => 0];

if ($tipo === 'global_sales') {
    $filas = obtenerTodas("
        SELECT gs.id, gs.folio, gs.createdAt, u.name as cajero, COALESCE(c.fullName, 'Público General') as cliente, 
               gs.totalInternal, gs.totalExternal, gs.totalAmount, gs.paymentMethod 
        FROM global_sales gs 
        LEFT JOIN users u ON gs.userId = u.id 
        LEFT JOIN clients c ON gs.clientId = c.id 
        WHERE date(gs.createdAt) BETWEEN :i AND :f 
        ORDER BY gs.id DESC
    ", [':i' => $inicio, ':f' => $fin]);
    foreach ($filas as $r) {
        $totalMonto += (float)$r['totalAmount'];
        $totalesDesglose['interno'] += (float)$r['totalInternal'];
        $totalesDesglose['externo'] += (float)$r['totalExternal'];
    }
} elseif ($tipo === 'ventas_internas') {
    $filas = obtenerTodas("
        SELECT si.id, COALESCE(gs.folio, printf('%04d', si.saleId)) as folio, si.createdAt, u.name as cajero,
               si.productName, si.quantity, si.unitPrice, si.subtotal 
        FROM saleitems si 
        LEFT JOIN global_sales gs ON si.globalSaleId = gs.id 
        LEFT JOIN users u ON si.userId = u.id 
        WHERE date(si.createdAt) BETWEEN :i AND :f 
        ORDER BY si.id DESC
    ", [':i' => $inicio, ':f' => $fin]);
    foreach ($filas as $r) $totalMonto += (float)$r['subtotal'];
} elseif ($tipo === 'ventas_externas') {
    $filas = obtenerTodas("
        SELECT ve.id, gs.folio, ve.createdAt, u.name as cajero, ve.productName, ve.quantity, ve.unitPrice, ve.subtotal 
        FROM ventas_externas_realizadas ve 
        LEFT JOIN global_sales gs ON ve.globalSaleId = gs.id 
        LEFT JOIN users u ON ve.userId = u.id 
        WHERE date(ve.createdAt) BETWEEN :i AND :f 
        ORDER BY ve.id DESC
    ", [':i' => $inicio, ':f' => $fin]);
    foreach ($filas as $r) $totalMonto += (float)$r['subtotal'];
} elseif ($tipo === 'membresias') {
    $filas = obtenerTodas("
        SELECT ms.id, ms.saleDate, c.fullName as cliente, m.name as plan, ms.cost 
        FROM membership_sales ms 
        JOIN clients c ON ms.clientId = c.id 
        JOIN memberships m ON ms.membershipId = m.id 
        WHERE date(ms.saleDate) BETWEEN :i AND :f 
        ORDER BY ms.saleDate DESC
    ", [':i' => $inicio, ':f' => $fin]);
    foreach ($filas as $r) $totalMonto += (float)$r['cost'];
} elseif ($tipo === 'pases_diarios') {
    $filas = obtenerTodas("
        SELECT id, checkInTime, visitorName, membershipCost 
        FROM temp_visits 
        WHERE date(checkInTime) BETWEEN :i AND :f 
        ORDER BY checkInTime DESC
    ", [':i' => $inicio, ':f' => $fin]);
    foreach ($filas as $r) $totalMonto += (float)$r['membershipCost'];
} elseif ($tipo === 'gastos') {
    $filas = obtenerTodas("
        SELECT e.id, e.expenseDate, e.description, u.name as usuario, e.cost 
        FROM expenses e 
        LEFT JOIN users u ON e.userId = u.id 
        WHERE e.expenseDate BETWEEN :i AND :f 
        ORDER BY e.expenseDate DESC
    ", [':i' => $inicio, ':f' => $fin]);
    foreach ($filas as $r) $totalMonto += (float)$r['cost'];
} elseif ($tipo === 'balance') {
    $ingInternos = (float)obtenerFila("SELECT COALESCE(SUM(subtotal), 0) as t FROM saleitems WHERE date(createdAt) BETWEEN :i AND :f", [':i' => $inicio, ':f' => $fin])['t'];
    $ingExternos = (float)obtenerFila("SELECT COALESCE(SUM(subtotal), 0) as t FROM ventas_externas_realizadas WHERE date(createdAt) BETWEEN :i AND :f", [':i' => $inicio, ':f' => $fin])['t'];
    $ingMembresias = (float)obtenerFila("SELECT COALESCE(SUM(cost), 0) as t FROM membership_sales WHERE date(saleDate) BETWEEN :i AND :f", [':i' => $inicio, ':f' => $fin])['t'];
    $ingPases = (float)obtenerFila("SELECT COALESCE(SUM(membershipCost), 0) as t FROM temp_visits WHERE date(checkInTime) BETWEEN :i AND :f", [':i' => $inicio, ':f' => $fin])['t'];
    $totalGastos = (float)obtenerFila("SELECT COALESCE(SUM(cost), 0) as t FROM expenses WHERE expenseDate BETWEEN :i AND :f", [':i' => $inicio, ':f' => $fin])['t'];
    
    $totalIngresos = $ingInternos + $ingExternos + $ingMembresias + $ingPases;
    $balanceNeto = $totalIngresos - $totalGastos;
}

$totalRegistros = count($filas);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/css/style.css">
    <style>
        @media print {
            .navbar, .sidebar, .no-print { display: none !important; }
            main { width: 100% !important; margin: 0 !important; padding: 0 !important; }
            .card { border: none !important; box-shadow: none !important; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/navbar.php'; ?>
    <div class="container-fluid"><div class="row">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <main class="col-md-10 ms-sm-auto px-md-4 main-content">
        <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="fas fa-file-alt me-2"></i>Centro de Reportes</h1>
                <p class="text-muted mb-0 small">Genera reportes de ventas con folio, ventas internas, ventas externas, membresías y balance</p>
            </div>
            <div class="no-print d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                    <i class="fas fa-print me-1"></i> Imprimir Reporte
                </button>
            </div>
        </div>

        <!-- Formulario de Filtros de Reporte -->
        <div class="card p-3 mb-4 no-print shadow-sm">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-bold small text-muted">Tipo de Reporte:</label>
                    <select name="tipo" class="form-select fw-bold text-primary" id="selectTipo" onchange="this.form.submit()">
                        <option value="global_sales" <?php echo $tipo==='global_sales'?'selected':''; ?>>📑 Ventas Globales (Folios #0001)</option>
                        <option value="ventas_internas" <?php echo $tipo==='ventas_internas'?'selected':''; ?>>🛒 Ventas Productos Internos (Tienda)</option>
                        <option value="ventas_externas" <?php echo $tipo==='ventas_externas'?'selected':''; ?>>🏷️ Ventas Externas Realizadas (Cocas/Scoops)</option>
                        <option value="membresias" <?php echo $tipo==='membresias'?'selected':''; ?>>💳 Ventas de Membresías</option>
                        <option value="pases_diarios" <?php echo $tipo==='pases_diarios'?'selected':''; ?>>🎟️ Pases Diarios / Visitas</option>
                        <option value="gastos" <?php echo $tipo==='gastos'?'selected':''; ?>>💸 Gastos y Egresos Operativos</option>
                        <option value="balance" <?php echo $tipo==='balance'?'selected':''; ?>>⚖️ Balance Financiero Consolidado</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small text-muted">Desde:</label>
                    <input type="date" name="fecha_inicio" id="fecha_inicio" class="form-control" value="<?php echo $inicio; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small text-muted">Hasta:</label>
                    <input type="date" name="fecha_fin" id="fecha_fin" class="form-control" value="<?php echo $fin; ?>">
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-fill">
                        <i class="fas fa-search me-1"></i> Ver
                    </button>
                    <?php if ($tipo !== 'balance'): ?>
                    <a href="?tipo=<?php echo $tipo; ?>&fecha_inicio=<?php echo $inicio; ?>&fecha_fin=<?php echo $fin; ?>&export=csv" class="btn btn-success flex-fill" title="Descargar en Excel CSV">
                        <i class="fas fa-file-excel me-1"></i> CSV
                    </a>
                    <?php endif; ?>
                </div>
            </form>
            
            <!-- Botones de Rango Rápido -->
            <div class="d-flex gap-1 mt-2 flex-wrap">
                <span class="small text-muted align-self-center me-2">Accesos rápidos:</span>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setFechas('<?php echo date('Y-m-d'); ?>', '<?php echo date('Y-m-d'); ?>')">Hoy</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setFechas('<?php echo date('Y-m-d', strtotime('monday this week')); ?>', '<?php echo date('Y-m-d'); ?>')">Esta Semana</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setFechas('<?php echo date('Y-m-01'); ?>', '<?php echo date('Y-m-d'); ?>')">Este Mes</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setFechas('<?php echo date('Y-m-01', strtotime('first day of last month')); ?>', '<?php echo date('Y-m-t', strtotime('last month')); ?>')">Mes Anterior</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setFechas('<?php echo date('Y-01-01'); ?>', '<?php echo date('Y-m-d'); ?>')">Todo el Año</button>
            </div>
        </div>

        <!-- RESULTADOS DEL REPORTE -->
        <?php if ($tipo === 'balance'): ?>
            <!-- Vista de Balance Financiero Consolidado -->
            <div class="card mb-4 border-0 shadow-sm">
                <div class="card-header bg-primary text-white py-3">
                    <h5 class="mb-0"><i class="fas fa-scale-balanced me-2"></i> Balance del Periodo (<?php echo $inicio; ?> al <?php echo $fin; ?>)</h5>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="p-3 bg-success-subtle border border-success rounded">
                                <div class="text-success fw-bold small">TOTAL INGRESOS</div>
                                <div class="fs-2 fw-bold text-success font-monospace">$<?php echo number_format($totalIngresos, 2); ?></div>
                                <small class="text-muted">Internos + Externos + Membresías + Pases</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 bg-danger-subtle border border-danger rounded">
                                <div class="text-danger fw-bold small">TOTAL EGRESOS / GASTOS</div>
                                <div class="fs-2 fw-bold text-danger font-monospace">$<?php echo number_format($totalGastos, 2); ?></div>
                                <small class="text-muted">Gastos operativos registrados</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 <?php echo $balanceNeto >= 0 ? 'bg-primary-subtle border border-primary' : 'bg-danger-subtle border border-danger'; ?> rounded">
                                <div class="<?php echo $balanceNeto >= 0 ? 'text-primary' : 'text-danger'; ?> fw-bold small">UTILIDAD NETA</div>
                                <div class="fs-2 fw-bold font-monospace <?php echo $balanceNeto >= 0 ? 'text-primary' : 'text-danger'; ?>">
                                    $<?php echo number_format($balanceNeto, 2); ?>
                                </div>
                                <small class="text-muted">Diferencial neto del gimnasio</small>
                            </div>
                        </div>
                    </div>

                    <table class="table table-bordered">
                        <thead class="table-light"><tr><th>Concepto Financiero</th><th class="text-end">Monto ($)</th></tr></thead>
                        <tbody>
                            <tr>
                                <td><i class="fas fa-store text-primary me-2"></i>Ventas de Productos Internos (Tienda)</td>
                                <td class="text-end text-success font-monospace fw-bold">+$<?php echo number_format($ingInternos, 2); ?></td>
                            </tr>
                            <tr>
                                <td><i class="fas fa-tag text-warning-emphasis me-2"></i>Ventas de Productos Externos (Cocas, Scoops, Concesión)</td>
                                <td class="text-end text-success font-monospace fw-bold">+$<?php echo number_format($ingExternos, 2); ?></td>
                            </tr>
                            <tr>
                                <td><i class="fas fa-id-card text-success me-2"></i>Venta y Renovación de Membresías</td>
                                <td class="text-end text-success font-monospace fw-bold">+$<?php echo number_format($ingMembresias, 2); ?></td>
                            </tr>
                            <tr>
                                <td><i class="fas fa-ticket-alt text-info me-2"></i>Pases Diarios y Visitas Temporales</td>
                                <td class="text-end text-success font-monospace fw-bold">+$<?php echo number_format($ingPases, 2); ?></td>
                            </tr>
                            <tr class="table-danger">
                                <td><i class="fas fa-wallet text-danger me-2"></i>Gastos Operativos (Luz, Agua, Nómina, Insumos)</td>
                                <td class="text-end text-danger font-monospace fw-bold">-$<?php echo number_format($totalGastos, 2); ?></td>
                            </tr>
                            <tr class="table-light fw-bold fs-5">
                                <td>Resultado Financiero Neto</td>
                                <td class="text-end font-monospace <?php echo $balanceNeto >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    $<?php echo number_format($balanceNeto, 2); ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php else: ?>
            <!-- Métricas Resumen del Reporte -->
            <div class="row g-3 mb-4">
                <div class="col-md-<?php echo ($tipo === 'global_sales') ? '4' : '6'; ?>">
                    <div class="card p-3 border-start border-4 border-primary shadow-sm h-100">
                        <div class="text-muted small fw-bold">REGISTROS ENCONTRADOS</div>
                        <div class="fs-3 fw-bold text-dark"><?php echo $totalRegistros; ?> operaciones</div>
                        <small class="text-muted">Entre <?php echo $inicio; ?> y <?php echo $fin; ?></small>
                    </div>
                </div>

                <?php if ($tipo === 'global_sales'): ?>
                <div class="col-md-4">
                    <div class="card p-3 border-start border-4 border-info shadow-sm h-100">
                        <div class="text-muted small fw-bold">DESGLOSE INTERNO / EXTERNO</div>
                        <div class="small mt-1">
                            <div><span class="text-muted">Interno:</span> <strong class="font-monospace">$<?php echo number_format($totalesDesglose['interno'], 2); ?></strong></div>
                            <div><span class="text-warning-emphasis">Externo:</span> <strong class="font-monospace text-warning-emphasis">$<?php echo number_format($totalesDesglose['externo'], 2); ?></strong></div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="col-md-<?php echo ($tipo === 'global_sales') ? '4' : '6'; ?>">
                    <div class="card p-3 border-start border-4 <?php echo $tipo==='gastos'?'border-danger':'border-success'; ?> shadow-sm h-100">
                        <div class="text-muted small fw-bold">MONTO TOTAL <?php echo $tipo==='gastos'?'EGRESADO':'RECAUDADO'; ?></div>
                        <div class="fs-3 fw-bold font-monospace <?php echo $tipo==='gastos'?'text-danger':'text-success'; ?>">
                            $<?php echo number_format($totalMonto, 2); ?>
                        </div>
                        <small class="text-muted">Promedio por operación: $<?php echo $totalRegistros > 0 ? number_format($totalMonto / $totalRegistros, 2) : '0.00'; ?></small>
                    </div>
                </div>
            </div>

            <!-- Tabla Detallada de Resultados -->
            <div class="card shadow-sm">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <span class="fw-bold text-dark">
                        <i class="fas fa-table me-1"></i> Detalle de <?php 
                            if ($tipo === 'global_sales') echo 'Ventas Globales (Folios #0001)';
                            elseif ($tipo === 'ventas_internas') echo 'Ventas de Productos Internos';
                            elseif ($tipo === 'ventas_externas') echo 'Ventas Externas Realizadas';
                            elseif ($tipo === 'membresias') echo 'Ventas de Membresías';
                            elseif ($tipo === 'pases_diarios') echo 'Pases Diarios / Visitas';
                            elseif ($tipo === 'gastos') echo 'Gastos y Egresos';
                        ?>
                    </span>
                    <span class="badge bg-secondary"><?php echo $totalRegistros; ?> registros</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <?php if ($tipo === 'global_sales'): ?>
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
                            <?php elseif ($tipo === 'ventas_internas'): ?>
                                <tr>
                                    <th>ID</th>
                                    <th>Folio Global</th>
                                    <th>Fecha y Hora</th>
                                    <th>Cajero</th>
                                    <th>Producto Interno</th>
                                    <th class="text-center">Cant.</th>
                                    <th>Precio Unitario</th>
                                    <th class="text-end">Subtotal</th>
                                </tr>
                            <?php elseif ($tipo === 'ventas_externas'): ?>
                                <tr>
                                    <th>ID</th>
                                    <th>Folio Global</th>
                                    <th>Fecha y Hora</th>
                                    <th>Cajero</th>
                                    <th>Producto Externo</th>
                                    <th class="text-center">Cant.</th>
                                    <th>Precio Unitario</th>
                                    <th class="text-end">Subtotal</th>
                                </tr>
                            <?php elseif ($tipo === 'membresias'): ?>
                                <tr>
                                    <th>ID</th>
                                    <th>Fecha</th>
                                    <th>Socio</th>
                                    <th>Plan Adquirido</th>
                                    <th class="text-end">Monto</th>
                                </tr>
                            <?php elseif ($tipo === 'pases_diarios'): ?>
                                <tr>
                                    <th>ID</th>
                                    <th>Fecha y Hora</th>
                                    <th>Nombre Visitante</th>
                                    <th class="text-end">Costo Pase</th>
                                </tr>
                            <?php elseif ($tipo === 'gastos'): ?>
                                <tr>
                                    <th>ID</th>
                                    <th>Fecha</th>
                                    <th>Descripción del Gasto</th>
                                    <th>Registrado Por</th>
                                    <th class="text-end">Monto</th>
                                </tr>
                            <?php endif; ?>
                        </thead>
                        <tbody>
                            <?php if (empty($filas)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5 text-muted">
                                        <i class="fas fa-inbox fa-3x mb-3 text-secondary d-block"></i>
                                        No se encontraron registros en el rango de fechas seleccionado (<?php echo $inicio; ?> al <?php echo $fin; ?>).
                                        <div class="mt-2 small">Prueba ampliando las fechas o seleccionando otro tipo de reporte.</div>
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php if ($tipo === 'global_sales'): ?>
                                <?php foreach ($filas as $f): ?>
                                <tr>
                                    <td><span class="badge bg-dark font-monospace fs-6">#<?php echo htmlspecialchars($f['folio']); ?></span></td>
                                    <td><?php echo $f['createdAt']; ?></td>
                                    <td><i class="fas fa-user-circle text-muted me-1"></i><?php echo htmlspecialchars($f['cajero'] ?? 'Admin'); ?></td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($f['cliente']); ?></td>
                                    <td class="font-monospace text-primary">$<?php echo number_format($f['totalInternal'], 2); ?></td>
                                    <td class="font-monospace text-warning-emphasis">$<?php echo number_format($f['totalExternal'], 2); ?></td>
                                    <td><span class="badge bg-light text-dark border text-uppercase"><?php echo htmlspecialchars($f['paymentMethod']); ?></span></td>
                                    <td class="text-end fw-bold text-success font-monospace fs-6">$<?php echo number_format($f['totalAmount'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>

                            <?php elseif ($tipo === 'ventas_internas'): ?>
                                <?php foreach ($filas as $f): ?>
                                <tr>
                                    <td class="font-monospace text-muted">#<?php echo $f['id']; ?></td>
                                    <td><span class="badge bg-secondary font-monospace">#<?php echo htmlspecialchars($f['folio']); ?></span></td>
                                    <td><?php echo $f['createdAt']; ?></td>
                                    <td><?php echo htmlspecialchars($f['cajero'] ?? 'Admin'); ?></td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($f['productName']); ?></td>
                                    <td class="text-center"><span class="badge bg-primary"><?php echo $f['quantity']; ?></span></td>
                                    <td>$<?php echo number_format($f['unitPrice'], 2); ?></td>
                                    <td class="text-end fw-bold text-success font-monospace">$<?php echo number_format($f['subtotal'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>

                            <?php elseif ($tipo === 'ventas_externas'): ?>
                                <?php foreach ($filas as $f): ?>
                                <tr>
                                    <td class="font-monospace text-muted">#<?php echo $f['id']; ?></td>
                                    <td><span class="badge bg-dark font-monospace">#<?php echo htmlspecialchars($f['folio']); ?></span></td>
                                    <td><?php echo $f['createdAt']; ?></td>
                                    <td><?php echo htmlspecialchars($f['cajero'] ?? 'Admin'); ?></td>
                                    <td class="fw-bold text-warning-emphasis"><i class="fas fa-tag me-1"></i><?php echo htmlspecialchars($f['productName']); ?></td>
                                    <td class="text-center"><span class="badge bg-warning text-dark"><?php echo $f['quantity']; ?></span></td>
                                    <td>$<?php echo number_format($f['unitPrice'], 2); ?></td>
                                    <td class="text-end fw-bold text-warning-emphasis font-monospace">$<?php echo number_format($f['subtotal'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>

                            <?php elseif ($tipo === 'membresias'): ?>
                                <?php foreach ($filas as $f): ?>
                                <tr>
                                    <td class="font-monospace text-muted">#<?php echo $f['id']; ?></td>
                                    <td><?php echo $f['saleDate']; ?></td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($f['cliente']); ?></td>
                                    <td><span class="badge bg-primary fs-6"><?php echo htmlspecialchars($f['plan']); ?></span></td>
                                    <td class="text-end fw-bold text-success font-monospace fs-6">$<?php echo number_format($f['cost'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>

                            <?php elseif ($tipo === 'pases_diarios'): ?>
                                <?php foreach ($filas as $f): ?>
                                <tr>
                                    <td class="font-monospace text-muted">#<?php echo $f['id']; ?></td>
                                    <td><?php echo $f['checkInTime']; ?></td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($f['visitorName'] ?? 'Visitante Temporal'); ?></td>
                                    <td class="text-end fw-bold text-success font-monospace fs-6">$<?php echo number_format($f['membershipCost'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>

                            <?php elseif ($tipo === 'gastos'): ?>
                                <?php foreach ($filas as $f): ?>
                                <tr>
                                    <td class="font-monospace text-muted">#<?php echo $f['id']; ?></td>
                                    <td><?php echo $f['expenseDate']; ?></td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($f['description']); ?></td>
                                    <td><i class="fas fa-user text-muted me-1"></i><?php echo htmlspecialchars($f['usuario'] ?? 'Sistema'); ?></td>
                                    <td class="text-end fw-bold text-danger font-monospace fs-6">-$<?php echo number_format($f['cost'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </main></div></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function setFechas(i, f) {
        document.getElementById('fecha_inicio').value = i;
        document.getElementById('fecha_fin').value = f;
        document.getElementById('fecha_inicio').form.submit();
    }
    </script>
</body>
</html>
