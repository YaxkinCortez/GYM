<?php
require_once __DIR__ . '/../includes/config.php';
verificarAutenticacion();
$usuario = obtenerUsuarioActual();

if ($usuario['role'] !== 'admin' && $usuario['role'] !== 'administrador') {
    header("Location: acceso_restringido.php");
    exit();
}

$mensaje = '';
$tipo_mensaje = 'success';

// 1. Obtener todas las tablas existentes en la base de datos SQLite
$tablasQuery = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name ASC");
$listaTablas = $tablasQuery->fetchAll(PDO::FETCH_COLUMN);

// Tabla activa por defecto
$tablaActiva = $_GET['tabla'] ?? ($listaTablas[0] ?? 'clients');
if (!in_array($tablaActiva, $listaTablas)) {
    $tablaActiva = $listaTablas[0] ?? 'clients';
}

// 2. Obtener estructura de columnas de la tabla activa (PRAGMA table_info)
$colStmt = $pdo->query("PRAGMA table_info(\"{$tablaActiva}\")");
$columnas = $colStmt->fetchAll(PDO::FETCH_ASSOC);

// Identificar clave primaria y posibles columnas de fecha
$pkCol = 'id';
$columnaFecha = null;
$nombresColumnas = [];
foreach ($columnas as $col) {
    $colName = $col['name'];
    $nombresColumnas[] = $colName;
    if ($col['pk'] == 1) {
        $pkCol = $colName;
    }
    // Detectar columna de fecha representativa para filtros
    if (!$columnaFecha && preg_match('/(createdat|date|fecha|expensedate|saledate|visitdate|startdate)/i', $colName)) {
        $columnaFecha = $colName;
    }
}

// 3. Procesar Acciones CRUD del Administrador (Crear, Editar, Eliminar)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    try {
        if ($accion === 'editar_registro') {
            $pkVal = $_POST['__pk_val'] ?? null;
            if ($pkVal !== null) {
                $camposUpdate = [];
                $valores = [':pk' => $pkVal];
                
                foreach ($columnas as $col) {
                    $cName = $col['name'];
                    if ($cName === $pkCol) continue; // No modificar primary key
                    if (isset($_POST[$cName])) {
                        $camposUpdate[] = "\"{$cName}\" = :{$cName}";
                        $val = $_POST[$cName];
                        $valores[":{$cName}"] = ($val === '' && $col['notnull'] == 0) ? null : $val;
                    }
                }
                
                if (!empty($camposUpdate)) {
                    $sql = "UPDATE \"{$tablaActiva}\" SET " . implode(", ", $camposUpdate) . " WHERE \"{$pkCol}\" = :pk";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($valores);
                    $mensaje = "✅ Registro #{$pkVal} en tabla '{$tablaActiva}' actualizado con éxito.";
                }
            }
        } elseif ($accion === 'crear_registro') {
            $camposInsert = [];
            $placeholders = [];
            $valores = [];
            
            foreach ($columnas as $col) {
                $cName = $col['name'];
                if ($cName === $pkCol && strpos(strtoupper($col['type']), 'INT') !== false) {
                    // Autoincrementable omitir
                    continue;
                }
                if (isset($_POST[$cName]) && $_POST[$cName] !== '') {
                    $camposInsert[] = "\"{$cName}\"";
                    $placeholders[] = ":{$cName}";
                    $valores[":{$cName}"] = $_POST[$cName];
                }
            }
            
            if (!empty($camposInsert)) {
                $sql = "INSERT INTO \"{$tablaActiva}\" (" . implode(", ", $camposInsert) . ") VALUES (" . implode(", ", $placeholders) . ")";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($valores);
                $mensaje = "✅ Nuevo registro insertado en tabla '{$tablaActiva}'.";
            }
        } elseif ($accion === 'eliminar_registro') {
            $pkVal = $_POST['__pk_val'] ?? null;
            if ($pkVal !== null) {
                $stmt = $pdo->prepare("DELETE FROM \"{$tablaActiva}\" WHERE \"{$pkCol}\" = :pk");
                $stmt->execute([':pk' => $pkVal]);
                $mensaje = "Registro #{$pkVal} eliminado de tabla '{$tablaActiva}'.";
            }
        }
    } catch (Exception $e) {
        $mensaje = "Error en operación: " . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// 4. Parámetros de Filtro y Búsqueda
$busqueda = trim($_GET['q'] ?? '');
$inicio = $_GET['fecha_inicio'] ?? '';
$fin = $_GET['fecha_fin'] ?? '';

$whereParts = [];
$params = [];

if ($busqueda !== '') {
    $searchConditions = [];
    foreach ($columnas as $i => $col) {
        $paramKey = ":q_{$i}";
        $searchConditions[] = "CAST(\"{$col['name']}\" AS TEXT) LIKE {$paramKey}";
        $params[$paramKey] = "%{$busqueda}%";
    }
    if (!empty($searchConditions)) {
        $whereParts[] = "(" . implode(" OR ", $searchConditions) . ")";
    }
}

if ($columnaFecha && $inicio !== '') {
    $whereParts[] = "date(\"{$columnaFecha}\") >= :f_inicio";
    $params[':f_inicio'] = $inicio;
}
if ($columnaFecha && $fin !== '') {
    $whereParts[] = "date(\"{$columnaFecha}\") <= :f_fin";
    $params[':f_fin'] = $fin;
}

$whereSQL = !empty($whereParts) ? "WHERE " . implode(" AND ", $whereParts) : "";

// Contar registros totales
$totalTabla = (int)$pdo->query("SELECT COUNT(*) FROM \"{$tablaActiva}\"")->fetchColumn();

// Obtener filas filtradas (máximo 150 para rendimiento óptimo)
$sqlFilas = "SELECT * FROM \"{$tablaActiva}\" {$whereSQL} ORDER BY \"{$pkCol}\" DESC LIMIT 150";
$stmt = $pdo->prepare($sqlFilas);
$stmt->execute($params);
$filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
$conteoFiltrado = count($filas);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editor de Tablas SQLite - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/css/style.css">
    <style>
        .table-responsive { max-height: 70vh; }
        .cell-mono { font-family: monospace; font-size: 0.88rem; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/navbar.php'; ?>
    <div class="container-fluid"><div class="row">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <main class="col-md-10 ms-sm-auto px-md-4 main-content">
        <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h1><i class="fas fa-table-list me-2"></i>Gestor de Tablas de Base de Datos</h1>
                <p class="text-muted mb-0 small">Explora, consulta y edita directamente los registros de SQLite (Acceso Administrador)</p>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-primary" onclick="abrirModalNuevoRegistro()">
                    <i class="fas fa-plus me-1"></i> Nuevo Registro en <?php echo htmlspecialchars($tablaActiva); ?>
                </button>
            </div>
        </div>

        <?php if ($mensaje): ?>
            <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                <?php echo $mensaje; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Selector de Tabla y Filtros -->
        <div class="card p-3 mb-4 shadow-sm">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-bold small text-muted">Seleccionar Tabla:</label>
                    <select name="tabla" class="form-select fw-bold text-primary" onchange="this.form.submit()">
                        <?php foreach ($listaTablas as $t): ?>
                        <option value="<?php echo $t; ?>" <?php echo $t===$tablaActiva?'selected':''; ?>>
                            📁 <?php echo $t; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($columnaFecha): ?>
                <div class="col-md-2">
                    <label class="form-label fw-bold small text-muted">Desde (<?php echo $columnaFecha; ?>):</label>
                    <input type="date" name="fecha_inicio" class="form-control" value="<?php echo htmlspecialchars($inicio); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold small text-muted">Hasta:</label>
                    <input type="date" name="fecha_fin" class="form-control" value="<?php echo htmlspecialchars($fin); ?>">
                </div>
                <?php endif; ?>

                <div class="<?php echo $columnaFecha ? 'col-md-3' : 'col-md-7'; ?>">
                    <label class="form-label fw-bold small text-muted">Buscar en cualquier columna:</label>
                    <input type="text" name="q" class="form-control" placeholder="Texto o número..." value="<?php echo htmlspecialchars($busqueda); ?>">
                </div>

                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-fill"><i class="fas fa-search me-1"></i> Filtrar</button>
                    <?php if ($busqueda || $inicio || $fin): ?>
                    <a href="?tabla=<?php echo $tablaActiva; ?>" class="btn btn-outline-secondary" title="Limpiar filtros"><i class="fas fa-undo"></i></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Info de la Tabla -->
        <?php if ($tablaActiva === 'global_sales'): ?>
            <div class="alert alert-primary d-flex align-items-center mb-3 py-2 border-primary shadow-sm" role="alert">
                <i class="fas fa-network-wired fa-2x me-3 text-primary"></i>
                <div class="small">
                    <strong>TABLA MAESTRA VINCULADA (Ventas Globales):</strong> Esta tabla registra cada transacción con su <strong>Folio único autoincrementable (#0001, #0002...)</strong>, fecha/hora y cajero. Sus productos individuales se encuentran desglosados en las tablas hijas vinculadas: <code>saleitems</code> (productos internos) y <code>ventas_externas_realizadas</code> (productos externos).
                </div>
            </div>
        <?php elseif ($tablaActiva === 'ventas_externas_realizadas'): ?>
            <div class="alert alert-warning d-flex align-items-center mb-3 py-2 border-warning shadow-sm" role="alert">
                <i class="fas fa-tag fa-2x me-3 text-warning-emphasis"></i>
                <div class="small">
                    <strong>TABLA HIJA VINCULADA (Ventas Externas Realizadas):</strong> Contiene productos externos vendidos (cocas, scoops de proteína, suplementos externos). Cada fila está <strong>vinculada a la venta global mediante <code>globalSaleId</code></strong> con la fecha, hora y cajero que realizó la venta.
                </div>
            </div>
        <?php elseif ($tablaActiva === 'saleitems'): ?>
            <div class="alert alert-info d-flex align-items-center mb-3 py-2 border-info shadow-sm" role="alert">
                <i class="fas fa-link fa-2x me-3 text-info"></i>
                <div class="small">
                    <strong>TABLA HIJA VINCULADA (Productos Internos):</strong> Contiene el desglose de productos internos vendidos. Cada registro está <strong>vinculado a la Venta Global mediante <code>globalSaleId</code></strong>. Modificar o eliminar registros aquí alterará el comprobante de dicha venta.
                </div>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
                <span class="badge bg-dark fs-6 me-2">Tabla: <code><?php echo $tablaActiva; ?></code></span>
                <span class="badge bg-secondary"><?php echo $conteoFiltrado; ?> mostrados (de <?php echo $totalTabla; ?> totales)</span>
                <?php if ($columnaFecha): ?>
                    <span class="badge bg-info-subtle text-info border ms-1"><i class="far fa-calendar-alt me-1"></i>Fecha filtrable por: <?php echo $columnaFecha; ?></span>
                <?php endif; ?>
            </div>
            <small class="text-muted">Mostrando los registros más recientes primero</small>
        </div>

        <!-- Tabla de Datos -->
        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="text-center" style="width: 110px;">Acciones</th>
                            <?php foreach ($columnas as $col): ?>
                            <th>
                                <div><?php echo htmlspecialchars($col['name']); ?></div>
                                <span class="badge bg-light text-secondary border font-monospace" style="font-size: 0.65rem;">
                                    <?php echo $col['type'] ?: 'TEXT'; ?><?php echo $col['pk'] ? ' (PK)' : ''; ?>
                                </span>
                            </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($filas)): ?>
                        <tr>
                            <td colspan="<?php echo count($columnas) + 1; ?>" class="text-center py-5 text-muted">
                                <i class="fas fa-database fa-3x mb-3 text-secondary d-block"></i>
                                No se encontraron registros en la tabla <strong><?php echo $tablaActiva; ?></strong> con los filtros actuales.
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php foreach ($filas as $fila): ?>
                        <tr>
                            <td class="text-center text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-primary me-1" onclick='abrirEditarFila(<?php echo json_encode($fila); ?>)' title="Modificar este registro">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('¿Seguro que deseas eliminar permanentemente este registro (#<?php echo $fila[$pkCol]; ?>)?');">
                                    <input type="hidden" name="accion" value="eliminar_registro">
                                    <input type="hidden" name="__pk_val" value="<?php echo htmlspecialchars($fila[$pkCol]); ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar registro">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                            <?php foreach ($columnas as $col): 
                                $cName = $col['name'];
                                $val = $fila[$cName] ?? null;
                            ?>
                            <td class="cell-mono">
                                <?php 
                                if ($val === null) {
                                    echo '<span class="text-muted fst-italic">NULL</span>';
                                } elseif (strlen($val) > 80) {
                                    echo htmlspecialchars(substr($val, 0, 80)) . '...';
                                } else {
                                    echo htmlspecialchars($val);
                                }
                                ?>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main></div></div>

    <!-- Modal Editar Registro -->
    <div class="modal fade" id="modalEditarFila" tabindex="-1" aria-labelledby="modalEditarFilaLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title" id="modalEditarFilaLabel">
                        <i class="fas fa-edit me-1"></i> Modificar Registro en <code><?php echo $tablaActiva; ?></code>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body" id="camposEditarContainer">
                        <input type="hidden" name="accion" value="editar_registro">
                        <input type="hidden" name="__pk_val" id="edit_pk_val">
                        <!-- Campos inyectados vía JavaScript -->
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success"><i class="fas fa-check me-1"></i> Guardar Cambios</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Nuevo Registro -->
    <div class="modal fade" id="modalCrearFila" tabindex="-1" aria-labelledby="modalCrearFilaLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalCrearFilaLabel">
                        <i class="fas fa-plus me-1"></i> Nuevo Registro en <code><?php echo $tablaActiva; ?></code>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="crear_registro">
                        <div class="row g-3">
                            <?php foreach ($columnas as $col): 
                                $cName = $col['name'];
                                if ($cName === $pkCol && strpos(strtoupper($col['type']), 'INT') !== false) {
                                    continue; // Autoincrementable omitir
                                }
                            ?>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-muted">
                                    <?php echo htmlspecialchars($cName); ?>
                                    <span class="badge bg-light text-secondary font-monospace"><?php echo $col['type'] ?: 'TEXT'; ?></span>
                                    <?php if ($col['notnull']): ?><span class="text-danger">*</span><?php endif; ?>
                                </label>
                                <?php if (stripos($cName, 'date') !== false): ?>
                                    <input type="text" name="<?php echo htmlspecialchars($cName); ?>" class="form-control" value="<?php echo date('Y-m-d H:i:s'); ?>" <?php echo $col['notnull'] ? 'required' : ''; ?>>
                                <?php else: ?>
                                    <input type="text" name="<?php echo htmlspecialchars($cName); ?>" class="form-control" <?php echo $col['notnull'] ? 'required' : ''; ?>>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Insertar Registro</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Estructura de Columnas para JS -->
    <script>
    var COLUMNAS_INFO = <?php echo json_encode($columnas); ?>;
    var PK_COL = <?php echo json_encode($pkCol); ?>;
    var TABLA_ACTIVA = <?php echo json_encode($tablaActiva); ?>;
    </script>
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

    function abrirModalNuevoRegistro() {
        abrirModalElemento('modalCrearFila');
    }

    function abrirEditarFila(fila) {
        document.getElementById('edit_pk_val').value = fila[PK_COL];
        var container = document.getElementById('camposEditarContainer');
        
        // Mantener los inputs hidden
        var html = '<input type="hidden" name="accion" value="editar_registro">' +
                   '<input type="hidden" name="__pk_val" value="' + fila[PK_COL] + '">';

        if (fila.globalSaleId) {
            html += '<div class="alert alert-warning py-2 small mb-3 border-warning">' +
                    '<i class="fas fa-exclamation-triangle me-1"></i> <strong>AVISO DE TABLAS VINCULADAS:</strong> ' +
                    'Este registro pertenece a la transacción global <strong>Folio #' + String(fila.globalSaleId).padStart(4, '0') + '</strong> (ID: ' + fila.globalSaleId + '). ' +
                    'Cualquier cambio en cantidad, producto o precio modificará la información de la venta original.' +
                    '</div>';
        } else if (TABLA_ACTIVA === 'global_sales') {
            html += '<div class="alert alert-info py-2 small mb-3 border-primary">' +
                    '<i class="fas fa-network-wired me-1"></i> <strong>VENTA GLOBAL MAESTRA:</strong> ' +
                    'Esta es la transacción principal (Folio: ' + (fila.folio || fila.id) + '). Sus productos detallados están divididos en las tablas hijas <code>saleitems</code> y <code>ventas_externas_realizadas</code>.' +
                    '</div>';
        }
        
        html += '<div class="row g-3">';
        
        COLUMNAS_INFO.forEach(function(col) {
            var cName = col.name;
            var val = fila[cName] !== null ? fila[cName] : '';
            var isPk = (cName === PK_COL);
            
            html += '<div class="col-md-6">' +
                    '<label class="form-label fw-bold small text-muted">' + cName + 
                    ' <span class="badge bg-light text-secondary font-monospace">' + (col.type || 'TEXT') + '</span>' +
                    (isPk ? ' <span class="badge bg-warning text-dark">Clave Primaria</span>' : '') +
                    '</label>';
            
            if (isPk) {
                html += '<input type="text" class="form-control bg-light" value="' + val + '" readonly>';
            } else {
                html += '<input type="text" name="' + cName + '" class="form-control" value="' + String(val).replace(/"/g, '&quot;') + '">';
            }
            html += '</div>';
        });
        
        html += '</div>';
        container.innerHTML = html;
        abrirModalElemento('modalEditarFila');
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
    </script>
</body>
</html>
