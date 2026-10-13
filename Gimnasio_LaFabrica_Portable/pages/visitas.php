<?php
require_once __DIR__ . '/../includes/config.php';
verificarAutenticacion();
$usuario = obtenerUsuarioActual();
$mensaje = ''; $tipo_mensaje = '';
$encontrados = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'buscar') {
        $q = "%" . escapar($_POST['q']) . "%";
        $encontrados = obtenerTodas("SELECT * FROM clients WHERE fullName LIKE :q OR fingerprint LIKE :q LIMIT 5", [':q' => $q]);
    } elseif ($accion === 'registrar_visita') {
        $cid = (int)$_POST['client_id'];
        $mem = obtenerFila("SELECT MAX(endDate) as endDate FROM clientmemberships WHERE clientId = :id", [':id' => $cid]);
        $hoy = date('Y-m-d');
        
        if (!$mem || empty($mem['endDate'])) {
            $mensaje = "🔴 <strong>¡Acceso Denegado!</strong> El cliente no tiene membresía asignada.";
            $tipo_mensaje = "danger";
        } else {
            $endTs = strtotime($mem['endDate']);
            $todayTs = strtotime($hoy);
            $dias = floor(($endTs - $todayTs) / 86400);

            if ($dias < 0) {
                $mensaje = "🔴 <strong>¡Acceso Denegado!</strong> Membresía CADUCADA (hace " . abs($dias) . " días).";
                $tipo_mensaje = "danger";
            } else {
                insertar('visits', ['clientId' => $cid, 'visitDate' => $hoy]);
                if ($dias <= 5) {
                    $mensaje = "⚠️ <strong>Entrada Permitida.</strong> Membresía por vencer en <strong>$dias días</strong>.";
                    $tipo_mensaje = "warning";
                } else {
                    $mensaje = "🟢 <strong>Entrada Permitida.</strong> Membresía activa ($dias días restantes).";
                    $tipo_mensaje = "success";
                }
            }
        }
    } elseif ($accion === 'visita_temporal') {
        // Cobro Pase Diario dinámico según catálogo de membresías
        $mem = obtenerFila("SELECT id, name, cost FROM memberships WHERE durationDays = 1 OR name LIKE '%diari%' ORDER BY durationDays ASC LIMIT 1");
        $cost = $mem ? (float)$mem['cost'] : 25.00;
        $memId = $mem ? (int)$mem['id'] : 5;

        $visitorName = trim(escapar($_POST['visitorName'] ?? ''));
        if (empty($visitorName)) {
            $visitorName = 'Visitante Temporal';
        }

        insertar('temp_visits', ['visitorName' => $visitorName, 'membershipCost' => $cost]);
        insertar('membership_sales', ['clientId' => null, 'membershipId' => $memId, 'cost' => $cost]);
        $mensaje = "✅ Pase diario cobrado exitosamente ($" . number_format($cost, 2) . ") para <strong>" . htmlspecialchars($visitorName) . "</strong>.";
        $tipo_mensaje = "success";
    }
}

// Obtener precio y nombre actual de la membresía diaria
$memDiaria = obtenerFila("SELECT id, name, cost FROM memberships WHERE durationDays = 1 OR name LIKE '%diari%' ORDER BY durationDays ASC LIMIT 1");
$costoDiario = $memDiaria ? (float)$memDiaria['cost'] : 25.00;
$nombrePlanDiario = $memDiaria ? $memDiaria['name'] : 'Pase Diario';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Visitas - <?php echo APP_NAME; ?></title>
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
                <div class="page-header"><h1><i class="fas fa-door-open me-2"></i>Control de Accesos</h1></div>
                <?php if ($mensaje): ?><div class="alert alert-<?php echo $tipo_mensaje; ?>"><?php echo $mensaje; ?></div><?php endif; ?>

                <div class="row g-4">
                    <div class="col-md-7">
                        <div class="card h-100">
                            <div class="card-header bg-primary text-white"><i class="fas fa-search me-2"></i>Socio Registrado</div>
                            <div class="card-body">
                                <form method="POST" class="d-flex gap-2 mb-3">
                                    <input type="hidden" name="accion" value="buscar">
                                    <input type="text" name="q" class="form-control" placeholder="Nombre o código huella (ej: FP-8841)..." autofocus>
                                    <button class="btn btn-primary"><i class="fas fa-search"></i></button>
                                </form>
                                <?php if (!empty($encontrados)): ?>
                                <div class="list-group">
                                    <?php foreach ($encontrados as $c): ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <div><strong><?php echo htmlspecialchars($c['fullName']); ?></strong><br><small class="text-muted"><?php echo $c['fingerprint']?:'Sin huella'; ?></small></div>
                                        <form method="POST">
                                            <input type="hidden" name="accion" value="registrar_visita">
                                            <input type="hidden" name="client_id" value="<?php echo $c['id']; ?>">
                                            <button class="btn btn-success btn-sm"><i class="fas fa-check"></i> Check-in</button>
                                        </form>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-5">
                        <div class="card h-100 bg-warning-subtle border-warning shadow-sm">
                            <div class="card-header bg-warning fw-bold text-dark d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-ticket-alt me-2"></i>Pase Diario / Visita Temporal</span>
                                <span class="badge bg-dark font-monospace">$<?php echo number_format($costoDiario, 2); ?></span>
                            </div>
                            <div class="card-body text-start d-flex flex-column justify-content-between">
                                <div>
                                    <p class="small text-muted mb-3">Registra un visitante temporal sin membresía mensual. El precio está vinculado automáticamente al plan diario vigente.</p>
                                    <form method="POST">
                                        <input type="hidden" name="accion" value="visita_temporal">
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-dark">Nombre del Visitante / Cliente:</label>
                                            <input type="text" name="visitorName" class="form-control form-control-lg" placeholder="Ej: Carlos Mendoza..." required autofocus autocomplete="off">
                                        </div>
                                        <button type="submit" class="btn btn-warning btn-lg w-100 py-3 fw-bold shadow-sm">
                                            <i class="fas fa-ticket-alt me-1"></i> Cobrar <?php echo htmlspecialchars($nombrePlanDiario); ?> ($<?php echo number_format($costoDiario, 2); ?>)
                                        </button>
                                    </form>
                                </div>
                                <div class="mt-3 p-2 bg-white rounded border small text-muted">
                                    <i class="fas fa-info-circle text-primary me-1"></i> El ingreso se registrará en las visitas de hoy y en el total de pases diarios cobrados.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>