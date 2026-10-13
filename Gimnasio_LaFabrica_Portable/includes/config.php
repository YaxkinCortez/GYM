<?php
/**
 * CONFIGURACIÓN EMBEBIDA (SQLite3 + PDO) - GIMNASIO LA FÁBRICA
 * 100% Autónomo sin MySQL ni XAMPP
 */
define('DB_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database');
define('DB_FILE', DB_DIR . DIRECTORY_SEPARATOR . 'gimnasio.sqlite');
define('APP_NAME', 'Gimnasio La Fabrica');
define('APP_URL', 'http://127.0.0.1:8080/');

date_default_timezone_set('America/Mexico_City');

if (!is_dir(DB_DIR)) {
    mkdir(DB_DIR, 0777, true);
}

// Validación de extensión SQLite en PHP
if (!extension_loaded('pdo_sqlite')) {
    http_response_code(500);
    die("<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'><title>Error: SQLite no activado en PHP</title>
    <style>body{font-family:system-ui,sans-serif;background:#0f172a;color:#f8fafc;padding:40px;display:flex;justify-content:center;}
    .box{background:#1e293b;border:1px solid #dc2626;border-radius:16px;padding:30px;max-width:550px;}
    h2{color:#f87171;margin-top:0;} code{background:#020617;padding:2px 6px;border-radius:6px;color:#38bdf8;font-family:monospace;}
    </style></head><body><div class='box'>
    <h2>⚠️ Extensión 'pdo_sqlite' no activa en PHP</h2>
    <p>El servidor PHP está corriendo, pero tu instalación de PHP no tiene cargado el módulo <code>pdo_sqlite</code>.</p>
    <p><b>Solución rápida:</b></p>
    <ol><li>Inicia siempre usando <code>iniciar_gimnasio.bat</code> (este lanzador activa SQLite automáticamente).</li>
    <li>O en tu archivo <code>php.ini</code>, busca la línea <code>;extension=pdo_sqlite</code> y quítale el punto y coma (<code>;</code>).</li>
    <li>O ejecuta <code>descargar_php_portable.bat</code> para tener PHP portable preconfigurado.</li></ol>
    </div></body></html>");
}

try {
    $dbExists = file_exists(DB_FILE);
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec('PRAGMA journal_mode = WAL;');

    // Inicializar tablas si la base de datos es nueva o no tiene tablas
    $tablasCount = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchColumn();
    if ($tablasCount === 0) {
        $schemaFile = DB_DIR . DIRECTORY_SEPARATOR . 'schema.sqlite.sql';
        if (file_exists($schemaFile)) {
            $pdo->exec(file_get_contents($schemaFile));
        }
    }

    // Migración automática y tablas para ventas globales, externas e internas
    try {
        $colProducts = $pdo->query("PRAGMA table_info(products)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('isExternal', $colProducts)) {
            $pdo->exec("ALTER TABLE products ADD COLUMN isExternal INTEGER NOT NULL DEFAULT 0;");
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS global_sales (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                folio TEXT UNIQUE NOT NULL,
                userId INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                clientId INTEGER REFERENCES clients(id) ON DELETE SET NULL,
                totalAmount REAL NOT NULL,
                totalInternal REAL NOT NULL DEFAULT 0.00,
                totalExternal REAL NOT NULL DEFAULT 0.00,
                paymentMethod TEXT NOT NULL DEFAULT 'efectivo',
                notes TEXT,
                createdAt DATETIME DEFAULT (datetime('now', 'localtime'))
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS ventas_externas_realizadas (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                globalSaleId INTEGER NOT NULL REFERENCES global_sales(id) ON DELETE CASCADE,
                productId INTEGER REFERENCES products(id) ON DELETE SET NULL,
                productName TEXT NOT NULL,
                quantity INTEGER NOT NULL,
                unitPrice REAL NOT NULL,
                subtotal REAL NOT NULL,
                userId INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                createdAt DATETIME DEFAULT (datetime('now', 'localtime'))
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS client_debts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                clientId INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
                globalSaleId INTEGER REFERENCES global_sales(id) ON DELETE SET NULL,
                description TEXT NOT NULL,
                totalAmount REAL NOT NULL,
                paidAmount REAL NOT NULL DEFAULT 0.00,
                remainingAmount REAL NOT NULL,
                status TEXT NOT NULL DEFAULT 'pendiente',
                userId INTEGER REFERENCES users(id) ON DELETE SET NULL,
                createdAt DATETIME DEFAULT (datetime('now', 'localtime')),
                updatedAt DATETIME DEFAULT (datetime('now', 'localtime'))
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS debt_payments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                debtId INTEGER NOT NULL REFERENCES client_debts(id) ON DELETE CASCADE,
                clientId INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
                amount REAL NOT NULL,
                paymentMethod TEXT NOT NULL DEFAULT 'efectivo',
                notes TEXT,
                userId INTEGER REFERENCES users(id) ON DELETE SET NULL,
                createdAt DATETIME DEFAULT (datetime('now', 'localtime'))
            );
        ");

        $colSales = $pdo->query("PRAGMA table_info(sales)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('globalSaleId', $colSales)) {
            $pdo->exec("ALTER TABLE sales ADD COLUMN globalSaleId INTEGER REFERENCES global_sales(id) ON DELETE CASCADE;");
        }
        $colItems = $pdo->query("PRAGMA table_info(saleitems)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('globalSaleId', $colItems)) {
            $pdo->exec("ALTER TABLE saleitems ADD COLUMN globalSaleId INTEGER REFERENCES global_sales(id) ON DELETE CASCADE;");
        }
        if (!in_array('productName', $colItems)) {
            $pdo->exec("ALTER TABLE saleitems ADD COLUMN productName TEXT;");
        }
        if (!in_array('userId', $colItems)) {
            $pdo->exec("ALTER TABLE saleitems ADD COLUMN userId INTEGER;");
        }

        // Migración automática para permitir ventas a crédito en tabla 'sales'
        $salesSqlRow = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='sales'")->fetch(PDO::FETCH_ASSOC);
        if (!empty($salesSqlRow['sql']) && strpos($salesSqlRow['sql'], "'credito'") === false && strpos($salesSqlRow['sql'], "CHECK") !== false) {
            $pdo->exec("PRAGMA foreign_keys = OFF;");
            $pdo->beginTransaction();
            $pdo->exec("ALTER TABLE sales RENAME TO sales_old_cred;");
            $pdo->exec("
                CREATE TABLE sales (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    globalSaleId INTEGER REFERENCES global_sales(id) ON DELETE CASCADE,
                    clientId INTEGER REFERENCES clients(id) ON DELETE SET NULL,
                    userId INTEGER REFERENCES users(id) ON DELETE CASCADE,
                    totalAmount REAL NOT NULL,
                    discountAmount REAL DEFAULT 0.00,
                    finalAmount REAL NOT NULL,
                    paymentMethod TEXT NOT NULL DEFAULT 'efectivo',
                    notes TEXT,
                    createdAt DATETIME DEFAULT (datetime('now', 'localtime'))
                );
            ");
            $oldCols = $pdo->query("PRAGMA table_info(sales_old_cred)")->fetchAll(PDO::FETCH_COLUMN, 1);
            $gsCol = in_array('globalSaleId', $oldCols) ? 'globalSaleId' : 'NULL';
            $discCol = in_array('discountAmount', $oldCols) ? 'discountAmount' : '0.00';
            $notesCol = in_array('notes', $oldCols) ? 'notes' : 'NULL';

            $pdo->exec("
                INSERT INTO sales (id, globalSaleId, clientId, userId, totalAmount, discountAmount, finalAmount, paymentMethod, notes, createdAt)
                SELECT id, $gsCol, clientId, userId, totalAmount, $discCol, finalAmount, paymentMethod, $notesCol, createdAt FROM sales_old_cred;
            ");
            $pdo->exec("DROP TABLE sales_old_cred;");
            $pdo->commit();
            $pdo->exec("PRAGMA foreign_keys = ON;");
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // Migración silenciosa
    }
} catch (PDOException $e) {
    die("Error al conectar con SQLite: " . $e->getMessage());
}

function ejecutarConsulta($sql, $params = []) {
    global $pdo;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function obtenerFila($sql, $params = []) {
    $stmt = ejecutarConsulta($sql, $params);
    $res = $stmt->fetch();
    return $res ?: null;
}

function obtenerTodas($sql, $params = []) {
    $stmt = ejecutarConsulta($sql, $params);
    return $stmt->fetchAll();
}

function insertar($tabla, $datos) {
    global $pdo;
    $columnas = implode(", ", array_keys($datos));
    $placeholders = ":" . implode(", :", array_keys($datos));
    $sql = "INSERT INTO $tabla ($columnas) VALUES ($placeholders)";
    $stmt = $pdo->prepare($sql);
    foreach ($datos as $col => $val) {
        $stmt->bindValue(":$col", ($val === '' ? null : $val));
    }
    $stmt->execute();
    return (int)$pdo->lastInsertId();
}

function actualizar($tabla, $datos, $condicion, $paramsCondicion = []) {
    global $pdo;
    $setParts = [];
    $params = [];
    foreach ($datos as $col => $val) {
        $setParts[] = "$col = :set_$col";
        $params[":set_$col"] = ($val === '' ? null : $val);
    }
    $sql = "UPDATE $tabla SET " . implode(", ", $setParts) . " WHERE $condicion";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, $paramsCondicion));
    return true;
}

function eliminar($tabla, $condicion, $params = []) {
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM $tabla WHERE $condicion");
    return $stmt->execute($params);
}

function escapar($datos) {
    return trim($datos ?? '');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function verificarAutenticacion() {
    if (!isset($_SESSION['usuario_id'])) {
        header("Location: " . APP_URL . "login.php");
        exit();
    }
}

function cerrarSesion() {
    session_destroy();
    header("Location: " . APP_URL . "login.php");
    exit();
}

function obtenerUsuarioActual() {
    if (isset($_SESSION['usuario_id'])) {
        return obtenerFila("SELECT * FROM users WHERE id = :id", [':id' => (int)$_SESSION['usuario_id']]);
    }
    return null;
}
?>