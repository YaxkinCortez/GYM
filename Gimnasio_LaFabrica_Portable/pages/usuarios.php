<?php
require_once __DIR__ . '/../includes/config.php';
verificarAutenticacion();
$usuario = obtenerUsuarioActual();

if ($usuario['role'] !== 'admin') {
    header("Location: acceso_restringido.php");
    exit();
}

$mensaje = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'crear') {
        insertar('users', [
            'name'     => escapar($_POST['name']),
            'email'    => escapar($_POST['email']),
            'password' => escapar($_POST['password']),
            'role'     => escapar($_POST['role'])
        ]);
        $mensaje = 'Usuario creado.';
    } elseif ($accion === 'eliminar') {
        $id = (int)$_POST['id'];
        if ($id !== (int)$usuario['id']) {
            eliminar('users', 'id = :id', [':id' => $id]);
            $mensaje = 'Usuario eliminado.';
        }
    }
}
$usuarios = obtenerTodas("SELECT * FROM users ORDER BY name ASC");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Usuarios - <?php echo APP_NAME; ?></title>
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
            <h1><i class="fas fa-user-shield me-2"></i>Usuarios</h1>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalUser"><i class="fas fa-plus"></i> Nuevo</button>
        </div>
        <?php if ($mensaje): ?><div class="alert alert-success"><?php echo $mensaje; ?></div><?php endif; ?>
        <div class="card"><table class="table table-hover mb-0">
            <thead class="table-light"><tr><th>Nombre</th><th>Email</th><th>Rol</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($usuarios as $u): ?>
                <tr>
                    <td><?php echo htmlspecialchars($u['name']); ?></td>
                    <td><?php echo htmlspecialchars($u['email']); ?></td>
                    <td><span class="badge <?php echo $u['role']==='admin'?'bg-danger':'bg-info'; ?>"><?php echo $u['role']==='admin'?'Administrador':'Cajero / Normal'; ?></span></td>
                    <td class="text-end">
                        <?php if ($u['id'] != $usuario['id']): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar usuario?');">
                            <input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </main></div></div>

    <div class="modal fade" id="modalUser" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content">
            <div class="modal-header bg-primary text-white"><h5 class="modal-title">Nuevo Usuario</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <form method="POST"><div class="modal-body">
                <input type="hidden" name="accion" value="crear">
                <div class="mb-2"><label>Nombre</label><input type="text" name="name" class="form-control" required></div>
                <div class="mb-2"><label>Email</label><input type="email" name="email" class="form-control" required></div>
                <div class="mb-2"><label>Contraseña</label><input type="password" name="password" class="form-control" required></div>
                <div class="mb-2"><label>Rol</label>
                    <select name="role" class="form-select">
                        <option value="user">👤 Cajero / Recepción (Normal)</option>
                        <option value="admin">👑 Administrador (Total)</option>
                    </select>
                </div>
            </div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary">Guardar</button></div></form>
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