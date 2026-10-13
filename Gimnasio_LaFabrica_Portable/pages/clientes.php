<?php
require_once __DIR__ . '/../includes/config.php';
verificarAutenticacion();
$usuario = obtenerUsuarioActual();
$mensaje = ''; $tipo_mensaje = '';

// ENDPOINT AJAX: Verificar credenciales de admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'verificar_admin') {
    header('Content-Type: application/json');
    $u = escapar($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    $check = obtenerFila("SELECT role, password FROM users WHERE (name = :u OR email = :u) LIMIT 1", [':u' => $u]);
    if ($check && ($check['role'] === 'admin' || $check['role'] === 'administrador') && $check['password'] === $p) {
        echo json_encode(['status' => 'ok']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Contraseña de administrador incorrecta']);
    }
    exit;
}

// ENDPOINT AJAX: Obtener Ficha Completa del Cliente (Datos, Membresías compradas, Deuda y Abonos)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'obtener_info_cliente') {
    header('Content-Type: application/json');
    $cId = (int)($_POST['cliente_id'] ?? 0);
    $cliente = obtenerFila("SELECT * FROM clients WHERE id = :id", [':id' => $cId]);
    if (!$cliente) {
        echo json_encode(['status' => 'error', 'message' => 'Cliente no encontrado']);
        exit;
    }

    // Membresía actual vigente o última registrada
    $cmActual = obtenerFila("
        SELECT cm.*, m.name as membership_name, m.cost, m.durationDays,
               CAST(julianday(cm.endDate) - julianday(date('now', 'localtime')) AS INTEGER) as dias_restantes
        FROM clientmemberships cm
        JOIN memberships m ON cm.membershipId = m.id
        WHERE cm.clientId = :id
        ORDER BY cm.id DESC LIMIT 1
    ", [':id' => $cId]);

    // Historial completo de membresías compradas
    $historialMembresias = obtenerTodas("
        SELECT cm.*, m.name as membership_name, m.cost, m.durationDays,
               CAST(julianday(cm.endDate) - julianday(date('now', 'localtime')) AS INTEGER) as dias_restantes
        FROM clientmemberships cm
        JOIN memberships m ON cm.membershipId = m.id
        WHERE cm.clientId = :id
        ORDER BY cm.id DESC
    ", [':id' => $cId]);

    // Historial de deudas del cliente
    $deudas = obtenerTodas("
        SELECT cd.*, gs.folio
        FROM client_debts cd
        LEFT JOIN global_sales gs ON cd.globalSaleId = gs.id
        WHERE cd.clientId = :id
        ORDER BY cd.id DESC
    ", [':id' => $cId]);

    // Historial de abonos realizados
    $abonos = obtenerTodas("
        SELECT dp.*, u.name as cajero, cd.description as deuda_desc
        FROM debt_payments dp
        LEFT JOIN users u ON dp.userId = u.id
        LEFT JOIN client_debts cd ON dp.debtId = cd.id
        WHERE dp.clientId = :id
        ORDER BY dp.id DESC
    ", [':id' => $cId]);

    $totalDeuda = 0.00;
    foreach ($deudas as $d) {
        if ($d['status'] !== 'liquidada') {
            $totalDeuda += (float)$d['remainingAmount'];
        }
    }

    echo json_encode([
        'status' => 'ok',
        'cliente' => $cliente,
        'membresiaActual' => $cmActual,
        'historialMembresias' => $historialMembresias,
        'deudas' => $deudas,
        'abonos' => $abonos,
        'totalDeudaPendiente' => $totalDeuda
    ]);
    exit;
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($_POST['accion'] ?? '', ['verificar_admin', 'obtener_info_cliente'])) {
    $accion = $_POST['accion'] ?? '';
    try {
        if ($accion === 'crear') {
            $clientId = insertar('clients', [
                'fullName'    => escapar($_POST['fullName']),
                'gender'      => escapar($_POST['gender']),
                'phone'       => escapar($_POST['phone'] ?? ''),
                'address'     => escapar($_POST['address'] ?? ''),
                'dateOfBirth' => escapar($_POST['dateOfBirth'] ?? ''),
                'fingerprint' => escapar($_POST['fingerprint'] ?? '')
            ]);
            
            if (!empty($_POST['membershipId'])) {
                $memId = (int)$_POST['membershipId'];
                $mem = obtenerFila("SELECT cost, durationDays FROM memberships WHERE id = :id", [':id' => $memId]);
                if ($mem) {
                    $end = date('Y-m-d', strtotime("+{$mem['durationDays']} days"));
                    insertar('clientmemberships', ['clientId' => $clientId, 'membershipId' => $memId, 'startDate' => date('Y-m-d'), 'endDate' => $end, 'isActive' => 1]);
                    insertar('membership_sales', ['clientId' => $clientId, 'membershipId' => $memId, 'cost' => $mem['cost']]);
                }
            }
            $mensaje = '✅ Cliente registrado exitosamente'; $tipo_mensaje = 'success';
        } elseif ($accion === 'editar') {
            $id = (int)$_POST['id'];
            actualizar('clients', [
                'fullName'    => escapar($_POST['fullName']),
                'gender'      => escapar($_POST['gender']),
                'phone'       => escapar($_POST['phone'] ?? ''),
                'address'     => escapar($_POST['address'] ?? ''),
                'dateOfBirth' => escapar($_POST['dateOfBirth'] ?? ''),
                'fingerprint' => escapar($_POST['fingerprint'] ?? '')
            ], "id = :id", [':id' => $id]);

            if (!empty($_POST['membershipId'])) {
                if ($id > 0) {
                    $deudaPendienteFila = obtenerFila("SELECT SUM(remainingAmount) as deudaTotal FROM client_debts WHERE clientId = :id AND status != 'liquidada'", [':id' => $id]);
                    $deudaPendiente = (float)($deudaPendienteFila['deudaTotal'] ?? 0);
                    if ($deudaPendiente > 0) {
                        throw new Exception("⛔ Venta bloqueada: El cliente tiene un saldo deudor pendiente de $" . number_format($deudaPendiente, 2) . ". Debe saldar su deuda antes de adquirir o renovar membresías.");
                    }
                }

                $memId = (int)$_POST['membershipId'];
                $mem = obtenerFila("SELECT cost, durationDays FROM memberships WHERE id = :id", [':id' => $memId]);
                if ($mem) {
                    // Respetar días restantes si la membresía actual no ha vencido
                    $current = obtenerFila("SELECT MAX(endDate) as maxEnd FROM clientmemberships WHERE clientId = :id", [':id' => $id]);
                    $base = date('Y-m-d');
                    if ($current && !empty($current['maxEnd']) && $current['maxEnd'] > $base) {
                        $base = $current['maxEnd'];
                    }
                    $end = date('Y-m-d', strtotime("$base +{$mem['durationDays']} days"));
                    insertar('clientmemberships', ['clientId' => $id, 'membershipId' => $memId, 'startDate' => date('Y-m-d'), 'endDate' => $end, 'isActive' => 1]);
                    insertar('membership_sales', ['clientId' => $id, 'membershipId' => $memId, 'cost' => $mem['cost']]);
                }
            } elseif (!empty($_POST['edit_endDate'])) {
                $customEnd = escapar($_POST['edit_endDate']);
                $customActive = isset($_POST['edit_isActive']) ? 1 : 0;
                $lastCm = obtenerFila("SELECT id FROM clientmemberships WHERE clientId = :id ORDER BY id DESC LIMIT 1", [':id' => $id]);
                if ($lastCm) {
                    actualizar('clientmemberships', ['endDate' => $customEnd, 'isActive' => $customActive], 'id = :id', [':id' => $lastCm['id']]);
                }
            }
            $mensaje = '✅ Cliente actualizado correctamente'; $tipo_mensaje = 'success';
        } elseif ($accion === 'vender_membresia') {
            $clientId = (int)$_POST['cliente_id'];
            $memId = (int)$_POST['membershipId'];
            $metodo = in_array($_POST['paymentMethod'] ?? '', ['efectivo', 'tarjeta', 'transferencia', 'credito']) ? $_POST['paymentMethod'] : 'efectivo';

            // RESTRICCIÓN: Bloquear si el socio tiene deuda pendiente
            $deudaPendienteFila = obtenerFila("SELECT SUM(remainingAmount) as deudaTotal FROM client_debts WHERE clientId = :id AND status != 'liquidada'", [':id' => $clientId]);
            $deudaPendiente = (float)($deudaPendienteFila['deudaTotal'] ?? 0);
            if ($deudaPendiente > 0) {
                throw new Exception("⛔ Venta bloqueada: El cliente tiene un saldo pendiente de $" . number_format($deudaPendiente, 2) . ". Debe saldar su deuda en el módulo de Abonos antes de adquirir otra membresía.");
            }

            $cliente = obtenerFila("SELECT * FROM clients WHERE id = :id", [':id' => $clientId]);
            $mem = obtenerFila("SELECT * FROM memberships WHERE id = :id", [':id' => $memId]);
            if ($cliente && $mem) {
                // Sumar a la vigencia actual si la actual sigue activa y no ha vencido, o activar desde hoy
                $current = obtenerFila("SELECT MAX(endDate) as maxEnd FROM clientmemberships WHERE clientId = :id AND isActive = 1", [':id' => $clientId]);
                $hoy = date('Y-m-d');
                $base = $hoy;
                if ($current && !empty($current['maxEnd']) && $current['maxEnd'] > $hoy) {
                    $base = $current['maxEnd'];
                }
                $nuevoFin = date('Y-m-d', strtotime("$base +{$mem['durationDays']} days"));

                insertar('clientmemberships', [
                    'clientId'     => $clientId,
                    'membershipId' => $memId,
                    'startDate'    => $hoy,
                    'endDate'      => $nuevoFin,
                    'isActive'     => 1
                ]);

                insertar('membership_sales', [
                    'clientId'     => $clientId,
                    'membershipId' => $memId,
                    'cost'         => $mem['cost'],
                    'saleDate'     => date('Y-m-d H:i:s')
                ]);

                // Si fue a crédito, registrar en client_debts
                if ($metodo === 'credito') {
                    insertar('client_debts', [
                        'clientId'        => $clientId,
                        'globalSaleId'    => null,
                        'description'     => "Membresía {$mem['name']} ({$mem['durationDays']} días) a crédito",
                        'totalAmount'     => $mem['cost'],
                        'paidAmount'      => 0.00,
                        'remainingAmount' => $mem['cost'],
                        'status'          => 'pendiente',
                        'userId'          => $usuario['id'],
                        'createdAt'       => date('Y-m-d H:i:s'),
                        'updatedAt'       => date('Y-m-d H:i:s')
                    ]);
                }

                $mensaje = "✅ Membresía '{$mem['name']}' vendida exitosamente para " . htmlspecialchars($cliente['fullName']) . ". Vigencia activa hasta el {$nuevoFin}" . ($metodo === 'credito' ? ' (Registrada como Deuda)' : '');
                $tipo_mensaje = 'success';
            }
        } elseif ($accion === 'registrar_visita') {
            $cId = (int)$_POST['cliente_id'];
            $cliente = obtenerFila("SELECT fullName FROM clients WHERE id = :id", [':id' => $cId]);
            if ($cliente) {
                insertar('visits', [
                    'clientId'    => $cId,
                    'visitDate'   => date('Y-m-d'),
                    'checkInTime' => date('Y-m-d H:i:s')
                ]);
                $mensaje = '✅ Asistencia registrada para ' . htmlspecialchars($cliente['fullName']) . ' (' . date('H:i') . ')';
                $tipo_mensaje = 'success';
            }
        } elseif ($accion === 'eliminar') {
            eliminar('clients', "id = :id", [':id' => (int)$_POST['id']]);
            $mensaje = 'Cliente eliminado correctamente'; $tipo_mensaje = 'success';
        }
    } catch (Exception $e) {
        $mensaje = 'Error: ' . $e->getMessage(); $tipo_mensaje = 'danger';
    }
}

// Búsqueda y listado en SQLite
$busqueda = trim($_GET['q'] ?? '');
$filtro = $_GET['filtro'] ?? 'todos';
$whereParts = []; $params = [];

if ($busqueda !== '') {
    $whereParts[] = "(c.fullName LIKE :q OR c.phone LIKE :q)";
    $params[':q'] = "%$busqueda%";
}
if ($filtro === 'proximas') {
    $whereParts[] = "CAST(julianday(cm.endDate) - julianday(date('now', 'localtime')) AS INTEGER) BETWEEN 1 AND 5";
} elseif ($filtro === 'caducadas') {
    $whereParts[] = "(cm.endDate IS NULL OR cm.endDate < date('now', 'localtime') OR cm.isActive = 0)";
} elseif ($filtro === 'deudores') {
    $whereParts[] = "c.id IN (SELECT DISTINCT clientId FROM client_debts WHERE status != 'liquidada' AND remainingAmount > 0)";
}
$whereSQL = !empty($whereParts) ? "WHERE " . implode(" AND ", $whereParts) : "";

$clientes = obtenerTodas("
    SELECT c.*, cm.startDate as memberStartDate, cm.endDate, cm.isActive as memIsActive, m.name as membership_name,
           CAST(julianday(cm.endDate) - julianday(date('now', 'localtime')) AS INTEGER) as dias_restantes,
           COALESCE((SELECT SUM(remainingAmount) FROM client_debts WHERE clientId = c.id AND status != 'liquidada'), 0.0) as deuda_pendiente,
           COALESCE((SELECT COUNT(*) FROM client_debts WHERE clientId = c.id AND status != 'liquidada' AND remainingAmount > 0), 0) as deudas_activas_count
    FROM clients c
    LEFT JOIN (SELECT clientId, membershipId, startDate, endDate, isActive FROM clientmemberships WHERE id IN (SELECT MAX(id) FROM clientmemberships GROUP BY clientId)) cm ON c.id = cm.clientId
    LEFT JOIN memberships m ON cm.membershipId = m.id
    $whereSQL
    ORDER BY c.fullName ASC
", $params);

// Conteos para los botones de filtro
$conteoTodos = (int)obtenerFila("SELECT COUNT(*) as t FROM clients")['t'];
$conteoProximas = (int)obtenerFila("
    SELECT COUNT(*) as t FROM (
        SELECT clientId, MAX(endDate) as maxEnd FROM clientmemberships 
        WHERE isActive = 1 
        GROUP BY clientId 
        HAVING CAST(julianday(maxEnd) - julianday(date('now', 'localtime')) AS INTEGER) BETWEEN 1 AND 5
    )
")['t'];
$conteoCaducadas = (int)obtenerFila("
    SELECT COUNT(*) as t FROM clients c 
    LEFT JOIN (SELECT clientId, MAX(endDate) as maxEnd FROM clientmemberships WHERE isActive = 1 GROUP BY clientId) cm ON c.id = cm.clientId 
    WHERE cm.maxEnd IS NULL OR cm.maxEnd < date('now', 'localtime')
")['t'];
$conteoDeudores = (int)obtenerFila("
    SELECT COUNT(DISTINCT clientId) as t FROM client_debts 
    WHERE status != 'liquidada' AND remainingAmount > 0
")['t'];

$membresias = obtenerTodas("SELECT id, name, cost, durationDays FROM memberships WHERE isActive = 1 ORDER BY cost ASC");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clientes - <?php echo APP_NAME; ?></title>
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
                        <h1><i class="fas fa-users me-2"></i>Gestión de Clientes</h1>
                        <p class="text-muted mb-0 small">Administra los socios, consulta su ficha completa, membresías compradas y control de adeudos</p>
                    </div>
                    <div class="d-flex gap-2">
                        <!-- Botón de Ayuda (?) Solicitado -->
                        <button type="button" class="btn btn-outline-info rounded-pill px-3" onclick="abrirModalAyuda()" title="¿Qué hace cada botón en esta página?">
                            <i class="fas fa-question-circle fa-lg me-1"></i> Ayuda
                        </button>
                        <button type="button" class="btn btn-primary" onclick="nuevoCliente()">
                            <i class="fas fa-plus me-1"></i> Nuevo Cliente
                        </button>
                    </div>
                </div>

                <?php if ($mensaje): ?>
                    <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                        <?php echo $mensaje; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Botones de Filtro y Buscador -->
                <div class="row mb-3 g-2">
                    <div class="col-md-7">
                        <div class="btn-group w-100 flex-wrap" role="group" aria-label="Filtros de membresía">
                            <a href="?filtro=todos<?php echo $busqueda ? '&q='.urlencode($busqueda) : ''; ?>" class="btn <?php echo $filtro==='todos'?'btn-primary':'btn-outline-primary'; ?> btn-sm">
                                <i class="fas fa-users me-1"></i> Todos <span class="badge bg-light text-dark ms-1"><?php echo $conteoTodos; ?></span>
                            </a>
                            <a href="?filtro=proximas<?php echo $busqueda ? '&q='.urlencode($busqueda) : ''; ?>" class="btn <?php echo $filtro==='proximas'?'btn-warning text-dark fw-bold':'btn-outline-warning text-dark'; ?> btn-sm">
                                <i class="fas fa-hourglass-half me-1"></i> ⚠️ Próximas <span class="badge bg-warning text-dark border ms-1"><?php echo $conteoProximas; ?></span>
                            </a>
                            <a href="?filtro=caducadas<?php echo $busqueda ? '&q='.urlencode($busqueda) : ''; ?>" class="btn <?php echo $filtro==='caducadas'?'btn-danger':'btn-outline-danger'; ?> btn-sm">
                                <i class="fas fa-times-circle me-1"></i> 🔴 Caducadas <span class="badge bg-danger text-white ms-1"><?php echo $conteoCaducadas; ?></span>
                            </a>
                            <a href="?filtro=deudores<?php echo $busqueda ? '&q='.urlencode($busqueda) : ''; ?>" class="btn <?php echo $filtro==='deudores'?'btn-danger text-white fw-bold shadow-sm':'btn-outline-danger'; ?> btn-sm">
                                <i class="fas fa-hand-holding-dollar me-1"></i> ⚠️ Con Adeudo <span class="badge bg-danger text-white ms-1"><?php echo $conteoDeudores; ?></span>
                            </a>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <form method="GET" class="d-flex gap-2">
                            <input type="hidden" name="filtro" value="<?php echo htmlspecialchars($filtro); ?>">
                            <input type="text" name="q" class="form-control form-control-sm" placeholder="Buscar por nombre o teléfono..." value="<?php echo htmlspecialchars($busqueda); ?>">
                            <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search me-1"></i> Buscar</button>
                            <?php if ($busqueda): ?>
                                <a href="?filtro=<?php echo $filtro; ?>" class="btn btn-outline-secondary btn-sm">Limpiar</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <!-- Tabla de Socios con Botones de Acción -->
                <div class="card shadow-sm">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Nombre Completo</th>
                                        <th>Contacto</th>
                                        <th>Membresía</th>
                                        <th>Vencimiento</th>
                                        <th class="text-center">Estado / Días</th>
                                        <th class="text-end">Acciones Disponibles</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($clientes)): ?>
                                        <tr><td colspan="6" class="text-center py-4 text-muted">No se encontraron clientes con este filtro o búsqueda.</td></tr>
                                    <?php endif; ?>
                                    <?php foreach ($clientes as $c): 
                                        $dias = $c['dias_restantes'] !== null ? (int)$c['dias_restantes'] : null;
                                        $esVencida = ($dias === null || $dias < 0 || (isset($c['memIsActive']) && $c['memIsActive'] == 0));
                                        $esProxima = (!$esVencida && $dias <= 5);
                                        $tieneDeuda = ((float)($c['deuda_pendiente'] ?? 0)) > 0;
                                        $claseFila = $tieneDeuda ? 'table-danger-subtle' : ($esVencida ? 'table-danger' : ($esProxima ? 'table-warning' : ''));
                                    ?>
                                    <tr class="<?php echo $claseFila; ?>">
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($c['fullName']); ?></div>
                                            <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                                                <small class="text-muted text-capitalize"><i class="fas fa-venus-mars small me-1"></i><?php echo $c['gender']; ?></small>
                                                <?php if ($tieneDeuda): ?>
                                                    <a href="abonos.php?cliente_id=<?php echo $c['id']; ?>" class="badge bg-danger text-white text-decoration-none" title="Ver adeudo pendiente en el módulo de Abonos">
                                                        <i class="fas fa-hand-holding-dollar me-1"></i> Deuda: $<?php echo number_format($c['deuda_pendiente'], 2); ?>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($c['phone']): ?>
                                                <div><i class="fas fa-phone small text-muted me-1"></i><?php echo htmlspecialchars($c['phone']); ?></div>
                                            <?php else: ?>
                                                <span class="text-muted small">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($c['membership_name']): ?>
                                                <span class="badge bg-primary fs-6"><?php echo htmlspecialchars($c['membership_name']); ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Sin Plan</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="font-monospace">
                                            <?php echo $c['endDate'] ?: '<span class="text-muted">-</span>'; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($esVencida): ?>
                                                <span class="badge bg-danger">
                                                    <i class="fas fa-times-circle me-1"></i> Vencida <?php echo $dias !== null ? '(' . abs($dias) . 'd)' : ''; ?>
                                                </span>
                                            <?php elseif ($esProxima): ?>
                                                <span class="badge bg-warning text-dark fw-bold">
                                                    <i class="fas fa-exclamation-triangle me-1"></i> <?php echo $dias; ?> días
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-success">
                                                    <i class="fas fa-check-circle me-1"></i> Vigente (<?php echo $dias; ?> d)
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <!-- Botón 1: Ficha / Información Completa del Cliente -->
                                            <button type="button" class="btn btn-sm btn-outline-primary me-1" onclick="verInfoCliente(<?php echo $c['id']; ?>)" title="Ver toda la información del cliente, historial de membresías y deuda">
                                                <i class="fas fa-id-card me-1"></i> Info
                                            </button>

                                            <!-- Botón 2: Registrar Asistencia Rápida -->
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="accion" value="registrar_visita">
                                                <input type="hidden" name="cliente_id" value="<?php echo $c['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success me-1" title="Registrar Entrada / Check-in del socio hoy">
                                                    <i class="fas fa-door-open me-1"></i> Visita
                                                </button>
                                            </form>

                                            <!-- Botón 3: Modificar Datos y Vigencia de Membresía -->
                                            <button type="button" class="btn btn-sm btn-info text-white me-1" onclick='editarCliente(<?php echo json_encode($c); ?>)' title="Modificar datos del socio, fecha de vencimiento o renovar">
                                                <i class="fas fa-edit me-1"></i> Modificar
                                            </button>

                                            <!-- Botón 4: Eliminar Socio -->
                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="solicitarEliminacion(<?php echo $c['id']; ?>)" title="Eliminar socio (requiere clave admin)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal Crear / Editar Cliente y Membresía -->
    <div class="modal fade" id="modalCliente" tabindex="-1" aria-labelledby="modalClienteLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalClienteLabel"><i class="fas fa-user-edit me-1"></i> Socio</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <!-- AVISO DE DEUDA ATRASADA AL RENOVAR / EDITAR -->
                        <div id="alertaDeudaRenovacion" class="alert alert-danger py-2 px-3 small mb-3 border-danger shadow-sm" style="display:none;">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <i class="fas fa-exclamation-triangle fs-5 text-danger me-2"></i>
                                    <strong class="text-danger">¡CLIENTE CON DEUDA ATRASADA PENDIENTE!</strong>
                                    <div class="mt-1">Saldo pendiente por liquidar: <strong id="montoDeudaAvisoRenovacion" class="font-monospace text-dark fs-6">$0.00</strong></div>
                                </div>
                                <a id="btnIrAbonarDesdeModal" href="/pages/abonos.php" target="_blank" class="btn btn-danger btn-sm text-nowrap">
                                    <i class="fas fa-hand-holding-dollar me-1"></i> Ir a Abonos
                                </a>
                            </div>
                        </div>

                        <input type="hidden" name="accion" id="accion" value="crear">
                        <input type="hidden" name="id" id="cliente_id">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Nombre Completo <span class="text-danger">*</span></label>
                            <input type="text" name="fullName" id="fullName" class="form-control" placeholder="Ej: Juan Pérez Martínez" required autofocus>
                        </div>
                        
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-bold">Género</label>
                                <select name="gender" id="gender" class="form-select">
                                    <option value="masculino">Masculino</option>
                                    <option value="femenino">Femenino</option>
                                    <option value="otro">Otro</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-bold">Teléfono</label>
                                <input type="tel" name="phone" id="phone" class="form-control" placeholder="10 dígitos">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Dirección</label>
                            <input type="text" name="address" id="address" class="form-control" placeholder="Calle, Número, Colonia">
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-bold">Fecha de Nacimiento</label>
                                <input type="date" name="dateOfBirth" id="dateOfBirth" class="form-control">
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-bold">Código de Huella / ID</label>
                                <input type="text" name="fingerprint" id="fingerprint" class="form-control" placeholder="Opcional">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Asignar / Renovar Plan de Membresía</label>
                            <select name="membershipId" id="membershipId" class="form-select">
                                <option value="">-- Sin cambio / Sin renovación --</option>
                                <?php foreach ($membresias as $m): ?>
                                <option value="<?php echo $m['id']; ?>"><?php echo htmlspecialchars($m['name']); ?> ($<?php echo number_format($m['cost'], 2); ?> - <?php echo $m['durationDays']; ?> días)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Sección de Modificación Directa de Vigencia para Socio Existente -->
                        <div class="mb-3 p-3 bg-light rounded border" id="secModificarVigencia" style="display:none;">
                            <label class="form-label fw-bold text-primary mb-1">
                                <i class="fas fa-calendar-alt me-1"></i> Modificar Vigencia de Membresía Actual
                            </label>
                            <div class="row g-2 mb-2">
                                <div class="col-8">
                                    <label class="form-label small text-muted mb-0">Fecha de Vencimiento:</label>
                                    <input type="date" name="edit_endDate" id="edit_endDate" class="form-control form-control-sm">
                                </div>
                                <div class="col-4 d-flex align-items-end">
                                    <div class="form-check form-switch mb-1">
                                        <input type="checkbox" name="edit_isActive" id="edit_isActive" class="form-check-input" checked>
                                        <label class="form-check-label small fw-bold" for="edit_isActive">Activa</label>
                                    </div>
                                </div>
                            </div>
                            <div class="btn-group btn-group-sm w-100" role="group">
                                <button type="button" class="btn btn-outline-secondary" onclick="ajustarVencimientoCliente(7)">+7 días</button>
                                <button type="button" class="btn btn-outline-secondary" onclick="ajustarVencimientoCliente(15)">+15 días</button>
                                <button type="button" class="btn btn-outline-secondary" onclick="ajustarVencimientoCliente(30)">+30 días</button>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL INFORMACIÓN COMPLETA DEL CLIENTE (FICHA COMPLETA CON HISTORIAL Y DEUDA) -->
    <div class="modal fade" id="modalInfoCliente" tabindex="-1" aria-labelledby="modalInfoClienteLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content shadow-lg border-0">
                <div class="modal-header bg-gradient bg-primary text-white">
                    <h5 class="modal-title fw-bold" id="modalInfoClienteLabel">
                        <i class="fas fa-id-card me-2"></i> Ficha del Socio: <span id="info_fullName" class="text-warning"></span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body p-4" style="max-height: 80vh; overflow-y: auto;">
                    
                    <!-- ALERTA DE DEUDA PENDIENTE DESTACADA -->
                    <div id="info_seccionAlertaDeuda" class="alert alert-danger p-3 mb-4 shadow-sm border-danger" style="display:none;">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div>
                                <h6 class="alert-heading mb-1 fw-bold"><i class="fas fa-exclamation-triangle fs-5 me-1"></i> CLIENTE CUENTA CON DEUDA ATRASADA PENDIENTE</h6>
                                <p class="mb-0 small">Saldo total acumulado por pagar: <strong id="info_montoDeudaTotal" class="font-monospace fs-5 text-dark">$0.00</strong></p>
                            </div>
                            <a id="info_btnIrAbonar" href="/pages/abonos.php" class="btn btn-danger fw-bold shadow-sm">
                                <i class="fas fa-hand-holding-dollar me-1"></i> Ir a Abonar / Liquidar Deuda
                            </a>
                        </div>
                    </div>

                    <!-- Datos Generales y Estado Actual -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="card h-100 border bg-light-subtle">
                                <div class="card-header bg-white py-2 fw-bold text-muted small text-uppercase">
                                    <i class="fas fa-user me-1 text-primary"></i> Información Personal
                                </div>
                                <div class="card-body small">
                                    <p class="mb-1"><strong>Nombre:</strong> <span id="info_nombre"></span></p>
                                    <p class="mb-1"><strong>Género:</strong> <span id="info_genero" class="text-capitalize"></span></p>
                                    <p class="mb-1"><strong>Teléfono:</strong> <span id="info_telefono"></span></p>
                                    <p class="mb-1"><strong>Dirección:</strong> <span id="info_direccion"></span></p>
                                    <p class="mb-1"><strong>Fecha de Nacimiento:</strong> <span id="info_nacimiento"></span></p>
                                    <p class="mb-0"><strong>Huella / ID:</strong> <span id="info_huella" class="font-monospace"></span></p>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="card h-100 border bg-light-subtle">
                                <div class="card-header bg-white py-2 fw-bold text-muted small text-uppercase">
                                    <i class="fas fa-id-card-alt me-1 text-success"></i> Estado de Membresía Actual
                                </div>
                                <div class="card-body small">
                                    <div id="info_badgeEstadoMem" class="mb-2"></div>
                                    <p class="mb-1"><strong>Plan Actual:</strong> <span id="info_planActual" class="fw-bold"></span></p>
                                    <p class="mb-1"><strong>Inicio:</strong> <span id="info_inicioMem" class="font-monospace"></span></p>
                                    <p class="mb-1"><strong>Vencimiento:</strong> <span id="info_finMem" class="font-monospace"></span></p>
                                    <p class="mb-0"><strong>Días Restantes:</strong> <span id="info_diasRestantes" class="fw-bold"></span></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- SECCIÓN: VENDER OTRA MEMBRESÍA / SUMAR A VIGENCIA ACTUAL -->
                    <div class="card mb-4 border border-primary-subtle shadow-xs bg-light-subtle">
                        <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                            <span class="fw-bold small text-uppercase text-primary"><i class="fas fa-credit-card me-1"></i> Vender Otra Membresía / Renovar y Sumar a Vigencia</span>
                            <span class="badge bg-primary-subtle text-primary border" id="vender_badgeTipoAccion">Suma automática</span>
                        </div>
                        <div class="card-body p-3">
                            <form method="POST" id="formVenderOtraMembresia">
                                <input type="hidden" name="accion" value="vender_membresia">
                                <input type="hidden" name="cliente_id" id="vender_cliente_id" value="">
                                
                                <div class="row g-2 mb-2">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted mb-1">Membresía a Vender:</label>
                                        <select name="membershipId" id="vender_membershipId" class="form-select form-select-sm" required onchange="calcularPrevisualizacionVentaMem()">
                                            <option value="">-- Seleccionar plan a vender --</option>
                                            <?php foreach ($membresias as $m): ?>
                                            <option value="<?php echo $m['id']; ?>" data-cost="<?php echo $m['cost']; ?>" data-duration="<?php echo $m['durationDays']; ?>" data-name="<?php echo htmlspecialchars($m['name']); ?>">
                                                <?php echo htmlspecialchars($m['name']); ?> ($<?php echo number_format($m['cost'], 2); ?> — <?php echo $m['durationDays']; ?> días)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted mb-1">Método de Pago:</label>
                                        <select name="paymentMethod" id="vender_paymentMethod" class="form-select form-select-sm" onchange="calcularPrevisualizacionVentaMem()">
                                            <option value="efectivo">💵 Efectivo</option>
                                            <option value="tarjeta">💳 Tarjeta (Terminal Bancaria)</option>
                                            <option value="transferencia">📲 Transferencia / SPEI</option>
                                            <option value="credito">⚠️ A Crédito (Dejar en Deuda al Socio)</option>
                                        </select>
                                    </div>
                                </div>

                                <div id="vender_previewBox" class="alert alert-info py-2 px-3 small mb-2" style="display:none;">
                                    <div id="vender_previewTexto"></div>
                                </div>

                                <div class="text-end">
                                    <button type="submit" id="vender_btnSubmit" class="btn btn-sm btn-primary fw-bold" disabled>
                                        <i class="fas fa-check-circle me-1"></i> Cobrar y Sumar Membresía
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- TABLA: Historial de Membresías Compradas -->
                    <div class="card mb-4 border">
                        <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                            <span class="fw-bold small text-uppercase text-muted"><i class="fas fa-history me-1 text-indigo"></i> Membresías Compradas</span>
                            <span class="badge bg-secondary" id="info_conteoMembresias">0 compras</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive" style="max-height: 200px; overflow-y: auto;">
                                <table class="table table-sm table-hover align-middle mb-0 small">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Plan</th>
                                            <th>Inicio</th>
                                            <th>Vencimiento</th>
                                            <th>Costo</th>
                                            <th>Estado</th>
                                        </tr>
                                    </thead>
                                    <tbody id="info_tablaMembresiasBody">
                                        <tr><td colspan="5" class="text-center text-muted py-2">Sin historial de membresías</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- TABLA: Detalle de Deudas y Compras a Crédito -->
                    <div class="card border">
                        <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                            <span class="fw-bold small text-uppercase text-muted"><i class="fas fa-receipt me-1 text-danger"></i> Deudas y Compras a Crédito</span>
                            <span id="info_badgeDeudasConteo" class="badge bg-secondary">0 deudas</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive" style="max-height: 200px; overflow-y: auto;">
                                <table class="table table-sm table-hover align-middle mb-0 small">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Folio / Concepto</th>
                                            <th>Fecha</th>
                                            <th class="text-end">Total</th>
                                            <th class="text-end">Abonado</th>
                                            <th class="text-end">Saldo Restante</th>
                                            <th class="text-center">Estado</th>
                                        </tr>
                                    </thead>
                                    <tbody id="info_tablaDeudasBody">
                                        <tr><td colspan="6" class="text-center text-muted py-2">No cuenta con adeudos registrados</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar Ficha</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Eliminar con Verificación Admin -->
    <div class="modal fade" id="modalVerificarAdmin" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="fas fa-shield-alt me-1"></i> Autorización Requerida</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Para eliminar a este socio, ingresa la contraseña de administrador:</p>
                    <input type="password" id="auth_password" class="form-control" placeholder="Contraseña de administrador" autofocus>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-danger" onclick="verificarYConfirmarEliminar()"><i class="fas fa-trash me-1"></i> Confirmar Eliminación</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL DE AYUDA Y GUÍA DE BOTONES (?) SOLICITADO -->
    <div class="modal fade" id="modalAyudaClientes" tabindex="-1" aria-labelledby="modalAyudaClientesLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="modalAyudaClientesLabel">
                        <i class="fas fa-question-circle me-2"></i> Guía: ¿Qué hace cada botón y sección en esta página?
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="card h-100 border-primary">
                                <div class="card-header bg-primary-subtle text-primary fw-bold">
                                    <i class="fas fa-id-card me-1"></i> Botón "Info" (Ficha del Socio)
                                </div>
                                <div class="card-body small">
                                    Muestra toda la información del cliente: datos personales, estatus si está activo o caducado, <strong>todas las membresías compradas</strong> históricamente y si cuenta con <strong>deuda pendiente</strong> (mostrando el saldo y enlace a Abonos).
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="card h-100 border-danger">
                                <div class="card-header bg-danger-subtle text-danger fw-bold">
                                    <i class="fas fa-exclamation-circle me-1"></i> Aviso de Deuda Atrasada al Renovar
                                </div>
                                <div class="card-body small">
                                    Si el socio tiene un saldo pendiente por compras a crédito y se intenta renovar o asignar una membresía, el sistema mostrará automáticamente una alerta en rojo notificando que <strong>cuenta con deuda atrasada</strong>.
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="card h-100 border-warning">
                                <div class="card-header bg-warning-subtle text-warning-emphasis fw-bold">
                                    <i class="fas fa-filter me-1"></i> Botones de Filtro Rápido
                                </div>
                                <div class="card-body small">
                                    <ul>
                                        <li><strong>Todos:</strong> Lista a todos los socios ordenados alfabéticamente.</li>
                                        <li><strong>⚠️ Próximas:</strong> Socios cuya membresía vencerá en 1 a 5 días.</li>
                                        <li><strong>🔴 Caducadas:</strong> Socios con membresía vencida o inactiva.</li>
                                        <li><strong>⚠️ Con Adeudo:</strong> Filtra exclusivamente a clientes que deben dinero.</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="card h-100 border-success">
                                <div class="card-header bg-success-subtle text-success fw-bold">
                                    <i class="fas fa-door-open me-1"></i> Botón "Visita" (Verde en la tabla)
                                </div>
                                <div class="card-body small">
                                    Registra el ingreso o check-in del socio el día de hoy con 1 solo clic. Guarda la fecha y la hora exacta en la base de datos de visitas para el control de aforo y estadísticas.
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="card h-100 border-info">
                                <div class="card-header bg-info-subtle text-info fw-bold">
                                    <i class="fas fa-edit me-1"></i> Botón "Modificar" (Azul en la tabla)
                                </div>
                                <div class="card-body small">
                                    Permite actualizar los datos del socio y <strong>modificar directamente la vigencia de su membresía activa</strong> (+7d, +15d, +30d) o renovar plan.
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="card h-100 border-secondary">
                                <div class="card-header bg-light text-dark fw-bold">
                                    <i class="fas fa-palette me-1"></i> Código de Colores de las Filas
                                </div>
                                <div class="card-body small">
                                    <ul>
                                        <li><span class="badge bg-danger">Rojo / Deuda</span>: Cliente con deuda pendiente o membresía vencida.</li>
                                        <li><span class="badge bg-warning text-dark">Amarillo</span>: Por vencer en los próximos 1-5 días.</li>
                                        <li><span class="badge bg-success">Verde</span>: Membresía vigente con más de 5 días.</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Entendido / Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    let idEliminar = null;
    let clienteEnEdicion = null;

    function abrirModalAyuda() {
        abrirModalElemento('modalAyudaClientes');
    }

    function nuevoCliente() {
        clienteEnEdicion = null;
        document.getElementById('accion').value = 'crear';
        document.getElementById('cliente_id').value = '';
        document.getElementById('fullName').value = '';
        document.getElementById('phone').value = '';
        document.getElementById('address').value = '';
        document.getElementById('dateOfBirth').value = '';
        document.getElementById('fingerprint').value = '';
        document.getElementById('membershipId').value = '';
        document.getElementById('secModificarVigencia').style.display = 'none';
        document.getElementById('alertaDeudaRenovacion').style.display = 'none';
        abrirModalElemento('modalCliente');
    }

    function editarCliente(c) {
        clienteEnEdicion = c;
        document.getElementById('accion').value = 'editar';
        document.getElementById('cliente_id').value = c.id;
        document.getElementById('fullName').value = c.fullName;
        document.getElementById('gender').value = c.gender;
        document.getElementById('phone').value = c.phone || '';
        document.getElementById('address').value = c.address || '';
        document.getElementById('dateOfBirth').value = c.dateOfBirth || '';
        document.getElementById('fingerprint').value = c.fingerprint || '';
        document.getElementById('membershipId').value = '';
        
        // Cargar vigencia de membresía actual
        var secVig = document.getElementById('secModificarVigencia');
        secVig.style.display = 'block';
        document.getElementById('edit_endDate').value = c.endDate || '';
        document.getElementById('edit_isActive').checked = (c.dias_restantes !== null && c.dias_restantes >= 0);

        // AVISO DE DEUDA ATRASADA SI EL CLIENTE TIENE DEUDA
        var alerta = document.getElementById('alertaDeudaRenovacion');
        var deudaMonto = parseFloat(c.deuda_pendiente || 0);
        if (deudaMonto > 0) {
            alerta.style.display = 'block';
            document.getElementById('montoDeudaAvisoRenovacion').innerText = '$' + deudaMonto.toFixed(2);
            document.getElementById('btnIrAbonarDesdeModal').href = '/pages/abonos.php?cliente_id=' + c.id;
        } else {
            alerta.style.display = 'none';
        }

        abrirModalElemento('modalCliente');
    }

    // AVISO AUTOMÁTICO SI SELECCIONA RENOVAR Y TIENE DEUDA ATRASADA
    document.getElementById('membershipId').addEventListener('change', function() {
        if (this.value && clienteEnEdicion && parseFloat(clienteEnEdicion.deuda_pendiente || 0) > 0) {
            alert('⚠️ ¡AVISO! El cliente ' + clienteEnEdicion.fullName + ' cuenta con una DEUDA ATRASADA PENDIENTE de $' + parseFloat(clienteEnEdicion.deuda_pendiente).toFixed(2) + '.\n\nPuedes continuar con la renovación pero recuerda cobrar o registrar el adeudo.');
        }
    });

    // VER FICHA COMPLETA DEL CLIENTE (Membresías compradas, Activo/Inactivo y Deuda)
    function verInfoCliente(cId) {
        const fd = new FormData();
        fd.append('accion', 'obtener_info_cliente');
        fd.append('cliente_id', cId);

        fetch('clientes.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'ok') {
                alert(data.message || 'Error al obtener datos');
                return;
            }

            const c = data.cliente;
            const mem = data.membresiaActual;
            const historial = data.historialMembresias || [];
            const deudas = data.deudas || [];
            const totalDeuda = parseFloat(data.totalDeudaPendiente || 0);

            // 1. Datos personales
            document.getElementById('info_fullName').innerText = c.fullName;
            document.getElementById('info_nombre').innerText = c.fullName;
            document.getElementById('info_genero').innerText = c.gender || '-';
            document.getElementById('info_telefono').innerText = c.phone || 'No registrado';
            document.getElementById('info_direccion').innerText = c.address || 'No registrada';
            document.getElementById('info_nacimiento').innerText = c.dateOfBirth || '-';
            document.getElementById('info_huella').innerText = c.fingerprint || 'Sin huella';

            // 2. Estado de membresía
            const badgeMem = document.getElementById('info_badgeEstadoMem');
            const dias = mem ? parseInt(mem.dias_restantes) : -999;
            const esActiva = mem && mem.isActive == 1 && dias >= 0;

            if (esActiva) {
                badgeMem.innerHTML = '<span class="badge bg-success fs-6"><i class="fas fa-check-circle me-1"></i> SOCIO ACTIVO (' + dias + ' días restantes)</span>';
            } else {
                badgeMem.innerHTML = '<span class="badge bg-danger fs-6"><i class="fas fa-times-circle me-1"></i> SOCIO INACTIVO / MEMBRESÍA VENCIDA</span>';
            }

            document.getElementById('info_planActual').innerText = mem ? mem.membership_name : 'Sin plan';
            document.getElementById('info_inicioMem').innerText = mem ? mem.startDate : '-';
            document.getElementById('info_finMem').innerText = mem ? mem.endDate : '-';
            document.getElementById('info_diasRestantes').innerText = mem && dias >= 0 ? dias + ' días' : '0 (Caducada)';

            // 3. Alerta de Deuda Pendiente
            const seccionAlertaDeuda = document.getElementById('info_seccionAlertaDeuda');
            if (totalDeuda > 0) {
                seccionAlertaDeuda.style.display = 'block';
                document.getElementById('info_montoDeudaTotal').innerText = '$' + totalDeuda.toFixed(2);
                document.getElementById('info_btnIrAbonar').href = '/pages/abonos.php?cliente_id=' + c.id;
            } else {
                seccionAlertaDeuda.style.display = 'none';
            }

            // 4. Tabla de Membresías Compradas
            document.getElementById('info_conteoMembresias').innerText = historial.length + ' registro(s)';
            const tMems = document.getElementById('info_tablaMembresiasBody');
            if (historial.length === 0) {
                tMems.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-2">Sin historial de membresías compradas</td></tr>';
            } else {
                tMems.innerHTML = historial.map(h => {
                    const diasH = parseInt(h.dias_restantes);
                    const vigente = (h.isActive == 1 && diasH >= 0);
                    const badge = vigente 
                        ? '<span class="badge bg-success">Vigente</span>' 
                        : '<span class="badge bg-secondary">Vencida</span>';
                    const costo = h.cost ? '$' + parseFloat(h.cost).toFixed(2) : '-';
                    return '<tr>' +
                        '<td class="fw-bold">' + h.membership_name + '</td>' +
                        '<td class="font-monospace">' + h.startDate + '</td>' +
                        '<td class="font-monospace">' + h.endDate + '</td>' +
                        '<td class="font-monospace text-success">' + costo + '</td>' +
                        '<td>' + badge + '</td>' +
                    '</tr>';
                }).join('');
            }

            // 5. Tabla de Deudas
            document.getElementById('info_badgeDeudasConteo').innerText = deudas.length + ' adeudo(s)';
            const tDeudas = document.getElementById('info_tablaDeudasBody');
            if (deudas.length === 0) {
                tDeudas.innerHTML = '<tr><td colspan="6" class="text-center text-success py-2"><i class="fas fa-check-circle me-1"></i> El socio está al corriente, sin deudas registradas</td></tr>';
            } else {
                tDeudas.innerHTML = deudas.map(d => {
                    const folio = d.folio ? '#' + d.folio : ('ID #' + d.id);
                    const esLiq = d.status === 'liquidada';
                    const badge = esLiq 
                        ? '<span class="badge bg-success">Liquidada</span>' 
                        : '<span class="badge bg-danger">Pendiente</span>';
                    return '<tr>' +
                        '<td><strong>' + folio + '</strong><br><small class="text-muted">' + (d.description || '') + '</small></td>' +
                        '<td class="font-monospace small">' + d.createdAt + '</td>' +
                        '<td class="text-end font-monospace">$' + parseFloat(d.totalAmount).toFixed(2) + '</td>' +
                        '<td class="text-end font-monospace text-success">$' + parseFloat(d.paidAmount).toFixed(2) + '</td>' +
                        '<td class="text-end font-monospace fw-bold ' + (esLiq ? 'text-muted' : 'text-danger') + '">$' + parseFloat(d.remainingAmount).toFixed(2) + '</td>' +
                        '<td class="text-center">' + badge + '</td>' +
                    '</tr>';
                }).join('');
            }

            abrirModalElemento('modalInfoCliente');

            // Preparar sección de vender membresía
            document.getElementById('vender_cliente_id').value = c.id;
            clienteActualFinMem = (mem && mem.endDate) ? mem.endDate : null;
            clienteActualEsActiva = esActiva;
            document.getElementById('vender_membershipId').value = '';
            document.getElementById('vender_paymentMethod').value = 'efectivo';
            document.getElementById('vender_previewBox').style.display = 'none';

            // RESTRICCIÓN DE DEUDA:
            const deudaTotalPendiente = deudas.filter(d => d.status !== 'liquidada').reduce((acc, d) => acc + parseFloat(d.remainingAmount || 0), 0);
            const selectMemEl = document.getElementById('vender_membershipId');
            const submitMemBtn = document.getElementById('vender_btnSubmit');
            const badgeTipo = document.getElementById('vender_badgeTipoAccion');

            if (deudaTotalPendiente > 0) {
                selectMemEl.disabled = true;
                document.getElementById('vender_paymentMethod').disabled = true;
                submitMemBtn.disabled = true;
                submitMemBtn.innerHTML = '<i class="fas fa-ban me-1"></i> Bloqueado: Debe liquidar deuda ($' + deudaTotalPendiente.toFixed(2) + ')';
                badgeTipo.className = 'badge bg-danger text-white border';
                badgeTipo.innerText = '⛔ Bloqueado por Deuda';
            } else {
                selectMemEl.disabled = false;
                document.getElementById('vender_paymentMethod').disabled = false;
                submitMemBtn.disabled = true;
                submitMemBtn.innerHTML = '<i class="fas fa-check-circle me-1"></i> Cobrar y Sumar Membresía';
                badgeTipo.className = 'badge bg-primary-subtle text-primary border';
                badgeTipo.innerText = esActiva ? 'Suma a días actuales' : 'Activación inmediata';
            }
        })
        .catch(err => {
            alert('Error al consultar datos del socio');
            console.error(err);
        });
    }

    let clienteActualFinMem = null;
    let clienteActualEsActiva = false;

    function calcularPrevisualizacionVentaMem() {
        const selectMem = document.getElementById('vender_membershipId');
        const opt = selectMem.options[selectMem.selectedIndex];
        const btn = document.getElementById('vender_btnSubmit');
        const box = document.getElementById('vender_previewBox');
        const txt = document.getElementById('vender_previewTexto');
        const metodo = document.getElementById('vender_paymentMethod').value;

        if (!opt || !opt.value) {
            btn.disabled = true;
            box.style.display = 'none';
            return;
        }

        btn.disabled = false;
        box.style.display = 'block';

        const dias = parseInt(opt.getAttribute('data-duration') || '30');
        const costo = parseFloat(opt.getAttribute('data-cost') || '0').toFixed(2);
        const nombre = opt.getAttribute('data-name');

        let baseDate = new Date();
        const hoyStr = new Date().toISOString().split('T')[0];
        let seSuma = false;

        if (clienteActualEsActiva && clienteActualFinMem && clienteActualFinMem > hoyStr) {
            baseDate = new Date(clienteActualFinMem + 'T00:00:00');
            seSuma = true;
        }

        baseDate.setDate(baseDate.getDate() + dias);
        const yyyy = baseDate.getFullYear();
        const mm = String(baseDate.getMonth() + 1).padStart(2, '0');
        const dd = String(baseDate.getDate()).padStart(2, '0');
        const nuevaFechaFin = yyyy + '-' + mm + '-' + dd;

        let avisoCredito = (metodo === 'credito') 
            ? '<br><strong class="text-danger"><i class="fas fa-exclamation-triangle"></i> Venta a Crédito: Se registrarán $' + costo + ' como adeudo pendiente del socio.</strong>' 
            : '';

        if (seSuma) {
            txt.innerHTML = '<strong><i class="fas fa-plus-circle text-success me-1"></i> Suma acumulativa a vigencia actual:</strong><br>' +
                'El socio cuenta con vigencia hasta el <strong>' + clienteActualFinMem + '</strong>. Al sumar <strong>' + dias + ' días</strong>, la nueva fecha de vencimiento será: <strong class="text-success text-decoration-underline">' + nuevaFechaFin + '</strong> (¡no pierde los días vigentes!). Total a cobrar: <strong>$' + costo + ' MXN</strong>' + avisoCredito;
            btn.innerHTML = '<i class="fas fa-check-circle me-1"></i> Cobrar $' + costo + ' y Sumar a Vigencia (' + nuevaFechaFin + ')';
        } else {
            txt.innerHTML = '<strong><i class="fas fa-bolt text-primary me-1"></i> Activación inmediata:</strong><br>' +
                'La membresía se pondrá activa a partir de hoy hasta el <strong class="text-primary text-decoration-underline">' + nuevaFechaFin + '</strong> (' + dias + ' días). Total a cobrar: <strong>$' + costo + ' MXN</strong>' + avisoCredito;
            btn.innerHTML = '<i class="fas fa-check-circle me-1"></i> Cobrar $' + costo + ' y Activar Membresía (' + nuevaFechaFin + ')';
        }
    }

    function ajustarVencimientoCliente(dias) {
        var inputEndDate = document.getElementById('edit_endDate');
        var actual = inputEndDate.value ? new Date(inputEndDate.value + 'T00:00:00') : new Date();
        actual.setDate(actual.getDate() + dias);
        var yyyy = actual.getFullYear();
        var mm = String(actual.getMonth() + 1).padStart(2, '0');
        var dd = String(actual.getDate()).padStart(2, '0');
        inputEndDate.value = yyyy + '-' + mm + '-' + dd;
    }

    function abrirModalElemento(id) {
        var el = document.getElementById(id);
        if (!el) return;
        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            var modal = bootstrap.Modal.getInstance(el) || new bootstrap.Modal(el);
            modal.show();
        } else {
            el.classList.add('show');
            el.style.display = 'block';
            var backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show fallback-backdrop';
            document.body.appendChild(backdrop);
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('[data-bs-dismiss="modal"]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var modal = this.closest('.modal');
                if (modal) {
                    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                        var inst = bootstrap.Modal.getInstance(modal);
                        if (inst) inst.hide();
                    }
                    modal.classList.remove('show');
                    modal.style.display = 'none';
                    document.querySelectorAll('.modal-backdrop, .fallback-backdrop').forEach(function(b) { b.remove(); });
                }
            });
        });
    });

    function solicitarEliminacion(id) {
        idEliminar = id;
        document.getElementById('auth_password').value = '';
        abrirModalElemento('modalVerificarAdmin');
    }

    function verificarYConfirmarEliminar() {
        const p = document.getElementById('auth_password').value;
        if (!p) {
            alert('Ingresa la contraseña de administrador');
            return;
        }
        const fd = new FormData();
        fd.append('accion', 'verificar_admin');
        fd.append('username', 'admin@gimnasio.local');
        fd.append('password', p);
        fetch('clientes.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.status === 'ok') {
                const f = document.createElement('form');
                f.method = 'POST';
                f.innerHTML = '<input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="' + idEliminar + '">';
                document.body.appendChild(f);
                f.submit();
            } else {
                alert(d.message);
            }
        });
    }
    </script>
</body>
</html>
