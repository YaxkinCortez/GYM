<?php
require_once __DIR__ . '/../includes/config.php';
verificarAutenticacion();
$usuario = obtenerUsuarioActual();

if ($usuario['role'] !== 'admin') {
    header("Location: acceso_restringido.php");
    exit();
}

$hoy = date('Y-m-d');
$inicio = escapar($_GET['fecha_inicio'] ?? date('Y-m-01'));
$fin = escapar($_GET['fecha_fin'] ?? $hoy);
$incluir_credito = isset($_GET['incluir_credito']) && $_GET['incluir_credito'] == '1';

// 1. Ingresos cobrados en caja
$ing_prod = (float)obtenerFila("SELECT COALESCE(SUM(finalAmount),0) as t FROM sales WHERE date(createdAt) BETWEEN :i AND :f", [':i' => $inicio, ':f' => $fin])['t'];
$ing_mem = (float)obtenerFila("SELECT COALESCE(SUM(cost),0) as t FROM membership_sales WHERE clientId IS NOT NULL AND date(saleDate) BETWEEN :i AND :f", [':i' => $inicio, ':f' => $fin])['t'];
$ing_temp = (float)obtenerFila("SELECT COALESCE(SUM(membershipCost),0) as t FROM temp_visits WHERE date(checkInTime) BETWEEN :i AND :f", [':i' => $inicio, ':f' => $fin])['t'];

// 2. Gastos
$gastos = (float)obtenerFila("SELECT COALESCE(SUM(cost),0) as t FROM expenses WHERE expenseDate BETWEEN :i AND :f", [':i' => $inicio, ':f' => $fin])['t'];

// 3. Deudas por cobrar / Crédito otorgado en el período
$deudas_fila = obtenerFila("SELECT COALESCE(SUM(remainingAmount),0) as pendiente, COALESCE(SUM(totalAmount),0) as otorgado, COALESCE(SUM(paidAmount),0) as abonado FROM client_debts WHERE date(createdAt) BETWEEN :i AND :f", [':i' => $inicio, ':f' => $fin]);
$credito_otorgado = (float)($deudas_fila['otorgado'] ?? 0);
$deudas_pendientes = (float)($deudas_fila['pendiente'] ?? 0);
$credito_abonado = (float)($deudas_fila['abonado'] ?? 0);

// Lista de deudores en el período
$lista_deudas = obtenerTodas("SELECT d.*, c.fullName as clientName FROM client_debts d LEFT JOIN clients c ON d.clientId = c.id WHERE date(d.createdAt) BETWEEN :i AND :f ORDER BY d.id DESC", [':i' => $inicio, ':f' => $fin]);

// 4. Cálculos de balance
$total_ingresos_cobrados = $ing_prod + $ing_mem + $ing_temp;
$balance_neto_cobrado = $total_ingresos_cobrados - $gastos;

$monto_credito_sumado = $incluir_credito ? $deudas_pendientes : 0;
$balance_final = $balance_neto_cobrado + $monto_credito_sumado;
$color = $balance_final >= 0 ? 'text-success' : 'text-danger';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Balance Financiero - <?php echo APP_NAME; ?></title>
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
                        <h1><i class="fas fa-scale-balanced me-2 text-primary"></i>Balance Financiero & Estado de Resultados</h1>
                        <p class="text-muted mb-0 small">Liquidación de ingresos recaudados, crédito otorgado por cobrar y gastos operativos</p>
                    </div>
                    <span class="badge bg-secondary font-monospace"><i class="fas fa-lock me-1"></i>Acceso Administrador</span>
                </div>

                <!-- Formulario de Filtros de Fecha e Interruptor de Crédito -->
                <div class="card mb-4 shadow-sm border-0">
                    <div class="card-body p-3">
                        <form method="GET" class="row g-3 align-items-end" id="formBalance">
                            <div class="col-md-3">
                                <label class="form-label small fw-bold text-muted mb-1">Fecha Desde:</label>
                                <input type="date" name="fecha_inicio" value="<?php echo htmlspecialchars($inicio); ?>" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-bold text-muted mb-1">Fecha Hasta:</label>
                                <input type="date" name="fecha_fin" value="<?php echo htmlspecialchars($fin); ?>" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-4">
                                <div class="form-check form-switch p-2 bg-light border rounded">
                                    <input class="form-check-input ms-0 me-2" type="checkbox" role="switch" id="switchCredito" name="incluir_credito" value="1" <?php echo $incluir_credito ? 'checked' : ''; ?> onchange="this.form.submit()">
                                    <label class="form-check-label small fw-bold text-dark cursor-pointer" for="switchCredito">
                                        ¿Sumar Deudas por Cobrar (Crédito) al Balance?
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-sm btn-primary w-100 fw-bold">
                                    <i class="fas fa-filter me-1"></i> Actualizar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Banner Informativo del Modo de Balance -->
                <div class="alert <?php echo $incluir_credito ? 'alert-primary border-primary' : 'alert-light border'; ?> d-flex align-items-center justify-content-between p-3 mb-4 shadow-2xs">
                    <div class="d-flex align-items-center gap-3">
                        <i class="fas <?php echo $incluir_credito ? 'fa-hand-holding-dollar fa-2x text-primary' : 'fa-cash-register fa-2x text-secondary'; ?>"></i>
                        <div>
                            <strong class="d-block">
                                <?php echo $incluir_credito 
                                    ? 'Modo Balance Total Devengado (Ingresos Cobrados + Cuentas por Cobrar - Gastos)' 
                                    : 'Modo Flujo de Caja Real (Solo Cobrado en Mano - Gastos)'; ?>
                            </strong>
                            <small class="text-muted">
                                <?php echo $incluir_credito 
                                    ? 'Se están sumando +$' . number_format($deudas_pendientes, 2) . ' de adeudos pendientes otorgados a socios en este período.'
                                    : 'Las deudas por cobrar se muestran a título informativo sin alterar el efectivo cobrado en caja.'; ?>
                            </small>
                        </div>
                    </div>
                    <span class="badge <?php echo $incluir_credito ? 'bg-primary' : 'bg-secondary'; ?> p-2 fs-7">
                        <?php echo $incluir_credito ? '✅ Crédito Sumado' : '⭕ Solo Cobrado'; ?>
                    </span>
                </div>

                <!-- 5 Tarjetas de Conceptos Clave -->
                <div class="row g-3 mb-4">
                    <div class="col-md">
                        <div class="card p-3 text-center border-start border-primary border-4 shadow-2xs h-100">
                            <h6 class="small text-muted text-uppercase fw-bold mb-1"><i class="fas fa-box me-1 text-primary"></i> Tienda</h6>
                            <h4 class="text-primary fw-bold font-monospace mb-0">$<?php echo number_format($ing_prod, 2); ?></h4>
                            <small class="text-muted">Ventas mostrador</small>
                        </div>
                    </div>
                    <div class="col-md">
                        <div class="card p-3 text-center border-start border-info border-4 shadow-2xs h-100">
                            <h6 class="small text-muted text-uppercase fw-bold mb-1"><i class="fas fa-id-card me-1 text-info"></i> Membresías</h6>
                            <h4 class="text-info fw-bold font-monospace mb-0">$<?php echo number_format($ing_mem, 2); ?></h4>
                            <small class="text-muted">Planes cobrados</small>
                        </div>
                    </div>
                    <div class="col-md">
                        <div class="card p-3 text-center border-start border-warning border-4 shadow-2xs h-100">
                            <h6 class="small text-muted text-uppercase fw-bold mb-1"><i class="fas fa-ticket me-1 text-warning"></i> Visitas</h6>
                            <h4 class="text-warning fw-bold font-monospace mb-0">$<?php echo number_format($ing_temp, 2); ?></h4>
                            <small class="text-muted">Pases diarios</small>
                        </div>
                    </div>
                    <div class="col-md">
                        <div class="card p-3 text-center border-start border-danger border-4 shadow-2xs h-100">
                            <h6 class="small text-muted text-uppercase fw-bold mb-1"><i class="fas fa-wallet me-1 text-danger"></i> Gastos</h6>
                            <h4 class="text-danger fw-bold font-monospace mb-0">-$<?php echo number_format($gastos, 2); ?></h4>
                            <small class="text-muted">Egresos de caja</small>
                        </div>
                    </div>
                    <div class="col-md">
                        <div class="card p-3 text-center border-start border-purple border-4 shadow-2xs h-100 <?php echo $incluir_credito ? 'bg-primary-subtle' : ''; ?>">
                            <h6 class="small text-muted text-uppercase fw-bold mb-1"><i class="fas fa-hand-holding-dollar me-1 text-purple"></i> Por Cobrar</h6>
                            <h4 class="text-purple fw-bold font-monospace mb-0">$<?php echo number_format($deudas_pendientes, 2); ?></h4>
                            <small class="<?php echo $incluir_credito ? 'text-primary fw-bold' : 'text-muted'; ?>">
                                <?php echo $incluir_credito ? '➕ Sumado al balance' : 'Otorgado: $' . number_format($credito_otorgado, 2); ?>
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Tarjeta Central de Balance Final -->
                <div class="card text-center p-4 mb-4 shadow-sm border">
                    <span class="text-muted text-uppercase fw-bold small">
                        <?php echo $incluir_credito 
                            ? 'Balance Total del Período (Con Créditos Incluidos)' 
                            : 'Balance Neto de Flujo de Caja Recaudado'; ?>
                    </span>
                    <h1 class="<?php echo $color; ?> fw-bold font-monospace display-5 my-2">
                        <?php echo $balance_final >= 0 ? '+' : ''; ?>$<?php echo number_format($balance_final, 2); ?>
                    </h1>
                    <p class="text-muted small mb-0">
                        Total Cobrado: <strong class="text-success">$<?php echo number_format($total_ingresos_cobrados, 2); ?></strong>
                        <?php if ($incluir_credito): ?>
                            + Crédito por Cobrar: <strong class="text-primary">$<?php echo number_format($deudas_pendientes, 2); ?></strong>
                        <?php endif; ?>
                        | Gastos: <strong class="text-danger">-$<?php echo number_format($gastos, 2); ?></strong>
                    </p>
                </div>

                <!-- Tabla de Desglose de Movimientos -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light py-2 fw-bold small text-uppercase">
                        <i class="fas fa-list-check me-1"></i> Desglose Consolidado de Movimientos
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th>Concepto</th>
                                    <th class="text-end">Monto</th>
                                    <th class="text-center">Tipo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Ventas en Mostrador / Tienda (Productos y Suplementos)</td>
                                    <td class="text-end font-monospace text-success fw-bold">+$<?php echo number_format($ing_prod, 2); ?></td>
                                    <td class="text-center"><span class="badge bg-success">Cobrado</span></td>
                                </tr>
                                <tr>
                                    <td>Inscripciones y Renovaciones de Membresías de Socios</td>
                                    <td class="text-end font-monospace text-success fw-bold">+$<?php echo number_format($ing_mem, 2); ?></td>
                                    <td class="text-center"><span class="badge bg-success">Cobrado</span></td>
                                </tr>
                                <tr>
                                    <td>Pases Diarios / Visitas Temporales</td>
                                    <td class="text-end font-monospace text-success fw-bold">+$<?php echo number_format($ing_temp, 2); ?></td>
                                    <td class="text-center"><span class="badge bg-success">Cobrado</span></td>
                                </tr>
                                <tr>
                                    <td>Gastos Operativos (Servicios, limpieza, mantenimiento)</td>
                                    <td class="text-end font-monospace text-danger fw-bold">-$<?php echo number_format($gastos, 2); ?></td>
                                    <td class="text-center"><span class="badge bg-danger">Egreso</span></td>
                                </tr>
                                <tr class="<?php echo $incluir_credito ? 'table-primary' : ''; ?>">
                                    <td>
                                        <strong>Cuentas por Cobrar (Crédito Otorgado Pendiente)</strong><br>
                                        <small class="text-muted">Total otorgado en período: $<?php echo number_format($credito_otorgado, 2); ?> | Recuperado: $<?php echo number_format($credito_abonado, 2); ?></small>
                                    </td>
                                    <td class="text-end font-monospace fw-bold <?php echo $incluir_credito ? 'text-primary' : 'text-muted'; ?>">
                                        <?php echo $incluir_credito ? '+$' . number_format($deudas_pendientes, 2) : '($' . number_format($deudas_pendientes, 2) . ')'; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($incluir_credito): ?>
                                            <span class="badge bg-primary">Sumado al Balance</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Omitido (No Sumado)</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="table-light fw-bold">
                                <tr>
                                    <td class="text-uppercase"><?php echo $incluir_credito ? 'BALANCE TOTAL (CON CRÉDITO SUMADO)' : 'BALANCE NETO COBRADO EN MANO'; ?></td>
                                    <td class="text-end font-monospace fs-6 <?php echo $color; ?>">$<?php echo number_format($balance_final, 2); ?></td>
                                    <td class="text-center"><?php echo $balance_final >= 0 ? '<span class="badge bg-success">Superávit</span>' : '<span class="badge bg-danger">Déficit</span>'; ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- Detalle de Socios con Deudas en el Período -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                        <span class="fw-bold small text-uppercase text-secondary">
                            <i class="fas fa-hand-holding-dollar me-1 text-primary"></i> Detalle de Crédito Otorgado a Socios (<?php echo count($lista_deudas); ?> registros)
                        </span>
                        <a href="/pages/abonos.php" class="btn btn-sm btn-outline-primary fw-bold">
                            <i class="fas fa-external-link-alt me-1"></i> Ir a Abonos y Deudas
                        </a>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th>Socio Deudor</th>
                                    <th>Concepto</th>
                                    <th class="text-center">Fecha</th>
                                    <th class="text-end">Crédito Otorgado</th>
                                    <th class="text-end">Abonado</th>
                                    <th class="text-end">Saldo Pendiente</th>
                                    <th class="text-center">Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($lista_deudas)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">
                                        No se registran créditos otorgados en las fechas seleccionadas.
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <?php foreach ($lista_deudas as $d): ?>
                                <tr>
                                    <td class="fw-bold"><?php echo htmlspecialchars($d['clientName'] ?? "Socio #{$d['clientId']}"); ?></td>
                                    <td><?php echo htmlspecialchars($d['description']); ?></td>
                                    <td class="text-center font-monospace"><?php echo substr($d['createdAt'], 0, 10); ?></td>
                                    <td class="text-end font-monospace">$<?php echo number_format($d['totalAmount'], 2); ?></td>
                                    <td class="text-end font-monospace text-success">$<?php echo number_format($d['paidAmount'], 2); ?></td>
                                    <td class="text-end font-monospace fw-bold text-danger">$<?php echo number_format($d['remainingAmount'], 2); ?></td>
                                    <td class="text-center">
                                        <span class="badge <?php echo $d['status'] === 'liquidada' ? 'bg-success' : 'bg-danger'; ?>">
                                            <?php echo ucfirst($d['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </main>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
