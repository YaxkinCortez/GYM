<?php
require_once __DIR__ . '/../includes/config.php';
verificarAutenticacion();
$usuario = obtenerUsuarioActual();
$mensaje = '';
$tipo_mensaje = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    
    if ($accion === 'escanear_codigo') {
        $code = escapar($_POST['barcode']);
        $p = obtenerFila("SELECT id, name, quantity FROM products WHERE barcode = :b", [':b' => $code]);
        if ($p) {
            actualizar('products', ['quantity' => $p['quantity'] + 1], 'id = :id', [':id' => $p['id']]);
            $mensaje = '✅ +1 unidad agregada a ' . htmlspecialchars($p['name']) . ' (Stock actual: ' . ($p['quantity'] + 1) . ')';
        } else {
            $mensaje = '⚠️ Código de barras no encontrado en inventario.';
            $tipo_mensaje = 'warning';
        }
    } elseif ($accion === 'ajuste_rapido') {
        $id = (int)$_POST['id'];
        $delta = (int)$_POST['delta'];
        $p = obtenerFila("SELECT id, quantity FROM products WHERE id = :id", [':id' => $id]);
        if ($p) {
            $nuevaCant = max(0, $p['quantity'] + $delta);
            actualizar('products', ['quantity' => $nuevaCant], 'id = :id', [':id' => $id]);
            $mensaje = 'Stock actualizado.';
        }
    } elseif ($accion === 'crear') {
        $nombre = escapar($_POST['name'] ?? '');
        if (!empty($nombre)) {
            insertar('products', [
                'name'          => $nombre,
                'barcode'       => escapar($_POST['barcode'] ?? ''),
                'purchasePrice' => (float)($_POST['purchasePrice'] ?? 0),
                'salePrice'     => (float)($_POST['salePrice'] ?? 0),
                'quantity'      => (int)($_POST['quantity'] ?? 0),
                'minStock'      => (int)($_POST['minStock'] ?? 5),
                'isExternal'    => isset($_POST['isExternal']) ? 1 : 0
            ]);
            $mensaje = '✅ Producto "' . htmlspecialchars($nombre) . '" agregado correctamente.';
        }
    } elseif ($accion === 'editar') {
        $id = (int)$_POST['id'];
        $nombre = escapar($_POST['name'] ?? '');
        if ($id > 0 && !empty($nombre)) {
            actualizar('products', [
                'name'          => $nombre,
                'barcode'       => escapar($_POST['barcode'] ?? ''),
                'purchasePrice' => (float)($_POST['purchasePrice'] ?? 0),
                'salePrice'     => (float)($_POST['salePrice'] ?? 0),
                'quantity'      => (int)($_POST['quantity'] ?? 0),
                'minStock'      => (int)($_POST['minStock'] ?? 5),
                'isExternal'    => isset($_POST['isExternal']) ? 1 : 0
            ], 'id = :id', [':id' => $id]);
            $mensaje = '✅ Producto "' . htmlspecialchars($nombre) . '" modificado correctamente.';
        }
    } elseif ($accion === 'eliminar' && ($usuario['role'] === 'admin' || $usuario['role'] === 'administrador')) {
        eliminar('products', 'id = :id', [':id' => (int)$_POST['id']]);
        $mensaje = 'Producto eliminado de inventario.';
    }
}

$busqueda = trim($_GET['q'] ?? '');
$filtroTipo = $_GET['tipo'] ?? 'todos';

$whereParts = [];
$params = [];

if ($busqueda !== '') {
    $whereParts[] = "(name LIKE :q OR barcode LIKE :q)";
    $params[':q'] = "%$busqueda%";
}

if ($filtroTipo === 'internos') {
    $whereParts[] = "isExternal = 0";
} elseif ($filtroTipo === 'externos') {
    $whereParts[] = "isExternal = 1";
}

$whereSQL = !empty($whereParts) ? "WHERE " . implode(" AND ", $whereParts) : "";
$productos = obtenerTodas("SELECT * FROM products $whereSQL ORDER BY name ASC", $params);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventario - <?php echo APP_NAME; ?></title>
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
            <h1><i class="fas fa-boxes me-2"></i>Inventario de Productos</h1>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalProd" id="btnNuevoProd">
                    <i class="fas fa-plus me-1"></i> Nuevo Producto
                </button>
            </div>
        </div>

        <?php if ($mensaje): ?>
            <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                <?php echo $mensaje; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Escaneo Rápido de Código de Barras -->
        <div class="card mb-3 p-3 bg-success-subtle border-success">
            <form method="POST" class="d-flex gap-2 align-items-center">
                <input type="hidden" name="accion" value="escanear_codigo">
                <i class="fas fa-barcode fa-2x text-success ms-2"></i>
                <input type="text" name="barcode" class="form-control form-control-lg font-monospace" placeholder="📷 Escanear código de barras para +1 stock inmediato..." autofocus>
                <button type="submit" class="btn btn-success btn-lg px-4"><i class="fas fa-plus"></i> +1 Stock</button>
            </form>
        </div>

        <!-- Barra de Búsqueda y Filtros -->
        <div class="card mb-3">
            <div class="card-body p-3">
                <form method="GET" class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                    <div class="btn-group" role="group">
                        <a href="?tipo=todos<?php echo $busqueda ? '&q='.urlencode($busqueda) : ''; ?>" class="btn btn-sm <?php echo $filtroTipo==='todos'?'btn-primary':'btn-outline-primary'; ?>">
                            <i class="fas fa-boxes me-1"></i> Todos
                        </a>
                        <a href="?tipo=internos<?php echo $busqueda ? '&q='.urlencode($busqueda) : ''; ?>" class="btn btn-sm <?php echo $filtroTipo==='internos'?'btn-primary':'btn-outline-primary'; ?>">
                            <i class="fas fa-store me-1"></i> Solo Internos
                        </a>
                        <a href="?tipo=externos<?php echo $busqueda ? '&q='.urlencode($busqueda) : ''; ?>" class="btn btn-sm <?php echo $filtroTipo==='externos'?'btn-warning text-dark fw-bold':'btn-outline-warning text-dark'; ?>">
                            <i class="fas fa-tag me-1"></i> Solo Venta Externa
                        </a>
                    </div>
                    <div class="d-flex gap-2 flex-grow-1" style="max-width: 450px;">
                        <input type="hidden" name="tipo" value="<?php echo htmlspecialchars($filtroTipo); ?>">
                        <input type="text" name="q" class="form-control" placeholder="Buscar por nombre o código..." value="<?php echo htmlspecialchars($busqueda); ?>">
                        <button type="submit" class="btn btn-secondary"><i class="fas fa-search"></i></button>
                        <?php if ($busqueda || $filtroTipo !== 'todos'): ?>
                            <a href="/pages/productos.php" class="btn btn-outline-secondary">Limpiar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tabla de Productos -->
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Producto</th>
                            <th>Código</th>
                            <th>P. Compra</th>
                            <th>P. Venta</th>
                            <th class="text-center">Stock Actual</th>
                            <th class="text-center">Ajuste Rápido</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($productos)): ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">No se encontraron productos registrados. Haz clic en "Nuevo Producto" para agregar uno.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($productos as $p): 
                            $bajoStock = ($p['quantity'] <= $p['minStock']);
                        ?>
                        <tr class="<?php echo $bajoStock ? 'table-warning' : ''; ?>">
                            <td>
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <span class="fw-bold"><?php echo htmlspecialchars($p['name']); ?></span>
                                    <?php if (!empty($p['isExternal'])): ?>
                                        <span class="badge bg-warning text-dark"><i class="fas fa-tag me-1"></i>Venta Externa</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-secondary border">Interno</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($bajoStock): ?>
                                    <span class="badge bg-danger small mt-1"><i class="fas fa-exclamation-triangle"></i> Bajo stock (Mín: <?php echo $p['minStock']; ?>)</span>
                                <?php endif; ?>
                            </td>
                            <td class="font-monospace text-muted"><?php echo htmlspecialchars($p['barcode'] ?: '-'); ?></td>
                            <td>$<?php echo number_format($p['purchasePrice'], 2); ?></td>
                            <td class="fw-bold text-success">$<?php echo number_format($p['salePrice'], 2); ?></td>
                            <td class="text-center">
                                <span class="badge <?php echo $bajoStock ? 'bg-danger' : 'bg-primary'; ?> fs-6">
                                    <?php echo (int)$p['quantity']; ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm" role="group">
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="accion" value="ajuste_rapido">
                                        <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                        <input type="hidden" name="delta" value="-1">
                                        <button type="submit" class="btn btn-outline-secondary" title="-1 unidad" <?php echo $p['quantity'] <= 0 ? 'disabled' : ''; ?>>-1</button>
                                    </form>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="accion" value="ajuste_rapido">
                                        <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                        <input type="hidden" name="delta" value="1">
                                        <button type="submit" class="btn btn-outline-success" title="+1 unidad">+1</button>
                                    </form>
                                </div>
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary me-1" onclick='abrirEditarProducto(<?php echo json_encode($p); ?>)' title="Modificar producto">
                                    <i class="fas fa-edit"></i> Modificar
                                </button>
                                <?php if ($usuario['role'] === 'admin' || $usuario['role'] === 'administrador'): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('¿Seguro que deseas eliminar este producto?');">
                                    <input type="hidden" name="accion" value="eliminar">
                                    <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
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
    </main></div></div>

    <!-- Modal Crear Producto -->
    <div class="modal fade" id="modalProd" tabindex="-1" aria-labelledby="modalProdLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalProdLabel"><i class="fas fa-plus-circle me-1"></i> Nuevo Producto</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="crear">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Nombre del Producto <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" placeholder="Ej: Proteína Whey 1kg, Agua 500ml..." required autofocus>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Código de Barras</label>
                            <input type="text" name="barcode" class="form-control font-monospace" placeholder="Código escaneado o manual">
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-bold">Precio de Compra ($)</label>
                                <input type="number" step="0.01" min="0" name="purchasePrice" class="form-control" value="0.00" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-bold">Precio de Venta ($) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="0" name="salePrice" class="form-control" placeholder="0.00" required>
                            </div>
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label fw-bold">Cantidad Inicial</label>
                                <input type="number" min="0" name="quantity" class="form-control" value="1" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-bold">Stock Mínimo (Alerta)</label>
                                <input type="number" min="0" name="minStock" class="form-control" value="5" required>
                            </div>
                        </div>
                        <div class="form-check form-switch mt-3 p-2 bg-warning-subtle rounded border border-warning">
                            <input type="checkbox" name="isExternal" class="form-check-input ms-0 me-2" id="prod_isExternal" value="1">
                            <label class="form-check-label fw-bold text-dark" for="prod_isExternal">
                                <i class="fas fa-tag me-1 text-warning-emphasis"></i> Venta Externa (Cocas, scoop de proteína, snacks externos)
                            </label>
                            <div class="form-text small">Si se marca, al venderse se registrará en la tabla separada de ventas externas con comprobante dividido.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Guardar Producto</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Modificar / Editar Producto -->
    <div class="modal fade" id="modalEditarProd" tabindex="-1" aria-labelledby="modalEditarProdLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title" id="modalEditarProdLabel"><i class="fas fa-edit me-1"></i> Modificar Producto</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="editar">
                        <input type="hidden" name="id" id="edit_prod_id">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Nombre del Producto <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="edit_prod_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Código de Barras</label>
                            <input type="text" name="barcode" id="edit_prod_barcode" class="form-control font-monospace">
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-bold">Precio de Compra ($)</label>
                                <input type="number" step="0.01" min="0" name="purchasePrice" id="edit_prod_purchasePrice" class="form-control" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-bold">Precio de Venta ($) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="0" name="salePrice" id="edit_prod_salePrice" class="form-control" required>
                            </div>
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label fw-bold">Stock Actual</label>
                                <input type="number" min="0" name="quantity" id="edit_prod_quantity" class="form-control" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-bold">Stock Mínimo (Alerta)</label>
                                <input type="number" min="0" name="minStock" id="edit_prod_minStock" class="form-control" required>
                            </div>
                        </div>
                        <div class="form-check form-switch mt-3 p-2 bg-warning-subtle rounded border border-warning">
                            <input type="checkbox" name="isExternal" class="form-check-input ms-0 me-2" id="edit_prod_isExternal" value="1">
                            <label class="form-check-label fw-bold text-dark" for="edit_prod_isExternal">
                                <i class="fas fa-tag me-1 text-warning-emphasis"></i> Venta Externa (Separar en comprobante y tabla externa)
                            </label>
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

    function abrirEditarProducto(prod) {
        document.getElementById('edit_prod_id').value = prod.id;
        document.getElementById('edit_prod_name').value = prod.name;
        document.getElementById('edit_prod_barcode').value = prod.barcode || '';
        document.getElementById('edit_prod_purchasePrice').value = prod.purchasePrice;
        document.getElementById('edit_prod_salePrice').value = prod.salePrice;
        document.getElementById('edit_prod_quantity').value = prod.quantity;
        document.getElementById('edit_prod_minStock').value = prod.minStock;
        document.getElementById('edit_prod_isExternal').checked = (prod.isExternal == 1);
        
        abrirModalElemento('modalEditarProd');
    }

    // Soporte para botón "Nuevo Producto" con fallback garantizado
    document.addEventListener('DOMContentLoaded', function() {
        var btnNuevo = document.getElementById('btnNuevoProd');
        if (btnNuevo) {
            btnNuevo.addEventListener('click', function(e) {
                e.preventDefault();
                abrirModalElemento('modalProd');
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
