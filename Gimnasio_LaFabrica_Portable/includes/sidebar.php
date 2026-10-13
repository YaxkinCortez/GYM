<?php
$pagina_activa = $pagina_activa ?? basename($_SERVER['PHP_SELF']);
$rol = $usuario['role'] ?? 'user';

$menu = [
    'dashboard.php'            => ['icon' => 'fas fa-chart-line',    'label' => 'Dashboard', 'admin' => false],
    'visitas.php'              => ['icon' => 'fas fa-door-open',     'label' => 'Visitas', 'admin' => false],
    'clientes.php'             => ['icon' => 'fas fa-users',         'label' => 'Clientes', 'admin' => false],
    'membresias.php'           => ['icon' => 'fas fa-id-card',       'label' => 'Membresías', 'admin' => false],
    'historial_membresias.php' => ['icon' => 'fas fa-history',       'label' => 'Historial Membresías', 'admin' => false],
    'productos.php'            => ['icon' => 'fas fa-box',           'label' => 'Inventario', 'admin' => false],
    'ventas.php'               => ['icon' => 'fas fa-shopping-cart', 'label' => 'Ventas (POS)', 'admin' => false],
    'historial_ventas.php'     => ['icon' => 'fas fa-receipt',       'label' => 'Historial Ventas', 'admin' => false],
    'abonos.php'               => ['icon' => 'fas fa-hand-holding-dollar', 'label' => 'Abonos y Deudas', 'admin' => false],
    'gastos.php'               => ['icon' => 'fas fa-wallet',        'label' => 'Gastos', 'admin' => false],
    'usuarios.php'             => ['icon' => 'fas fa-user-shield',   'label' => 'Usuarios', 'admin' => true],
    'reportes.php'             => ['icon' => 'fas fa-file-alt',      'label' => 'Reportes', 'admin' => true],
    'balance.php'              => ['icon' => 'fas fa-scale-balanced', 'label' => 'Balance', 'admin' => true],
    'respaldos.php'            => ['icon' => 'fas fa-database',      'label' => 'Respaldos', 'admin' => true],
    'tablas.php'               => ['icon' => 'fas fa-table-list',    'label' => 'Editor de Tablas', 'admin' => true]
];
?>
<!-- Desktop Sidebar -->
<nav class="col-md-2 d-none d-md-block sidebar">
    <div class="position-sticky pt-3">
        <ul class="nav flex-column">
            <?php foreach ($menu as $archivo => $item): 
                $clase = ($pagina_activa === $archivo) ? 'active' : '';
                $bloqueado = ($item['admin'] && $rol !== 'admin');
            ?>
            <li class="nav-item">
                <a class="nav-link <?php echo $clase; ?>" href="/pages/<?php echo $archivo; ?>">
                    <i class="<?php echo $item['icon']; ?>"></i> <?php echo $item['label']; ?>
                    <?php if ($bloqueado): ?><span class="badge bg-warning text-dark float-end small">🔒</span><?php endif; ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</nav>

<!-- Mobile Offcanvas Sidebar -->
<div class="offcanvas offcanvas-start bg-white d-md-none" tabindex="-1" id="sidebarMobile">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title fw-bold text-primary"><i class="fas fa-dumbbell me-2"></i> Menú</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body p-0">
        <ul class="nav flex-column sidebar w-100">
            <?php foreach ($menu as $archivo => $item): 
                $clase = ($pagina_activa === $archivo) ? 'active' : '';
            ?>
            <li class="nav-item">
                <a class="nav-link <?php echo $clase; ?>" href="/pages/<?php echo $archivo; ?>">
                    <i class="<?php echo $item['icon']; ?>"></i> <?php echo $item['label']; ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>