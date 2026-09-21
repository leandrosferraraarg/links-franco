<?php
declare(strict_types=1);

session_start([
    'use_strict_mode' => 1,
    'cookie_httponly' => 1,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store');
$_SESSION['csrf'] ??= bin2hex(random_bytes(24));

function e(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function csrf(): void { echo '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }
function checkCsrf(): void {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) throw new RuntimeException('La sesión cambió. Recargá la página e intentá otra vez.');
}
function configuration(): ?array {
    $path = __DIR__ . '/data/config.php';
    return is_file($path) ? require $path : null;
}
function connectDatabase(array $config): PDO {
    $sqlite = $config['driver'] === 'sqlite';
    if (!extension_loaded($sqlite ? 'pdo_sqlite' : 'pdo_mysql')) throw new RuntimeException('Activá la extensión ' . ($sqlite ? 'pdo_sqlite' : 'pdo_mysql') . ' en el panel del hosting.');
    $dsn = $sqlite ? 'sqlite:' . __DIR__ . '/data/favorites.sqlite' : 'mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';dbname=' . $config['database'] . ';charset=utf8mb4';
    $db = new PDO($dsn, $sqlite ? null : $config['username'], $sqlite ? null : $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    if ($sqlite) $db->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    return $db;
}
function initializeSchema(PDO $db): void {
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $db->exec("CREATE TABLE IF NOT EXISTS people (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, color TEXT NOT NULL DEFAULT '#348478');
            CREATE TABLE IF NOT EXISTS favorites (id INTEGER PRIMARY KEY AUTOINCREMENT, person_id INTEGER NOT NULL REFERENCES people(id) ON DELETE CASCADE, title TEXT NOT NULL, video_id TEXT NOT NULL, image TEXT NOT NULL DEFAULT '', position INTEGER NOT NULL DEFAULT 0);
            CREATE TABLE IF NOT EXISTS settings (`key` TEXT PRIMARY KEY, value TEXT NOT NULL);
            CREATE INDEX IF NOT EXISTS favorites_person ON favorites(person_id);
            CREATE TABLE IF NOT EXISTS admins (id INTEGER PRIMARY KEY, username TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL);");
        $columns = $db->query('PRAGMA table_info(favorites)')->fetchAll();
        if (!in_array('link_url', array_column($columns, 'name'), true)) $db->exec("ALTER TABLE favorites ADD COLUMN link_url TEXT NOT NULL DEFAULT ''");
        $db->exec("INSERT OR IGNORE INTO settings (`key`, value) VALUES ('title', 'Mis videos')");
    } else {
        $db->exec("CREATE TABLE IF NOT EXISTS people (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, color VARCHAR(7) NOT NULL DEFAULT '#348478') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS favorites (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, person_id INT NOT NULL, title VARCHAR(160) NOT NULL, video_id VARCHAR(11) NOT NULL, image TEXT NOT NULL, position INT NOT NULL DEFAULT 0, link_url TEXT NOT NULL, INDEX favorites_person (person_id), FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec('CREATE TABLE IF NOT EXISTS settings (`key` VARCHAR(80) PRIMARY KEY, value TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $db->exec('CREATE TABLE IF NOT EXISTS admins (id INT PRIMARY KEY, username VARCHAR(80) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $db->exec("INSERT IGNORE INTO settings (`key`, value) VALUES ('title', 'Mis videos')");
    }
}
function isAdmin(array $config): bool {
    return ($_SESSION['admin_id'] ?? 0) === 1
        && hash_equals($config['installation_id'], (string) ($_SESSION['installation_id'] ?? ''))
        && ($_SESSION['admin_last_seen'] ?? 0) > time() - 43200;
}
function installedDatabase(string $installUrl = 'install/'): array {
    $config = configuration();
    if (!$config) { header('Location: ' . $installUrl); exit; }
    try { $db = connectDatabase($config); }
    catch (Throwable $exception) {
        http_response_code(503);
        exit('<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Mis videos</title><body><h1>No pudimos conectar con la base de datos</h1><p>Revisá la configuración del hosting y data/config.php.</p></body></html>');
    }
    if (isAdmin($config)) $_SESSION['admin_last_seen'] = time();
    return [$config, $db];
}
