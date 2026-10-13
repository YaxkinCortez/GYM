<?php
/**
* NAVBAR CON SOPORTE MÓVIL - GIMNASIO LA FABRICA
*/
?>
<nav class="navbar navbar-expand-lg navbar-dark shadow-sm">
    <div class="container-fluid">
        <button class="navbar-toggler d-md-none me-2 border-0" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarMobile">
            <span class="navbar-toggler-icon"></span>
        </button>
        <a class="navbar-brand fw-bold" href="/pages/dashboard.php">
            <i class="fas fa-dumbbell me-2"></i> <?php echo APP_NAME; ?>
        </a>
        <div class="ms-auto d-flex align-items-center gap-3">
            <span class="text-white small d-none d-sm-inline">
                <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($usuario['name'] ?? 'Usuario'); ?> 
                <span class="badge bg-light text-dark ms-1"><?php echo ($usuario['role'] === 'admin') ? 'Admin' : 'Cajero'; ?></span>
            </span>
            <a class="btn btn-sm btn-outline-light rounded-pill px-3" href="/logout.php">
                <i class="fas fa-sign-out-alt"></i> Salir
            </a>
        </div>
    </div>
</nav>