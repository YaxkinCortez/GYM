<?php
require_once __DIR__ . '/../includes/config.php';
verificarAutenticacion();
$usuario = obtenerUsuarioActual();
$mensaje = ''; $tipo_mensaje = 'success';
$comprobanteAbono = null;

// Procesar Registro de Abono / Liquidación
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    if ($accion === 'registrar_abono') {
        try {
            $debtId = (int)$_POST['debtId'];
            $montoAbono = (float)$_POST['amount'];
            $metodo = in_array($_POST['paymentMethod'] ?? '', ['efectivo', 'tarjeta', 'transferencia']) ? $_POST['paymentMethod'] : 'efectivo';
            $notas = escapar($_POST['notes'] ?? '');

            if ($montoAbono <= 0) {
                throw new Exception("El monto a abonar debe ser mayor a cero.");
            }

            $deuda = obtenerFila("
                SELECT cd.*, c.fullName as cliente, c.phone 
                FROM client_debts cd 
                JOIN clients c ON cd.clientId = c.id 
                WHERE cd.id = :id
            ", [':id' => $debtId]);

            if (!$deuda) {
                throw new Exception("No se encontró el registro de adeudo.");
            }

            if ($deuda['status'] === 'liquidada' || (float)$deuda['remainingAmount'] <= 0) {
                throw new Exception("Esta deuda ya se encuentra completamente liquidada.");
            }

            if ($montoAbono > (float)$deuda['remainingAmount']) {
                $montoAbono = (float)$deuda['remainingAmount']; // Ajustar al saldo restante
            }

            $nuevoSaldo = (float)$deuda['remainingAmount'] - $montoAbono;
            $nuevoPagado = (float)$deuda['paidAmount'] + $montoAbono;
            $nuevoEstado = ($nuevoSaldo <= 0.001) ? 'liquidada' : 'parcial';

            $pdo->beginTransaction();

            // 1. Actualizar el adeudo
            actualizar('client_debts', [
                'paidAmount'      => $nuevoPagado,
                'remainingAmount' => max(0, $nuevoSaldo),
                'status'          => $nuevoEstado,
                'updatedAt'       => date('Y-m-d H:i:s')
            ], "id = :id", [':id' => $debtId]);

            // 2. Registrar el movimiento en debt_payments
            $paymentId = insertar('debt_payments', [
                'debtId'        => $debtId,
                'clientId'      => $deuda['clientId'],
                'amount'        => $montoAbono,
                'paymentMethod' => $metodo,
                'notes'         => $notas,
                'userId'        => $usuario['id'],
                'createdAt'     => date('Y-m-d H:i:s')
            ]);

            $pdo->commit();

            $mensaje = ($nuevoEstado === 'liquidada')
                ? "🎉 ¡Deuda liquidada exitosamente! Se abonaron $" . number_format($montoAbono, 2) . " para " . htmlspecialchars($deuda['cliente']) . "."
                : "✅ Abono de $" . number_format($montoAbono, 2) . " registrado con éxito. Saldo pendiente restante: $" . number_format($nuevoSaldo, 2) . ".";

            $comprobanteAbono = [
                'paymentId'     => $paymentId,
                'debtId'        => $debtId,
                'cliente'       => $deuda['cliente'],
                'descripcion'   => $deuda['description'],
                'montoAbonado'  => $montoAbono,
                'saldoAnterior' => (float)$deuda['remainingAmount'],
                'saldoRestante' => max(0, $nuevoSaldo),
                'estado'        => $nuevoEstado,
                'metodo'        => ucfirst($metodo),
                'cajero'        => $usuario['name'],
                'fechaHora'     => date('d/m/Y H:i:s')
            ];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $mensaje = "❌ Error: " . $e->getMessage();
            $tipo_mensaje = 'danger';
        }
    }
}

// Filtros y Búsqueda
$filtroEstado = $_GET['estado'] ?? 'pendientes';
$busqueda = trim($_GET['q'] ?? '');
$clienteFiltroId = !empty($_GET['cliente_id']) ? (int)$_GET['cliente_id'] : null;

$whereParts = [];
$params = [];

if ($filtroEstado === 'pendientes') {
    $whereParts[] = "cd.status != 'liquidada'";
} elseif ($filtroEstado === 'liquidadas') {
    $whereParts[] = "cd.status = 'liquidada'";
}

if ($clienteFiltroId) {
    $whereParts[] = "cd.clientId = :cid";
    $params[':cid'] = $clienteFiltroId;
}

if ($busqueda !== '') {
    $whereParts[] = "(c.fullName LIKE :q OR cd.description LIKE :q OR c.phone LIKE :q)";
    $params[':q'] = "%$busqueda%";
}

$whereSQL = !empty($whereParts) ? "WHERE " . implode(" AND ", $whereParts) : "";

// Métricas Resumen
$stats = obtenerFila("
    SELECT
        (SELECT COALESCE(SUM(remainingAmount), 0) FROM client_debts WHERE status != 'liquidada') as totalDeudaPendiente,
        (SELECT COALESCE(SUM(amount), 0) FROM debt_payments WHERE date(createdAt) = date('now', 'localtime')) as abonosHoy,
        (SELECT COUNT(DISTINCT clientId) FROM client_debts WHERE status != 'liquidada') as sociosConDeuda,
        (SELECT COUNT(*) FROM client_debts WHERE status = 'liquidada') as deudasLiquidadas
");

$totalDeudaPendiente = (float)($stats['totalDeudaPendiente'] ?? 0);
$abonosHoy           = (float)($stats['abonosHoy'] ?? 0);
$sociosConDeuda      = (int)($stats['sociosConDeuda'] ?? 0);
$deudasLiquidadas    = (int)($stats['deudasLiquidadas'] ?? 0);

// Lista de Deudas
$deudas = obtenerTodas("
    SELECT cd.*, c.fullName as cliente, c.phone, u.name as cajeroRegistro,
           COALESCE(gs.folio, printf('%04d', cd.globalSaleId)) as folioVenta
    FROM client_debts cd
    JOIN clients c ON cd.clientId = c.id
    LEFT JOIN users u ON cd.userId = u.id
    LEFT JOIN global_sales gs ON cd.globalSaleId = gs.id
    $whereSQL
    ORDER BY (cd.status = 'liquidada') ASC, cd.id DESC
", $params);

// Lista de socios con deudas activas para el selector de abono rápido
$sociosConDeudaLista = obtenerTodas("
    SELECT DISTINCT c.id, c.fullName, c.phone,
           (SELECT SUM(remainingAmount) FROM client_debts WHERE clientId = c.id AND status != 'liquidada') as deudaTotal
    FROM clients c
    JOIN client_debts cd ON c.id = cd.clientId
    WHERE cd.status != 'liquidada'
    ORDER BY c.fullName ASC
");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Abonos y Deudas - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/css/style.css">
    <style>
        .ticket-box { background: #fafafa; border: 1px dashed #bbb; padding: 15px; border-radius: 8px; font-family: 'Courier New', Courier, monospace; }
        @media print {
            .navbar, .sidebar, .no-print, .btn, .modal-footer { display: none !important; }
            main, .modal-dialog, .modal-content { border: none !important; box-shadow: none !important; width: 100% !important; margin: 0 !important; }
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
                <h1><i class="fas fa-hand-holding-dollar me-2 text-warning"></i>Gestión de Abonos y Deudas</h1>
                <p class="text-muted mb-0 small">Administra cuentas por cobrar, registra abonos parciales y liquida saldos de socios</p>
            </div>
            <button type="button" class="btn btn-warning fw-bold text-dark shadow-sm" onclick="abrirModalNuevoAbono()">
                <i class="fas fa-plus me-1"></i> Registrar Abono
            </button>
        </div>

        <?php if ($mensaje): ?>
            <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                <?php echo $mensaje; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- TARJETAS KPI RESUMEN DE CARTERA -->
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="stat-card border-start border-4 border-danger h-100 shadow-sm">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-danger small fw-bold text-uppercase">Deuda Total por Cobrar</div>
                            <h3 class="text-danger font-monospace mt-1 mb-0">$<?php echo number_format($totalDeudaPendiente, 2); ?></h3>
                        </div>
                        <i class="fas fa-file-invoice-dollar text-danger fa-2x opacity-75"></i>
                    </div>
                    <small class="text-muted d-block mt-2">Saldo total pendiente de liquidar</small>
                </div>
            </div>

            <div class="col-xl-3 col-md-6">
                <div class="stat-card border-start border-4 border-success h-100 shadow-sm">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-success small fw-bold text-uppercase">Abonos Cobrados Hoy</div>
                            <h3 class="text-success font-monospace mt-1 mb-0">$<?php echo number_format($abonosHoy, 2); ?></h3>
                        </div>
                        <i class="fas fa-money-bill-wave text-success fa-2x opacity-75"></i>
                    </div>
                    <small class="text-muted d-block mt-2">Ingresado a caja el día de hoy</small>
                </div>
            </div>

            <div class="col-xl-3 col-md-6">
                <div class="stat-card border-start border-4 border-warning h-100 shadow-sm">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-warning-emphasis small fw-bold text-uppercase">Socios con Deuda</div>
                            <h3 class="text-warning-emphasis font-monospace mt-1 mb-0"><?php echo $sociosConDeuda; ?></h3>
                        </div>
                        <i class="fas fa-users-slash text-warning-emphasis fa-2x opacity-75"></i>
                    </div>
                    <small class="text-muted d-block mt-2">Clientes con saldo pendiente</small>
                </div>
            </div>

            <div class="col-xl-3 col-md-6">
                <div class="stat-card border-start border-4 border-info h-100 shadow-sm">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-info small fw-bold text-uppercase">Deudas Liquidadas</div>
                            <h3 class="text-info font-monospace mt-1 mb-0"><?php echo $deudasLiquidadas; ?></h3>
                        </div>
                        <i class="fas fa-check-double text-info fa-2x opacity-75"></i>
                    </div>
                    <small class="text-muted d-block mt-2">Cuentas totalmente pagadas</small>
                </div>
            </div>
        </div>

        <!-- FILTROS Y BÚSQUEDA -->
        <div class="card mb-3 shadow-sm">
            <div class="card-body p-3">
                <form method="GET" class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                    <div class="btn-group" role="group">
                        <a href="?estado=pendientes<?php echo $busqueda ? '&q='.urlencode($busqueda) : ''; ?>" class="btn btn-sm <?php echo $filtroEstado==='pendientes'?'btn-danger':'btn-outline-danger'; ?>">
                            <i class="fas fa-clock me-1"></i> Pendientes / Activas
                        </a>
                        <a href="?estado=todos<?php echo $busqueda ? '&q='.urlencode($busqueda) : ''; ?>" class="btn btn-sm <?php echo $filtroEstado==='todos'?'btn-primary':'btn-outline-primary'; ?>">
                            <i class="fas fa-list me-1"></i> Todas las Cuentas
                        </a>
                        <a href="?estado=liquidadas<?php echo $busqueda ? '&q='.urlencode($busqueda) : ''; ?>" class="btn btn-sm <?php echo $filtroEstado==='liquidadas'?'btn-success':'btn-outline-success'; ?>">
                            <i class="fas fa-check-circle me-1"></i> Solo Liquidadas
                        </a>
                    </div>

                    <div class="d-flex gap-2 flex-grow-1" style="max-width: 450px;">
                        <input type="hidden" name="estado" value="<?php echo htmlspecialchars($filtroEstado); ?>">
                        <input type="text" name="q" class="form-control form-control-sm" placeholder="Buscar por socio, folio o concepto..." value="<?php echo htmlspecialchars($busqueda); ?>">
                        <button type="submit" class="btn btn-sm btn-secondary"><i class="fas fa-search"></i></button>
                        <?php if ($busqueda || $filtroEstado !== 'pendientes' || $clienteFiltroId): ?>
                            <a href="/pages/abonos.php" class="btn btn-sm btn-outline-secondary">Limpiar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- TABLA DE DEUDAS Y ABONOS -->
        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Fecha</th>
                            <th>Socio</th>
                            <th>Concepto</th>
                            <th class="text-end">Monto Original</th>
                            <th class="text-end">Abonado</th>
                            <th class="text-end">Saldo Pendiente</th>
                            <th class="text-center">Estado</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($deudas)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-5 text-muted">
                                    <i class="fas fa-check-circle fa-3x mb-2 text-success opacity-50 d-block"></i>
                                    No se encontraron deudas con los filtros actuales.
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($deudas as $d): 
                            $esLiquidada = ($d['status'] === 'liquidada');
                            $esParcial = ($d['status'] === 'parcial');
                        ?>
                        <tr class="<?php echo $esLiquidada ? 'table-light text-muted' : ($esParcial ? 'table-warning' : 'table-danger'); ?>">
                            <td class="font-monospace fw-bold">#<?php echo $d['id']; ?></td>
                            <td class="small font-monospace"><?php echo substr($d['createdAt'], 0, 16); ?></td>
                            <td>
                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($d['cliente']); ?></div>
                                <?php if ($d['phone']): ?><small class="text-muted"><i class="fas fa-phone small me-1"></i><?php echo htmlspecialchars($d['phone']); ?></small><?php endif; ?>
                            </td>
                            <td>
                                <div><?php echo htmlspecialchars($d['description']); ?></div>
                                <?php if (!empty($d['folioVenta'])): ?>
                                    <span class="badge bg-dark font-monospace small">Folio #<?php echo htmlspecialchars($d['folioVenta']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end font-monospace">$<?php echo number_format($d['totalAmount'], 2); ?></td>
                            <td class="text-end font-monospace text-success fw-bold">+$<?php echo number_format($d['paidAmount'], 2); ?></td>
                            <td class="text-end font-monospace fs-6 <?php echo $esLiquidada ? 'text-muted' : 'text-danger fw-bold'; ?>">
                                $<?php echo number_format($d['remainingAmount'], 2); ?>
                            </td>
                            <td class="text-center">
                                <?php if ($esLiquidada): ?>
                                    <span class="badge bg-success"><i class="fas fa-check-double me-1"></i>Liquidada</span>
                                <?php elseif ($esParcial): ?>
                                    <span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>Abono Parcial</span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="fas fa-exclamation-circle me-1"></i>Pendiente</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <?php if (!$esLiquidada): ?>
                                <button type="button" class="btn btn-sm btn-success fw-bold me-1" onclick='abrirAbonoEspecifico(<?php echo json_encode($d); ?>)' title="Abonar o liquidar este adeudo">
                                    <i class="fas fa-coins me-1"></i> Abonar
                                </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-sm btn-outline-info" onclick="verHistorialAbonos(<?php echo $d['id']; ?>, '<?php echo htmlspecialchars($d['cliente'], ENT_QUOTES); ?>')" title="Ver pagos realizados a esta deuda">
                                    <i class="fas fa-history"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main></div></div>

    <!-- MODAL REGISTRAR ABONO / LIQUIDAR -->
    <div class="modal fade" id="modalAbonar" tabindex="-1" aria-labelledby="modalAbonarLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark py-2">
                    <h5 class="modal-title fw-bold" id="modalAbonarLabel"><i class="fas fa-hand-holding-dollar me-2"></i> Registrar Abono de Deuda</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body p-3">
                        <input type="hidden" name="accion" value="registrar_abono">
                        <input type="hidden" name="debtId" id="abono_debtId">

                        <div class="p-3 bg-light rounded border mb-3">
                            <div class="small text-muted">Socio:</div>
                            <h5 class="fw-bold text-primary mb-1" id="abono_clienteNombre">-</h5>
                            <div class="small text-muted" id="abono_concepto">-</div>
                            <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
                                <span class="fw-bold text-muted small">Saldo Pendiente Actual:</span>
                                <span class="fs-4 fw-bold text-danger font-monospace" id="abono_saldoDisplay">$0.00</span>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold small text-muted">Monto a Abonar ($):</label>
                            <input type="number" step="0.01" min="0.50" name="amount" id="abono_amount" class="form-control form-control-lg font-monospace text-success fw-bold" required autofocus>
                            <div class="btn-group btn-group-sm w-100 mt-2" role="group">
                                <button type="button" class="btn btn-outline-danger fw-bold" onclick="abonarTodo()">Liquidar Todo</button>
                                <button type="button" class="btn btn-outline-secondary" onclick="sumarAbono(50)">$50</button>
                                <button type="button" class="btn btn-outline-secondary" onclick="sumarAbono(100)">$100</button>
                                <button type="button" class="btn btn-outline-secondary" onclick="sumarAbono(200)">$200</button>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold small text-muted">Método de Pago:</label>
                            <select name="paymentMethod" class="form-select">
                                <option value="efectivo">💵 Efectivo</option>
                                <option value="tarjeta">💳 Tarjeta (Terminal Bancaria)</option>
                                <option value="transferencia">📲 Transferencia / SPEI</option>
                            </select>
                        </div>

                        <div class="mb-2">
                            <label class="form-label small fw-bold text-muted">Notas / Observaciones (Opcional):</label>
                            <input type="text" name="notes" class="form-control form-control-sm" placeholder="Ej: Pago parcial, prometió liquidar el viernes...">
                        </div>
                    </div>
                    <div class="modal-footer py-2">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success fw-bold"><i class="fas fa-check-circle me-1"></i> Confirmar Abono</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL SELECCIONAR CLIENTE PARA ABONO RÁPIDO -->
    <div class="modal fade" id="modalSeleccionarDeuda" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white py-2">
                    <h5 class="modal-title"><i class="fas fa-users me-2"></i> Seleccionar Socio con Deuda Activa</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3">
                    <?php if (empty($sociosConDeudaLista)): ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-check-circle fa-3x text-success mb-2"></i>
                            <div>¡Excelente! No hay socios con deudas pendientes registradas.</div>
                        </div>
                    <?php else: ?>
                        <p class="small text-muted mb-2">Haz clic en el socio para ver sus deudas pendientes:</p>
                        <div class="list-group">
                            <?php foreach ($sociosConDeudaLista as $s): ?>
                                <a href="?cliente_id=<?php echo $s['id']; ?>&estado=pendientes" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo htmlspecialchars($s['fullName']); ?></strong>
                                        <?php if ($s['phone']): ?><div class="small text-muted"><?php echo htmlspecialchars($s['phone']); ?></div><?php endif; ?>
                                    </div>
                                    <span class="badge bg-danger fs-6 font-monospace">Deuda: $<?php echo number_format($s['deudaTotal'], 2); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL COMPROBANTE DE ABONO EXITOSO -->
    <?php if ($comprobanteAbono): ?>
    <div class="modal fade" id="modalTicketAbono" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-success text-white py-2">
                    <h5 class="modal-title"><i class="fas fa-receipt me-2"></i> Comprobante de Abono</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3">
                    <div class="ticket-box">
                        <div class="text-center mb-2 pb-2 border-bottom">
                            <h5 class="fw-bold mb-0"><?php echo APP_NAME; ?></h5>
                            <small class="text-muted d-block">RECIBO OFICIAL DE ABONO A CUENTA</small>
                            <span class="badge bg-dark font-monospace mt-1">RECIBO #<?php echo $comprobanteAbono['paymentId']; ?></span>
                        </div>
                        <div class="small mb-2">
                            <div><strong>Fecha/Hora:</strong> <?php echo $comprobanteAbono['fechaHora']; ?></div>
                            <div><strong>Socio:</strong> <?php echo htmlspecialchars($comprobanteAbono['cliente']); ?></div>
                            <div><strong>Concepto:</strong> <?php echo htmlspecialchars($comprobanteAbono['descripcion']); ?></div>
                            <div><strong>Atendió:</strong> <?php echo htmlspecialchars($comprobanteAbono['cajero']); ?></div>
                            <div><strong>Método:</strong> <?php echo $comprobanteAbono['metodo']; ?></div>
                        </div>
                        <div class="p-2 border rounded bg-white small mb-2">
                            <div class="d-flex justify-content-between">
                                <span>Saldo Anterior:</span>
                                <span class="font-monospace text-muted">$<?php echo number_format($comprobanteAbono['saldoAnterior'], 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between fs-5 fw-bold text-success my-1">
                                <span>MONTO ABONADO:</span>
                                <span class="font-monospace">+$<?php echo number_format($comprobanteAbono['montoAbonado'], 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between fw-bold border-top pt-1 <?php echo $comprobanteAbono['saldoRestante'] <= 0 ? 'text-success' : 'text-danger'; ?>">
                                <span>SALDO RESTANTE:</span>
                                <span class="font-monospace">$<?php echo number_format($comprobanteAbono['saldoRestante'], 2); ?></span>
                            </div>
                        </div>
                        <div class="text-center small mt-2">
                            <?php if ($comprobanteAbono['saldoRestante'] <= 0): ?>
                                <span class="badge bg-success py-1 px-3">🎉 ¡CUENTA TOTALMENTE LIQUIDADA!</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">PAGO PARCIAL APLICADO</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" class="btn btn-primary" onclick="window.print()"><i class="fas fa-print me-1"></i> Imprimir</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- MODAL HISTORIAL DE ABONOS DE UNA DEUDA -->
    <div class="modal fade" id="modalHistorial" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white py-2">
                    <h5 class="modal-title" id="historialTitulo"><i class="fas fa-history me-2"></i> Historial de Abonos</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3" id="historialCuerpo">
                    <div class="text-center py-3 text-muted">Cargando pagos...</div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    let saldoActualDeuda = 0;

    function abrirModalNuevoAbono() {
        var modal = new bootstrap.Modal(document.getElementById('modalSeleccionarDeuda'));
        modal.show();
    }

    function abrirAbonoEspecifico(deuda) {
        document.getElementById('abono_debtId').value = deuda.id;
        document.getElementById('abono_clienteNombre').textContent = deuda.cliente;
        document.getElementById('abono_concepto').textContent = deuda.description + (deuda.folioVenta ? ' (Folio #' + deuda.folioVenta + ')' : '');
        
        saldoActualDeuda = parseFloat(deuda.remainingAmount) || 0;
        document.getElementById('abono_saldoDisplay').textContent = '$' + saldoActualDeuda.toFixed(2);
        document.getElementById('abono_amount').value = saldoActualDeuda.toFixed(2);
        document.getElementById('abono_amount').max = saldoActualDeuda.toFixed(2);

        var modal = new bootstrap.Modal(document.getElementById('modalAbonar'));
        modal.show();
    }

    function abonarTodo() {
        document.getElementById('abono_amount').value = saldoActualDeuda.toFixed(2);
    }

    function sumarAbono(val) {
        const monto = Math.min(saldoActualDeuda, val);
        document.getElementById('abono_amount').value = monto.toFixed(2);
    }

    function verHistorialAbonos(debtId, cliente) {
        document.getElementById('historialTitulo').innerHTML = '<i class="fas fa-history me-2"></i> Abonos de: ' + cliente;
        document.getElementById('historialCuerpo').innerHTML = '<div class="text-center py-3 text-muted">Cargando abonos...</div>';

        var modal = new bootstrap.Modal(document.getElementById('modalHistorial'));
        modal.show();

        fetch('/pages/abonos_ajax.php?debtId=' + debtId)
            .then(r => r.json())
            .then(data => {
                if (!data || data.length === 0) {
                    document.getElementById('historialCuerpo').innerHTML = '<div class="text-center py-3 text-muted">No se han registrado abonos a esta deuda aún.</div>';
                    return;
                }
                let html = '<div class="list-group list-group-flush">';
                data.forEach(p => {
                    html += '<div class="list-group-item d-flex justify-content-between align-items-center py-2">' +
                        '<div>' +
                            '<strong class="text-success fs-6 font-monospace">+$' + parseFloat(p.amount).toFixed(2) + '</strong>' +
                            '<small class="text-muted d-block">' + p.createdAt + ' | ' + p.paymentMethod.toUpperCase() + '</small>' +
                            (p.notes ? '<small class="text-muted italic">Nota: ' + p.notes + '</small>' : '') +
                        '</div>' +
                        '<div class="text-end"><span class="badge bg-light text-dark border">Cajero: ' + (p.cajero || 'Admin') + '</span></div>' +
                    '</div>';
                });
                html += '</div>';
                document.getElementById('historialCuerpo').innerHTML = html;
            })
            .catch(err => {
                document.getElementById('historialCuerpo').innerHTML = '<div class="alert alert-danger py-2 small">Error al cargar historial.</div>';
            });
    }

    <?php if ($comprobanteAbono): ?>
    document.addEventListener('DOMContentLoaded', function() {
        var modalEl = document.getElementById('modalTicketAbono');
        if (modalEl) {
            var modal = new bootstrap.Modal(modalEl);
            modal.show();
        }
    });
    <?php endif; ?>
    </script>
</body>
</html>
