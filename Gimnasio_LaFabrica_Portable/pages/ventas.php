<?php
require_once __DIR__ . '/../includes/config.php';
verificarAutenticacion();
$usuario = obtenerUsuarioActual();
$mensaje = ''; $tipo_mensaje = '';
$ticketData = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear_venta') {
    try {
        $items = json_decode($_POST['items'] ?? '[]', true);
        if (empty($items)) throw new Exception("El carrito está vacío");

        $metodo = in_array($_POST['paymentMethod'] ?? '', ['efectivo', 'tarjeta', 'transferencia', 'credito']) ? $_POST['paymentMethod'] : 'efectivo';
        $clientId = !empty($_POST['clientId']) ? (int)$_POST['clientId'] : null;

        if ($metodo === 'credito' && empty($clientId)) {
            throw new Exception("Para registrar una compra a crédito (deuda pendiente), debes buscar y seleccionar a un socio registrado.");
        }

        $internalItems = [];
        $externalItems = [];
        $totalInternal = 0.0;
        $totalExternal = 0.0;

        foreach ($items as $it) {
            $isExt = !empty($it['isExternal']) && (int)$it['isExternal'] === 1;
            if ($isExt) {
                $externalItems[] = $it;
                $totalExternal += (float)$it['subtotal'];
            } else {
                $internalItems[] = $it;
                $totalInternal += (float)$it['subtotal'];
            }
        }

        $totalGlobal = $totalInternal + $totalExternal;
        $fechaHora = date('Y-m-d H:i:s');

        $pdo->beginTransaction();

        // Generar folio autoincrementable con formato 0001
        $maxRow = obtenerFila("SELECT MAX(id) as maxId FROM global_sales");
        $nextId = ((int)($maxRow['maxId'] ?? 0)) + 1;
        $folio = sprintf('%04d', $nextId);

        // 1. Guardar Transacción en Tabla de Ventas Globales
        $globalSaleId = insertar('global_sales', [
            'folio'         => $folio,
            'userId'        => $usuario['id'],
            'clientId'      => $clientId,
            'totalAmount'   => $totalGlobal,
            'totalInternal' => $totalInternal,
            'totalExternal' => $totalExternal,
            'paymentMethod' => $metodo,
            'notes'         => '',
            'createdAt'     => $fechaHora
        ]);

        // 2. Procesar y guardar productos internos en tabla 'sales' y 'saleitems'
        $saleId = null;
        if (!empty($internalItems)) {
            // Manejo a prueba de fallos: la tabla legado 'sales' en bases SQLite existentes puede tener
            // la restricción CHECK(paymentMethod IN ('efectivo', 'tarjeta', 'transferencia')).
            // Para 'credito', usamos 'efectivo' en la tabla legado 'sales' con nota explicativa,
            // garantizando que nunca falle por CHECK constraint violation, mientras que la tabla maestra
            // 'global_sales' y 'client_debts' registran 'credito' y la deuda del cliente con folio.
            $metodoParaSales = ($metodo === 'credito') ? 'efectivo' : $metodo;
            $notasSales = ($metodo === 'credito') ? "A crédito (deuda registrada en Folio #{$folio})" : '';

            $saleId = insertar('sales', [
                'globalSaleId'  => $globalSaleId,
                'clientId'      => $clientId,
                'userId'        => $usuario['id'],
                'totalAmount'   => $totalInternal,
                'discountAmount'=> 0.00,
                'finalAmount'   => $totalInternal,
                'paymentMethod' => $metodoParaSales,
                'notes'         => $notasSales,
                'createdAt'     => $fechaHora
            ]);

            foreach ($internalItems as $item) {
                $qty = (int)$item['quantity'];
                $prodId = (int)$item['productId'];

                // Descuento atómico de stock
                $stmt = $pdo->prepare("UPDATE products SET quantity = quantity - :qty WHERE id = :id AND quantity >= :qty");
                $stmt->execute([':qty' => $qty, ':id' => $prodId]);
                if ($stmt->rowCount() === 0) {
                    throw new Exception("Stock insuficiente para producto interno: " . $item['productName']);
                }

                insertar('saleitems', [
                    'saleId'       => $saleId,
                    'globalSaleId' => $globalSaleId,
                    'productId'    => $prodId,
                    'productName'  => $item['productName'],
                    'quantity'     => $qty,
                    'unitPrice'    => (float)$item['unitPrice'],
                    'subtotal'     => (float)$item['subtotal'],
                    'userId'       => $usuario['id'],
                    'createdAt'    => $fechaHora
                ]);
            }
        }

        // 4. Procesar y guardar productos externos en tabla 'ventas_externas_realizadas'
        if (!empty($externalItems)) {
            foreach ($externalItems as $item) {
                $qty = (int)$item['quantity'];
                $prodId = (int)$item['productId'];

                // Descuento atómico de stock
                $stmt = $pdo->prepare("UPDATE products SET quantity = quantity - :qty WHERE id = :id AND quantity >= :qty");
                $stmt->execute([':qty' => $qty, ':id' => $prodId]);
                if ($stmt->rowCount() === 0) {
                    throw new Exception("Stock insuficiente para producto externo: " . $item['productName']);
                }

                insertar('ventas_externas_realizadas', [
                    'globalSaleId' => $globalSaleId,
                    'productId'    => $prodId,
                    'productName'  => $item['productName'],
                    'quantity'     => $qty,
                    'unitPrice'    => (float)$item['unitPrice'],
                    'subtotal'     => (float)$item['subtotal'],
                    'userId'       => $usuario['id'],
                    'createdAt'    => $fechaHora
                ]);
            }
        }

        // 5. Si la venta fue a crédito (deuda), registrar en tabla 'client_debts'
        if ($metodo === 'credito' && $clientId) {
            $conceptoDeuda = "Compra de productos POS Folio #{$folio}";
            insertar('client_debts', [
                'clientId'        => $clientId,
                'globalSaleId'    => $globalSaleId,
                'description'     => $conceptoDeuda,
                'totalAmount'     => $totalGlobal,
                'paidAmount'      => 0.00,
                'remainingAmount' => $totalGlobal,
                'status'          => 'pendiente',
                'userId'          => $usuario['id'],
                'createdAt'       => $fechaHora,
                'updatedAt'       => $fechaHora
            ]);
        }

        $pdo->commit();

        $clienteObj = $clientId ? obtenerFila("SELECT fullName FROM clients WHERE id = :id", [':id' => $clientId]) : null;
        $nombreClienteTicket = $clienteObj ? $clienteObj['fullName'] : 'Público General';

        $mensaje = ($metodo === 'credito')
            ? "🎉 Deuda registrada exitosamente para {$nombreClienteTicket} por $" . number_format($totalGlobal, 2) . " (Folio #{$folio})"
            : "✅ Venta Folio #{$folio} cobrada exitosamente ($" . number_format($totalGlobal, 2) . ")";
        $tipo_mensaje = 'success';

        $ticketData = [
            'folio'         => $folio,
            'globalSaleId'  => $globalSaleId,
            'cajero'        => $usuario['name'],
            'cliente'       => $nombreClienteTicket,
            'fechaHora'     => $fechaHora,
            'metodo'        => ($metodo === 'credito') ? 'A Crédito (Deuda Pendiente)' : ucfirst($metodo),
            'esCredito'     => ($metodo === 'credito'),
            'totalGlobal'   => $totalGlobal,
            'internalItems' => $internalItems,
            'totalInternal' => $totalInternal,
            'externalItems' => $externalItems,
            'totalExternal' => $totalExternal
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $mensaje = "❌ Error: " . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

$productos = obtenerTodas("SELECT id, name, barcode, salePrice, quantity, isExternal FROM products WHERE quantity > 0 ORDER BY name ASC");
$clientes = obtenerTodas("
    SELECT c.id, c.fullName, c.phone,
           (SELECT COALESCE(SUM(remainingAmount), 0) FROM client_debts WHERE clientId = c.id AND status != 'liquidada') as deudaTotal
    FROM clients c 
    ORDER BY c.fullName ASC
");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Punto de Venta - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/css/style.css">
    <style>
        .badge-ext { background-color: #f59e0b; color: #000; font-weight: 600; }
        .ticket-box { background: #fafafa; border: 1px dashed #bbb; padding: 15px; border-radius: 8px; font-family: 'Courier New', Courier, monospace; }
        .sugerencia-item:hover { background-color: #f1f5f9; cursor: pointer; }
        @media print {
            .navbar, .sidebar, .no-print, .btn, .modal-footer { display: none !important; }
            main, .modal-dialog, .modal-content { border: none !important; box-shadow: none !important; width: 100% !important; margin: 0 !important; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/navbar.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include __DIR__ . '/../includes/sidebar.php'; ?>
            <main class="col-md-10 ms-sm-auto px-md-4 main-content">
                <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h1><i class="fas fa-cash-register me-2"></i>Punto de Venta (POS)</h1>
                        <p class="text-muted mb-0 small">Ventas con folio automático, soporte de ventas a crédito/deuda y comprobante dividido</p>
                    </div>
                </div>

                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                        <?php echo $mensaje; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row g-3">
                    <!-- Catálogo de Productos y Búsqueda -->
                    <div class="col-lg-7">
                        <div class="card mb-3 shadow-sm">
                            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-barcode me-2"></i>Escanear o Seleccionar Producto</span>
                                <div class="btn-group btn-group-sm" role="group">
                                    <button type="button" class="btn btn-outline-light active" onclick="filtrarCatalogo('todos', this)">Todos</button>
                                    <button type="button" class="btn btn-outline-light" onclick="filtrarCatalogo('internos', this)">Internos</button>
                                    <button type="button" class="btn btn-outline-light" onclick="filtrarCatalogo('externos', this)">Externos</button>
                                </div>
                            </div>
                            <div class="card-body p-3">
                                <div class="input-group mb-3">
                                    <span class="input-group-text bg-light"><i class="fas fa-search text-muted"></i></span>
                                    <input type="text" id="barcodeInput" class="form-control form-control-lg font-monospace" placeholder="📷 Escanea código de barras o teclea nombre..." autofocus>
                                </div>

                                <div class="row g-2" id="gridProductos" style="max-height: 480px; overflow-y: auto;">
                                    <?php foreach ($productos as $p): 
                                        $esExt = !empty($p['isExternal']);
                                    ?>
                                    <div class="col-sm-6 item-card" data-tipo="<?php echo $esExt ? 'externos' : 'internos'; ?>" data-nombre="<?php echo strtolower($p['name']); ?>" data-barcode="<?php echo strtolower($p['barcode'] ?? ''); ?>">
                                        <div class="card h-100 border p-2 shadow-2xs hover-shadow" style="cursor: pointer; transition: all 0.15s ease-in-out;" onclick="agregarPorId(<?php echo (int)$p['id']; ?>)" title="Clic para agregar al carrito">
                                            <div class="d-flex justify-content-between align-items-start mb-1">
                                                <div class="fw-bold text-truncate" style="max-width: 75%;"><?php echo htmlspecialchars($p['name']); ?></div>
                                                <?php if ($esExt): ?>
                                                    <span class="badge badge-ext small"><i class="fas fa-tag"></i> Ext</span>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-secondary border small">Int</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
                                                <div>
                                                    <span class="text-success fw-bold fs-6 font-monospace">$<?php echo number_format($p['salePrice'], 2); ?></span>
                                                    <small class="text-muted d-block"><?php echo $p['quantity']; ?> en stock</small>
                                                </div>
                                                <button type="button" class="btn btn-sm btn-primary fw-bold" onclick="event.stopPropagation(); agregarPorId(<?php echo (int)$p['id']; ?>)">
                                                    <i class="fas fa-plus me-1"></i> Agregar
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Carrito y Cobro -->
                    <div class="col-lg-5">
                        <div class="card shadow-sm">
                            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-shopping-cart me-2"></i>Comprobante de Venta</span>
                                <div class="d-flex gap-1">
                                    <button type="button" class="btn btn-sm btn-outline-light" onclick="reiniciarVentaCompleta()" title="Reiniciar venta a público general sin cliente"><i class="fas fa-undo me-1"></i>Reiniciar</button>
                                    <button type="button" class="btn btn-sm btn-outline-light" onclick="vaciarCarrito()" title="Vaciar Carrito"><i class="fas fa-trash-alt"></i></button>
                                </div>
                            </div>
                            <div class="card-body p-3">
                                <form method="POST" id="formVenta">
                                    <input type="hidden" name="accion" value="crear_venta">
                                    <input type="hidden" name="items" id="itemsInput">

                                    <!-- Selector de Cliente / Socio con Alerta de Deuda -->
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <label class="form-label small fw-bold text-muted mb-0">Cliente / Socio que compra:</label>
                                            <span id="clienteDeudaAviso" class="badge bg-danger small" style="display:none;"></span>
                                        </div>
                                        <div class="input-group input-group-sm mb-1.5">
                                            <span class="input-group-text bg-light"><i class="fas fa-search text-muted"></i></span>
                                            <input type="text" id="filtroClienteInput" class="form-control" placeholder="Buscar socio por nombre o teléfono..." oninput="filtrarClientesEnSelect(this.value)">
                                        </div>
                                        <select name="clientId" id="clientIdSelect" class="form-select" onchange="actualizarClienteSeleccionado(this.value)">
                                            <option value="">👤 Público General (Venta de mostrador)</option>
                                            <?php foreach ($clientes as $c): 
                                                $deudaVal = (float)($c['deudaTotal'] ?? 0);
                                            ?>
                                            <option value="<?php echo $c['id']; ?>" data-nombre="<?php echo htmlspecialchars(strtolower($c['fullName'])); ?>" data-deuda="<?php echo $deudaVal; ?>">
                                                <?php echo htmlspecialchars($c['fullName']); ?> <?php echo $c['phone'] ? '('.htmlspecialchars($c['phone']).')' : ''; ?> <?php echo $deudaVal > 0 ? '— ⚠️ Adeudo: $'.number_format($deudaVal, 2) : '— ✅ Al corriente'; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="hidden" id="clientIdInput" name="clientIdHidden" value="">
                                        <div id="alertaDeudaBanner" class="alert alert-danger py-1 px-2 small mt-2 mb-0" style="display:none;">
                                            <i class="fas fa-exclamation-triangle me-1"></i> <span id="alertaDeudaTexto"></span>
                                        </div>
                                    </div>

                                    <!-- Lista de Productos en Carrito -->
                                    <div id="carritoItems" class="mb-3 border rounded p-2" style="min-height: 160px; max-height: 280px; overflow-y: auto; background-color: #fafbfc;">
                                        <div class="text-center text-muted py-4" id="carritoVacioMsg">
                                            <i class="fas fa-shopping-basket fa-3x mb-2 text-secondary opacity-50"></i>
                                            <div>El carrito está vacío</div>
                                            <small>Haz clic en los productos para agregarlos</small>
                                        </div>
                                    </div>

                                    <!-- Desglose por Tipo de Producto -->
                                    <div class="p-2 mb-3 bg-light rounded border small">
                                        <div class="d-flex justify-content-between mb-1">
                                            <span class="text-muted"><i class="fas fa-store me-1"></i> Subtotal Interno:</span>
                                            <strong class="font-monospace text-dark" id="subtotalInternoDisplay">$0.00</strong>
                                        </div>
                                        <div class="d-flex justify-content-between mb-1">
                                            <span class="text-warning-emphasis"><i class="fas fa-tag me-1"></i> Subtotal Venta Externa:</span>
                                            <strong class="font-monospace text-warning-emphasis" id="subtotalExternoDisplay">$0.00</strong>
                                        </div>
                                        <hr class="my-1">
                                        <div class="d-flex justify-content-between fs-5 fw-bold text-success">
                                            <span>TOTAL GLOBAL:</span>
                                            <span class="font-monospace" id="totalDisplay">$0.00</span>
                                        </div>
                                    </div>

                                    <!-- Método de Pago -->
                                    <div class="mb-3">
                                        <label class="form-label fw-bold small text-muted">Método de Pago:</label>
                                        <select name="paymentMethod" id="paymentMethodSelect" class="form-select fw-bold" onchange="cambiarMetodoPago(this.value)">
                                            <option value="efectivo">💵 Efectivo</option>
                                            <option value="tarjeta">💳 Tarjeta (Terminal Bancaria)</option>
                                            <option value="transferencia">📲 Transferencia / SPEI</option>
                                            <option value="credito" class="text-danger fw-bold">⚠️ Dejar en Deuda / A Crédito (Socio)</option>
                                        </select>
                                        <div id="avisoCredito" class="alert alert-warning py-2 small mt-2 mb-0" style="display:none;">
                                            <i class="fas fa-info-circle me-1"></i> <strong>Venta a Crédito:</strong> Esta compra quedará registrada como saldo deudor a nombre del socio seleccionado y aparecerá en el módulo de <strong>Abonos y Deudas</strong>.
                                        </div>
                                    </div>

                                    <button type="submit" class="btn btn-success btn-lg w-100 py-3 fw-bold shadow-sm" onclick="return prepararEnvio()">
                                        <i class="fas fa-check-circle me-1"></i> Cobrar y Generar Comprobante
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- MODAL DE COMPROBANTE DIVIDIDO / TICKET GENERADO -->
    <?php if ($ticketData): ?>
    <div class="modal fade" id="modalTicketExitoso" tabindex="-1" aria-labelledby="modalTicketLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header <?php echo $ticketData['esCredito'] ? 'bg-warning text-dark' : 'bg-success text-white'; ?> py-2">
                    <h5 class="modal-title" id="modalTicketLabel">
                        <i class="fas fa-receipt me-2"></i> <?php echo $ticketData['esCredito'] ? 'Venta a Crédito Registrada' : 'Venta Cobrada con Éxito'; ?>
                    </h5>
                    <button type="button" class="btn-close <?php echo $ticketData['esCredito'] ? '' : 'btn-close-white'; ?>" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3">
                    <div class="ticket-box" id="areaTicket">
                        <div class="text-center mb-2 pb-2 border-bottom">
                            <h5 class="fw-bold mb-0"><?php echo APP_NAME; ?></h5>
                            <small class="text-muted d-block">COMPROBANTE DE VENTA DIVIDIDO</small>
                            <span class="badge bg-dark fs-6 mt-1">FOLIO: #<?php echo $ticketData['folio']; ?></span>
                        </div>
                        <div class="small mb-2">
                            <div><strong>Fecha/Hora:</strong> <?php echo $ticketData['fechaHora']; ?></div>
                            <div><strong>Atendió:</strong> <?php echo htmlspecialchars($ticketData['cajero']); ?></div>
                            <div><strong>Socio / Cliente:</strong> <?php echo htmlspecialchars($ticketData['cliente']); ?></div>
                            <div>
                                <strong>Método:</strong> 
                                <?php if ($ticketData['esCredito']): ?>
                                    <span class="badge bg-danger">A CRÉDITO (DEUDA PENDIENTE)</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><?php echo $ticketData['metodo']; ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Desglose Interno -->
                        <?php if (!empty($ticketData['internalItems'])): ?>
                        <div class="border rounded p-2 mb-2 bg-white">
                            <div class="fw-bold text-primary small mb-1 border-bottom pb-1">
                                <i class="fas fa-store me-1"></i> PRODUCTOS INTERNOS (Gimnasio)
                            </div>
                            <table class="w-100 small">
                                <?php foreach ($ticketData['internalItems'] as $it): ?>
                                <tr>
                                    <td><?php echo $it['quantity']; ?>x <?php echo htmlspecialchars($it['productName']); ?></td>
                                    <td class="text-end font-monospace">$<?php echo number_format($it['subtotal'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="fw-bold border-top">
                                    <td>Subtotal Interno:</td>
                                    <td class="text-end font-monospace">$<?php echo number_format($ticketData['totalInternal'], 2); ?></td>
                                </tr>
                            </table>
                        </div>
                        <?php endif; ?>

                        <!-- Desglose Externo -->
                        <?php if (!empty($ticketData['externalItems'])): ?>
                        <div class="border border-warning rounded p-2 mb-2 bg-white">
                            <div class="fw-bold text-warning-emphasis small mb-1 border-bottom pb-1">
                                <i class="fas fa-tag me-1"></i> VENTAS EXTERNAS REALIZADAS (Concesión / Suplementos)
                            </div>
                            <table class="w-100 small">
                                <?php foreach ($ticketData['externalItems'] as $it): ?>
                                <tr>
                                    <td><?php echo $it['quantity']; ?>x <?php echo htmlspecialchars($it['productName']); ?></td>
                                    <td class="text-end font-monospace">$<?php echo number_format($it['subtotal'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="fw-bold border-top">
                                    <td>Subtotal Externo:</td>
                                    <td class="text-end font-monospace">$<?php echo number_format($ticketData['totalExternal'], 2); ?></td>
                                </tr>
                            </table>
                        </div>
                        <?php endif; ?>

                        <div class="text-end fs-5 fw-bold <?php echo $ticketData['esCredito'] ? 'text-danger' : 'text-success'; ?> pt-2 border-top">
                            TOTAL GLOBAL: $<?php echo number_format($ticketData['totalGlobal'], 2); ?>
                        </div>
                        <?php if ($ticketData['esCredito']): ?>
                            <div class="alert alert-danger py-1 small text-center mt-2 mb-0">
                                <strong>ADEUDO REGISTRADO:</strong> El socio tiene un nuevo saldo pendiente por liquidar.
                            </div>
                        <?php endif; ?>
                        <div class="text-center text-muted small mt-2">
                            ¡Gracias por su preferencia!
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" class="btn btn-primary" onclick="window.print()">
                        <i class="fas fa-print me-1"></i> Imprimir Comprobante
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const prods = <?php echo json_encode($productos); ?>;
    const clientesLista = <?php echo json_encode($clientes); ?>;
    let carrito = [];

    function actualizarClienteSeleccionado(cliId) {
        const cliInput = document.getElementById('clientIdInput');
        if (cliInput) cliInput.value = cliId;

        const sel = document.getElementById('clientIdSelect');
        const opt = sel ? sel.options[sel.selectedIndex] : null;
        const deuda = opt ? parseFloat(opt.getAttribute('data-deuda') || '0') : 0;
        const aviso = document.getElementById('clienteDeudaAviso');
        const banner = document.getElementById('alertaDeudaBanner');
        const texto = document.getElementById('alertaDeudaTexto');

        if (deuda > 0) {
            if (aviso) {
                aviso.textContent = 'Deuda: $' + deuda.toFixed(2);
                aviso.style.display = 'inline-block';
            }
            if (banner && texto) {
                texto.textContent = 'Atención: Este socio cuenta con una deuda pendiente atrasada de $' + deuda.toFixed(2) + '.';
                banner.style.display = 'block';
            }
        } else {
            if (aviso) aviso.style.display = 'none';
            if (banner) banner.style.display = 'none';
        }
    }

    function cambiarMetodoPago(metodo) {
        const aviso = document.getElementById('avisoCredito');
        if (aviso) {
            aviso.style.display = (metodo === 'credito') ? 'block' : 'none';
        }
        const sel = document.getElementById('clientIdSelect');
        if (metodo === 'credito' && (!sel || !sel.value)) {
            alert('⚠️ Para registrar una venta a crédito (deuda pendiente), debes seleccionar a un socio en el menú de clientes.');
            if (sel) sel.focus();
        }
    }

    // Búsqueda por código de barras o texto
    document.getElementById('barcodeInput').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const val = this.value.trim().toLowerCase();
            const match = prods.find(p => (p.barcode && p.barcode.toLowerCase() === val) || p.name.toLowerCase().includes(val));
            if (match) {
                agregarAlCarrito(match);
            } else {
                alert('Producto no encontrado');
            }
            this.value = '';
        }
    });

    function filtrarCatalogo(tipo, btn) {
        document.querySelectorAll('.card-header .btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.querySelectorAll('.item-card').forEach(el => {
            if (tipo === 'todos' || el.getAttribute('data-tipo') === tipo) {
                el.style.display = 'block';
            } else {
                el.style.display = 'none';
            }
        });
    }

    function filtrarClientesEnSelect(query) {
        const q = (query || '').toLowerCase().trim();
        const sel = document.getElementById('clientIdSelect');
        if (!sel) return;
        for (let i = 0; i < sel.options.length; i++) {
            const opt = sel.options[i];
            if (i === 0) {
                opt.style.display = '';
                continue;
            }
            const nom = (opt.getAttribute('data-nombre') || opt.text || '').toLowerCase();
            if (!q || nom.includes(q)) {
                opt.style.display = '';
            } else {
                opt.style.display = 'none';
            }
        }
    }

    function agregarPorId(id) {
        const prod = prods.find(p => p.id == id);
        if (prod) {
            agregarAlCarrito(prod);
        } else {
            alert('Producto no encontrado');
        }
    }

    function agregarAlCarrito(p) {
        const item = carrito.find(i => i.productId == p.id);
        if (item) {
            if (item.quantity + 1 > p.quantity) {
                alert('Stock insuficiente para "' + p.name + '" (Stock disponible: ' + p.quantity + ')');
                return;
            }
            item.quantity++;
            item.subtotal = item.quantity * item.unitPrice;
        } else {
            carrito.push({
                productId: p.id,
                productName: p.name,
                isExternal: parseInt(p.isExternal) || 0,
                quantity: 1,
                unitPrice: parseFloat(p.salePrice),
                subtotal: parseFloat(p.salePrice)
            });
        }
        render();
    }

    function cambiarCantidad(idx, delta) {
        const it = carrito[idx];
        const prodOriginal = prods.find(p => p.id == it.productId);
        const nuevaCant = it.quantity + delta;
        if (nuevaCant <= 0) {
            quitar(idx);
            return;
        }
        if (prodOriginal && nuevaCant > prodOriginal.quantity) {
            alert('Stock máximo alcanzado (' + prodOriginal.quantity + ')');
            return;
        }
        it.quantity = nuevaCant;
        it.subtotal = it.quantity * it.unitPrice;
        render();
    }

    function render() {
        const cont = document.getElementById('carritoItems');
        if (carrito.length === 0) {
            let sugerenciasHtml = '<div class="d-flex flex-wrap justify-content-center gap-1 mt-2">';
            prods.slice(0, 4).forEach(p => {
                sugerenciasHtml += '<button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 small" onclick="agregarPorId(' + p.id + ')">+ ' + p.name.split(' ')[0] + ' ($' + parseFloat(p.salePrice).toFixed(2) + ')</button>';
            });
            sugerenciasHtml += '</div>';

            cont.innerHTML = '<div class="text-center text-muted py-3" id="carritoVacioMsg">' +
                '<i class="fas fa-shopping-basket fa-2x mb-2 text-secondary opacity-50"></i>' +
                '<div class="fw-bold">El carrito está vacío</div><small class="d-block mb-1">Haz clic en <strong>+ Agregar</strong> en el catálogo o usa un acceso rápido:</small>' +
                sugerenciasHtml + '</div>';
            document.getElementById('subtotalInternoDisplay').textContent = '$0.00';
            document.getElementById('subtotalExternoDisplay').textContent = '$0.00';
            document.getElementById('totalDisplay').textContent = '$0.00';
            return;
        }

        cont.innerHTML = '';
        let totInterno = 0;
        let totExterno = 0;

        carrito.forEach((it, idx) => {
            if (it.isExternal == 1) {
                totExterno += it.subtotal;
            } else {
                totInterno += it.subtotal;
            }

            const badgeHtml = it.isExternal == 1
                ? '<span class="badge badge-ext small me-1">Ext</span>'
                : '<span class="badge bg-light text-secondary border small me-1">Int</span>';

            cont.innerHTML += 
                '<div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom bg-white p-2 rounded shadow-2xs">' +
                    '<div style="max-width: 60%;">' +
                        '<div>' + badgeHtml + '<strong class="text-truncate">' + it.productName + '</strong></div>' +
                        '<small class="text-muted">$' + it.unitPrice.toFixed(2) + ' c/u</small>' +
                    '</div>' +
                    '<div class="d-flex align-items-center gap-1">' +
                        '<button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="cambiarCantidad(' + idx + ', -1)">-</button>' +
                        '<span class="fw-bold px-1">' + it.quantity + '</span>' +
                        '<button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="cambiarCantidad(' + idx + ', 1)">+</button>' +
                        '<span class="fw-bold font-monospace text-success ms-2" style="min-width:65px; text-align:right;">$' + it.subtotal.toFixed(2) + '</span>' +
                        '<button type="button" class="btn btn-sm text-danger ms-1" onclick="quitar(' + idx + ')" title="Quitar"><i class="fas fa-times"></i></button>' +
                    '</div>' +
                '</div>';
        });

        const totalGlobal = totInterno + totExterno;
        document.getElementById('subtotalInternoDisplay').textContent = '$' + totInterno.toFixed(2);
        document.getElementById('subtotalExternoDisplay').textContent = '$' + totExterno.toFixed(2);
        document.getElementById('totalDisplay').textContent = '$' + totalGlobal.toFixed(2);
    }

    function quitar(idx) {
        carrito.splice(idx, 1);
        render();
    }

    function vaciarCarrito() {
        if (carrito.length > 0 && confirm('¿Deseas vaciar el carrito?')) {
            carrito = [];
            render();
        }
    }

    function reiniciarVentaCompleta() {
        carrito = [];
        const sel = document.getElementById('clientIdSelect');
        if (sel) sel.value = '';
        actualizarClienteSeleccionado('');
        const filtro = document.getElementById('filtroClienteInput');
        if (filtro) { 
            filtro.value = ''; 
            filtrarClientesEnSelect(''); 
        }
        const metodoEl = document.getElementById('paymentMethodSelect');
        if (metodoEl) metodoEl.value = 'efectivo';
        cambiarMetodoPago('efectivo');
        render();
    }

    function prepararEnvio() {
        if (!carrito || carrito.length === 0) {
            alert('⚠️ El carrito está vacío. Haz clic en los productos del catálogo a la izquierda para agregarlos antes de cobrar.');
            return false;
        }

        const metodoEl = document.getElementById('paymentMethodSelect');
        const metodo = metodoEl ? metodoEl.value : 'efectivo';
        const cliSelect = document.getElementById('clientIdSelect');
        const cliId = cliSelect ? cliSelect.value : '';

        if (metodo === 'credito' && !cliId) {
            alert('⚠️ Para registrar una venta a crédito o dejar en deuda pendiente, debes seleccionar a un socio en el menú de clientes.');
            if (cliSelect) cliSelect.focus();
            return false;
        }

        const itemsInput = document.getElementById('itemsInput');
        if (itemsInput) {
            itemsInput.value = JSON.stringify(carrito);
        }
        return true;
    }

    <?php if ($ticketData): ?>
    document.addEventListener('DOMContentLoaded', function() {
        var modalEl = document.getElementById('modalTicketExitoso');
        if (modalEl) {
            var modal = new bootstrap.Modal(modalEl);
            modal.show();
            modalEl.addEventListener('hidden.bs.modal', function() {
                reiniciarVentaCompleta();
            });
        }
    });
    <?php endif; ?>
    </script>
</body>
</html>
