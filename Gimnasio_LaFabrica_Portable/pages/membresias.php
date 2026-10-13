<?php
require_once __DIR__ . '/../includes/config.php';
verificarAutenticacion();
$usuario = obtenerUsuarioActual();
$mensaje = '';
$tipo_mensaje = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    // 1. Acciones sobre Planes del Catálogo (Admin)
    if ($usuario['role'] === 'admin' || $usuario['role'] === 'administrador') {
        if ($accion === 'crear') {
            $nombre = escapar($_POST['name'] ?? '');
            if (!empty($nombre)) {
                insertar('memberships', [
                    'name'         => $nombre,
                    'cost'         => (float)($_POST['cost'] ?? 0),
                    'durationDays' => (int)($_POST['durationDays'] ?? 30),
                    'isActive'     => isset($_POST['isActive']) ? 1 : 0
                ]);
                $mensaje = '✅ Plan "' . htmlspecialchars($nombre) . '" creado exitosamente.';
            }
        } elseif ($accion === 'editar') {
            $id = (int)$_POST['id'];
            $nombre = escapar($_POST['name'] ?? '');
            if ($id > 0 && !empty($nombre)) {
                actualizar('memberships', [
                    'name'         => $nombre,
                    'cost'         => (float)($_POST['cost'] ?? 0),
                    'durationDays' => (int)($_POST['durationDays'] ?? 30),
                    'isActive'     => isset($_POST['isActive']) ? 1 : 0
                ], 'id = :id', [':id' => $id]);
                $mensaje = '✅ Plan "' . htmlspecialchars($nombre) . '" modificado correctamente.';
            }
        } elseif ($accion === 'toggle_activa') {
            $id = (int)$_POST['id'];
            $m = obtenerFila("SELECT id, name, isActive FROM memberships WHERE id = :id", [':id' => $id]);
            if ($m) {
                $nuevoEstado = $m['isActive'] ? 0 : 1;
                actualizar('memberships', ['isActive' => $nuevoEstado], 'id = :id', [':id' => $id]);
                $mensaje = 'Plan "' . htmlspecialchars($m['name']) . '" cambiado a ' . ($nuevoEstado ? 'ACTIVO' : 'INACTIVO') . '.';
            }
        } elseif ($accion === 'eliminar') {
            eliminar('memberships', 'id = :id', [':id' => (int)$_POST['id']]);
            $mensaje = 'Plan eliminado del catálogo.';
        }
    }

    // 2. Acciones sobre Membresías Activas de Socios (Admin o Cajero autorizado)
    if ($accion === 'editar_socio_membresia') {
        $cmId = (int)$_POST['cmId'];
        $memId = (int)$_POST['membershipId'];
        $startDate = escapar($_POST['startDate'] ?? '');
        $endDate = escapar($_POST['endDate'] ?? '');
        $isActive = isset($_POST['isActive']) ? 1 : 0;
        
        if ($cmId > 0 && !empty($startDate) && !empty($endDate)) {
            actualizar('clientmemberships', [
                'membershipId' => $memId,
                'startDate'    => $startDate,
                'endDate'      => $endDate,
                'isActive'     => $isActive
            ], 'id = :id', [':id' => $cmId]);
            $mensaje = '✅ Membresía activa del socio modificada exitosamente.';
        }
    } elseif ($accion === 'toggle_activa_socio') {
        $cmId = (int)$_POST['cmId'];
        $cm = obtenerFila("SELECT id, isActive FROM clientmemberships WHERE id = :id", [':id' => $cmId]);
        if ($cm) {
            $nuevoEstado = $cm['isActive'] ? 0 : 1;
            actualizar('clientmemberships', ['isActive' => $nuevoEstado], 'id = :id', [':id' => $cmId]);
            $mensaje = 'Estado de membresía cambiado a ' . ($nuevoEstado ? 'ACTIVA / VIGENTE' : 'INACTIVA / CONCLUIDA') . '.';
        }
    }
}

// Obtener datos del catálogo
$membresias = obtenerTodas("SELECT * FROM memberships ORDER BY cost ASC");

// Obtener socios con membresías activas o vigentes
$filtro_socio = trim($_GET['qs'] ?? '');
$sqlSocios = "
    SELECT cm.id as cmId, c.id as clientId, c.fullName, c.phone, m.id as membershipId, m.name as plan, 
           cm.startDate, cm.endDate, cm.isActive,
           CAST(julianday(cm.endDate) - julianday(date('now', 'localtime')) AS INTEGER) as dias_restantes
    FROM clientmemberships cm
    JOIN clients c ON cm.clientId = c.id
    JOIN memberships m ON cm.membershipId = m.id
    WHERE cm.isActive = 1
";
$paramsSocios = [];
if ($filtro_socio !== '') {
    $sqlSocios .= " AND (c.fullName LIKE :qs OR m.name LIKE :qs)";
    $paramsSocios[':qs'] = "%$filtro_socio%";
}
$sqlSocios .= " ORDER BY cm.endDate ASC";
$sociosActivos = obtenerTodas($sqlSocios, $paramsSocios);

// Métricas rápidas
$totalPlanes = count($membresias);
$planesActivos = count(array_filter($membresias, fn($m) => $m['isActive']));
$totalSociosActivos = count($sociosActivos);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Membresías - <?php echo APP_NAME; ?></title>
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
                <h1><i class="fas fa-id-card me-2"></i>Gestión de Membresías</h1>
                <p class="text-muted mb-0 small">Modifica planes de membresía y vigencias activas de socios</p>
            </div>
            <?php if ($usuario['role'] === 'admin' || $usuario['role'] === 'administrador'): ?>
            <button type="button" class="btn btn-primary" id="btnNuevaMem">
                <i class="fas fa-plus me-1"></i> Nuevo Plan de Membresía
            </button>
            <?php endif; ?>
        </div>

        <?php if ($mensaje): ?>
            <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                <?php echo $mensaje; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Métricas -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card p-3 border-start border-4 border-primary shadow-sm">
                    <div class="text-muted small fw-bold">PLANES EN CATÁLOGO</div>
                    <div class="fs-3 fw-bold text-primary"><?php echo $planesActivos; ?> <span class="fs-6 text-muted font-monospace">/ <?php echo $totalPlanes; ?> total</span></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card p-3 border-start border-4 border-success shadow-sm">
                    <div class="text-muted small fw-bold">SOCIOS CON MEMBRESÍA ACTIVA</div>
                    <div class="fs-3 fw-bold text-success"><?php echo $totalSociosActivos; ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card p-3 border-start border-4 border-warning shadow-sm">
                    <div class="text-muted small fw-bold">POR VENCER (1-5 DÍAS)</div>
                    <div class="fs-3 fw-bold text-warning">
                        <?php echo count(array_filter($sociosActivos, fn($s) => $s['dias_restantes'] >= 1 && $s['dias_restantes'] <= 5)); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pestañas de Navegación -->
        <ul class="nav nav-tabs mb-3" id="tabMembresias" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active fw-bold" id="planes-tab" data-bs-toggle="tab" data-bs-target="#tab-planes" type="button" role="tab">
                    <i class="fas fa-tags me-1"></i> Planes del Catálogo (<?php echo $totalPlanes; ?>)
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-bold" id="socios-tab" data-bs-toggle="tab" data-bs-target="#tab-socios" type="button" role="tab">
                    <i class="fas fa-users-cog me-1"></i> Membresías Activas de Socios (<?php echo $totalSociosActivos; ?>)
                </button>
            </li>
        </ul>

        <div class="tab-content" id="tabMembresiasContent">
            <!-- TAB 1: CATÁLOGO DE PLANES -->
            <div class="tab-pane fade show active" id="tab-planes" role="tabpanel">
                <div class="card">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <span class="fw-bold"><i class="fas fa-list me-1"></i> Planes y Precios Configurados</span>
                        <span class="text-muted small">Haz clic en "Modificar" para editar precio, duración o estado</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Nombre del Plan</th>
                                    <th>Costo ($)</th>
                                    <th>Duración</th>
                                    <th>Estado</th>
                                    <th class="text-end">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($membresias as $m): ?>
                                <tr>
                                    <td>
                                        <span class="fw-bold fs-6"><?php echo htmlspecialchars($m['name']); ?></span>
                                    </td>
                                    <td class="text-success fw-bold font-monospace fs-6">$<?php echo number_format($m['cost'], 2); ?></td>
                                    <td><i class="far fa-calendar-alt text-muted me-1"></i> <?php echo $m['durationDays']; ?> días</td>
                                    <td>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="accion" value="toggle_activa">
                                            <input type="hidden" name="id" value="<?php echo $m['id']; ?>">
                                            <button type="submit" class="btn btn-sm <?php echo $m['isActive'] ? 'btn-success' : 'btn-secondary'; ?> rounded-pill" title="Clic para alternar Activa / Inactiva">
                                                <i class="fas <?php echo $m['isActive'] ? 'fa-check-circle' : 'fa-pause-circle'; ?> me-1"></i>
                                                <?php echo $m['isActive'] ? 'Activa' : 'Inactiva'; ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($usuario['role'] === 'admin' || $usuario['role'] === 'administrador'): ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary me-1" onclick='abrirEditarPlan(<?php echo json_encode($m); ?>)' title="Modificar este plan">
                                            <i class="fas fa-edit me-1"></i> Modificar
                                        </button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('¿Seguro que deseas eliminar esta membresía del catálogo?');">
                                            <input type="hidden" name="accion" value="eliminar">
                                            <input type="hidden" name="id" value="<?php echo $m['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar"><i class="fas fa-trash"></i></button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB 2: MEMBRESÍAS ACTIVAS DE SOCIOS -->
            <div class="tab-pane fade" id="tab-socios" role="tabpanel">
                <div class="card mb-3">
                    <div class="card-body p-3">
                        <form method="GET" class="d-flex gap-2">
                            <input type="text" name="qs" class="form-control" placeholder="Buscar socio activo por nombre o plan..." value="<?php echo htmlspecialchars($filtro_socio); ?>">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-search me-1"></i> Buscar</button>
                            <?php if ($filtro_socio): ?>
                                <a href="/pages/membresias.php" class="btn btn-outline-secondary">Ver Todos</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Socio</th>
                                    <th>Plan Asignado</th>
                                    <th>Fecha Inicio</th>
                                    <th>Fecha Vencimiento</th>
                                    <th class="text-center">Días Restantes</th>
                                    <th>Estado</th>
                                    <th class="text-end">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($sociosActivos)): ?>
                                    <tr><td colspan="7" class="text-center py-4 text-muted">No hay socios con membresía activa bajo este criterio.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($sociosActivos as $sa): 
                                    $d = (int)$sa['dias_restantes'];
                                    $badgeClass = ($d <= 0) ? 'bg-danger' : (($d <= 5) ? 'bg-warning text-dark' : 'bg-success');
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold"><?php echo htmlspecialchars($sa['fullName']); ?></div>
                                        <?php if ($sa['phone']): ?><small class="text-muted"><i class="fas fa-phone small me-1"></i><?php echo htmlspecialchars($sa['phone']); ?></small><?php endif; ?>
                                    </td>
                                    <td><span class="badge bg-primary fs-6"><?php echo htmlspecialchars($sa['plan']); ?></span></td>
                                    <td class="font-monospace text-muted"><?php echo $sa['startDate']; ?></td>
                                    <td class="font-monospace fw-bold"><?php echo $sa['endDate']; ?></td>
                                    <td class="text-center">
                                        <span class="badge <?php echo $badgeClass; ?> fs-6">
                                            <?php echo ($d > 0) ? "$d días" : ($d === 0 ? "Vence Hoy" : "Vencida (" . abs($d) . "d)"); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Activa</span>
                                    </td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-info text-white me-1" onclick='abrirModificarSocioMembresia(<?php echo json_encode($sa); ?>)' title="Modificar vigencia o plan activo">
                                            <i class="fas fa-calendar-check me-1"></i> Modificar Vigencia
                                        </button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('¿Desactivar esta membresía activa? El socio pasará a estar inactivo.');">
                                            <input type="hidden" name="accion" value="toggle_activa_socio">
                                            <input type="hidden" name="cmId" value="<?php echo $sa['cmId']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-warning" title="Desactivar">
                                                <i class="fas fa-pause"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main></div></div>

    <!-- Modal Crear Plan de Membresía -->
    <div class="modal fade" id="modalMem" tabindex="-1" aria-labelledby="modalMemLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalMemLabel"><i class="fas fa-id-card me-1"></i> Nuevo Plan de Membresía</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="crear">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Nombre del Plan <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" placeholder="Ej: Mensualidad, Anualidad, Plan Pareja..." required autofocus>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-bold">Costo ($) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="0" name="cost" class="form-control" placeholder="350.00" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-bold">Duración (Días) <span class="text-danger">*</span></label>
                                <input type="number" min="1" name="durationDays" class="form-control" value="30" required>
                            </div>
                        </div>
                        <div class="form-check form-switch mt-2">
                            <input type="checkbox" name="isActive" class="form-check-input" checked id="actPlan">
                            <label class="form-check-label fw-bold" for="actPlan">Plan Activo (Disponible para socios)</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Crear Plan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Modificar Plan de Membresía (Catálogo) -->
    <div class="modal fade" id="modalEditarMem" tabindex="-1" aria-labelledby="modalEditarMemLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title" id="modalEditarMemLabel"><i class="fas fa-edit me-1"></i> Modificar Plan de Membresía</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="editar">
                        <input type="hidden" name="id" id="edit_mem_id">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Nombre del Plan <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="edit_mem_name" class="form-control" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-bold">Costo ($) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="0" name="cost" id="edit_mem_cost" class="form-control" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-bold">Duración (Días) <span class="text-danger">*</span></label>
                                <input type="number" min="1" name="durationDays" id="edit_mem_durationDays" class="form-control" required>
                            </div>
                        </div>
                        <div class="form-check form-switch mt-2">
                            <input type="checkbox" name="isActive" class="form-check-input" id="edit_mem_isActive">
                            <label class="form-check-label fw-bold" for="edit_mem_isActive">Plan Activo</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success"><i class="fas fa-check me-1"></i> Guardar Cambios</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Modificar Membresía Activa de Socio -->
    <div class="modal fade" id="modalModificarSocioMem" tabindex="-1" aria-labelledby="modalModificarSocioMemLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalModificarSocioMemLabel"><i class="fas fa-calendar-alt me-1"></i> Modificar Membresía Activa de Socio</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="editar_socio_membresia">
                        <input type="hidden" name="cmId" id="socio_cm_id">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Socio</label>
                            <input type="text" id="socio_nombre" class="form-control bg-light" readonly>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Plan Asignado</label>
                            <select name="membershipId" id="socio_plan_id" class="form-select" required>
                                <?php foreach ($membresias as $m): ?>
                                <option value="<?php echo $m['id']; ?>"><?php echo htmlspecialchars($m['name']); ?> ($<?php echo number_format($m['cost'], 2); ?> - <?php echo $m['durationDays']; ?> días)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-bold">Fecha Inicio</label>
                                <input type="date" name="startDate" id="socio_start_date" class="form-control" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-bold">Fecha Vencimiento</label>
                                <input type="date" name="endDate" id="socio_end_date" class="form-control" required>
                            </div>
                        </div>

                        <!-- Botones de Extensión Rápida -->
                        <div class="mb-3 p-2 bg-light rounded border">
                            <label class="form-label small fw-bold text-muted d-block mb-1">Extender Vigencia Rápida (+ Días a la fecha de vencimiento):</label>
                            <div class="btn-group btn-group-sm w-100" role="group">
                                <button type="button" class="btn btn-outline-primary" onclick="sumarDiasVencimiento(7)">+7 Días</button>
                                <button type="button" class="btn btn-outline-primary" onclick="sumarDiasVencimiento(15)">+15 Días</button>
                                <button type="button" class="btn btn-outline-primary" onclick="sumarDiasVencimiento(30)">+30 Días (1 Mes)</button>
                                <button type="button" class="btn btn-outline-primary" onclick="sumarDiasVencimiento(365)">+1 Año</button>
                            </div>
                        </div>

                        <div class="form-check form-switch mt-2">
                            <input type="checkbox" name="isActive" class="form-check-input" checked id="socio_is_active">
                            <label class="form-check-label fw-bold" for="socio_is_active">Membresía Activa / Vigente</label>
                            <div class="form-text small">Si desmarcas esta casilla, la membresía pasará a inactiva y no permitirá acceso al gimnasio.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i> Guardar Modificaciones</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts Bootstrap y Fallback JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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

    function abrirEditarPlan(m) {
        document.getElementById('edit_mem_id').value = m.id;
        document.getElementById('edit_mem_name').value = m.name;
        document.getElementById('edit_mem_cost').value = m.cost;
        document.getElementById('edit_mem_durationDays').value = m.durationDays;
        document.getElementById('edit_mem_isActive').checked = (m.isActive == 1);
        
        abrirModalElemento('modalEditarMem');
    }

    function abrirModificarSocioMembresia(sa) {
        document.getElementById('socio_cm_id').value = sa.cmId;
        document.getElementById('socio_nombre').value = sa.fullName;
        document.getElementById('socio_plan_id').value = sa.membershipId;
        document.getElementById('socio_start_date').value = sa.startDate;
        document.getElementById('socio_end_date').value = sa.endDate;
        document.getElementById('socio_is_active').checked = (sa.isActive == 1);
        
        abrirModalElemento('modalModificarSocioMem');
    }

    function sumarDiasVencimiento(dias) {
        var inputEndDate = document.getElementById('socio_end_date');
        var actual = inputEndDate.value ? new Date(inputEndDate.value + 'T00:00:00') : new Date();
        actual.setDate(actual.getDate() + dias);
        var yyyy = actual.getFullYear();
        var mm = String(actual.getMonth() + 1).padStart(2, '0');
        var dd = String(actual.getDate()).padStart(2, '0');
        inputEndDate.value = yyyy + '-' + mm + '-' + dd;
    }

    document.addEventListener('DOMContentLoaded', function() {
        var btnNuevo = document.getElementById('btnNuevaMem');
        if (btnNuevo) {
            btnNuevo.addEventListener('click', function(e) {
                e.preventDefault();
                abrirModalElemento('modalMem');
            });
        }

        // Cierre universal de modales
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
    </script>
</body>
</html>
