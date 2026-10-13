<?php
/**
 * Router para el servidor PHP embebido (CLI Web Server)
 * Gimnasio La Fabrica - SQLite Desktop
 */

// Asegurar que la raíz del proyecto y la carpeta includes estén en include_path
set_include_path(get_include_path() . PATH_SEPARATOR . __DIR__ . PATH_SEPARATOR . __DIR__ . '/includes');

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = urldecode($uri);
$file = __DIR__ . $uri;

// 1. Servir directamente archivos estáticos existentes (CSS, JS, imágenes, fuentes)
if (preg_match('/\.(?:css|js|png|jpg|jpeg|gif|svg|ico|woff|woff2|ttf|txt|sql)$/i', $uri)) {
    if (file_exists($file) && is_file($file)) {
        return false;
    }
}

// 2. Ruta raíz o index -> Login
if ($uri === '/' || $uri === '/index.php' || $uri === '') {
    chdir(__DIR__);
    require __DIR__ . '/login.php';
    exit;
}

// 3. Archivo PHP directo (ej: /login.php, /pages/dashboard.php)
if (file_exists($file) && is_file($file) && substr($file, -4) === '.php') {
    chdir(dirname($file));
    require $file;
    exit;
}

// 4. URL limpia sin extensión (ej: /pages/dashboard -> /pages/dashboard.php)
if (file_exists($file . '.php') && is_file($file . '.php')) {
    chdir(dirname($file . '.php'));
    require $file . '.php';
    exit;
}

// 5. Index dentro de subdirectorios
if (is_dir($file) && file_exists($file . '/index.php')) {
    chdir($file);
    require $file . '/index.php';
    exit;
}

// Si no existe, devolver 404 limpio
http_response_code(404);
echo "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'><title>404 - No Encontrado</title>";
echo "<style>body{font-family:sans-serif;padding:40px;background:#0f172a;color:#f8fafc;text-align:center;}</style></head>";
echo "<body><h1>🏋️ Gimnasio La Fábrica</h1><p>Página no encontrada: <code>" . htmlspecialchars($uri) . "</code></p>";
echo "<a href='/login.php' style='color:#818cf8;font-weight:bold;'>Volver al Login</a></body></html>";
exit;
