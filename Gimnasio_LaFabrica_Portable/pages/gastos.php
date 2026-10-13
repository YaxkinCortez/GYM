<?php
require_once __DIR__ . '/../includes/config.php';
verificarAutenticacion();
$usuario = obtenerUsuarioActual();
$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'crear') {
        insertar('expenses', [
            'description' => escapar($_POST['description']),
            'cost'        => (float)$_POST['cost'],
            'expenseDate' => escapar($_POST['expenseDate']),
            'userId'      => $usuario['id']
        ]);
        $mensaje = 'Gasto registrado.';
    } elseif ($accion === 'eliminar' && $usuario['role'] === 'admin') {
        eliminar('expenses', 'id = :id', [':id' => (int)$_POST['id']]);
        $mensaje = 'Gasto eliminado.';
    }
}
$gastos = obtenerTodas("SELECT e.*, u.name as usuario_nombre FROM expenses e LEFT JOIN users u ON e.userId = u.id ORDER BY e.expenseDate DESC");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gastos - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/navbar.php'; ?>
    <div class="container-fluid"><div class="row">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <main class="col-md-10 ms-sm-auto px-md-4 main-content">
        <div class="page-header">
            <h1><i class="fas fa-wallet me-2"></i>Gastos</h1>
            <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#modalGasto"><i class="fas fa-plus"></i> Nuevo Gasto</button>
        </div>
        <?php if ($mensaje): ?><div class="alert alert-success"><?php echo $mensaje; ?></div><?php endif; ?>
        <div class="card"><table class="table table-hover mb-0">
            <thead class="table-light"><tr><th>Concepto</th><th>Costo</th><th>Fecha</th><th>Registró</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($gastos as $g): ?>
                <tr>
                    <td><?php echo htmlspecialchars($g['description']); ?></td>
                    <td class="text-danger fw-bold">-$<?php echo number_format($g['cost'], 2); ?></td>
                    <td><?php echo $g['expenseDate']; ?></td>
                    <td><?php echo htmlspecialchars($g['usuario_nombre']); ?></td>
                    <td class="text-end">
                        <?php if ($usuario['role'] === 'admin'): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar gasto?');">
                            <input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?php echo $g['id']; ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </main></div></div>

    <div class="modal fade" id="modalGasto" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content">
            <div class="modal-header bg-danger text-white"><h5 class="modal-title">Registrar Gasto</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <form method="POST"><div class="modal-body">
                <input type="hidden" name="accion" value="crear">
                <div class="mb-2"><label>Descripción</label><input type="text" name="description" class="form-control" required></div>
                <div class="mb-2"><label>Monto ($)</label><input type="number" step="0.01" name="cost" class="form-control" required></div>
                <div class="mb-2"><label>Fecha</label><input type="date" name="expenseDate" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
            </div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-danger">Guardar</button></div></form>
        </div></div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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
                    document.querySelectorAll('.modal-backdrop').forEach(function(b) { b.remove(); });
                }
            });
        });
    });
    </script>
</body>
</html>