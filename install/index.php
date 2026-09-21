<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app.php';
if (configuration()) {
    http_response_code(403);
    exit('<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Instalación completa</title><body><h1>La instalación ya está completa</h1><p>El instalador está bloqueado.</p><a href="../login.php">Iniciar sesión</a></body></html>');
}
$error = '';
$root = dirname(__DIR__);
$legacy = is_file($root . '/data/favorites.sqlite');
$driver = (string) ($_POST['driver'] ?? ($legacy ? 'sqlite' : 'mysql'));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lock = null; $temporary = null; $saved = false;
    try {
        checkCsrf();
        if (!in_array($driver, ['mysql', 'sqlite'], true)) throw new RuntimeException('Elegí MySQL o SQLite.');
        $adminName = trim((string) ($_POST['admin_username'] ?? ''));
        $adminPassword = (string) ($_POST['admin_password'] ?? '');
        if ($adminName === '' || strlen($adminName) > 80) throw new RuntimeException('Ingresá un usuario de hasta 80 caracteres.');
        if (strlen($adminPassword) < 8 || strlen($adminPassword) > 72) throw new RuntimeException('Usá una contraseña de entre 8 y 72 bytes (letras y números comunes ocupan un byte).');
        if ($adminPassword !== (string) ($_POST['admin_confirm'] ?? '')) throw new RuntimeException('Las contraseñas no coinciden.');
        foreach (['data', 'uploads'] as $folder) {
            if (!is_dir($root . '/' . $folder) && !mkdir($root . '/' . $folder, 0755, true) && !is_dir($root . '/' . $folder)) throw new RuntimeException('PHP necesita permiso de escritura en data y uploads.');
            if (!is_writable($root . '/' . $folder)) throw new RuntimeException('PHP necesita permiso de escritura en data y uploads.');
        }
        $lock = fopen($root . '/data/install.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('No se pudo bloquear la instalación. Intentá otra vez.');
        if (configuration()) throw new RuntimeException('La instalación ya se completó. Entrá desde el botón Editar.');
        $config = ['driver' => $driver, 'installation_id' => bin2hex(random_bytes(24))];
        if ($driver === 'mysql') {
            $host = trim((string) ($_POST['db_host'] ?? 'localhost'));
            $database = trim((string) ($_POST['db_name'] ?? ''));
            $port = filter_var($_POST['db_port'] ?? 3306, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
            $username = trim((string) ($_POST['db_user'] ?? ''));
            if (!preg_match('/^[a-zA-Z0-9_.:\[\]-]+$/D', $host) || !preg_match('/^[a-zA-Z0-9_$-]+$/D', $database) || !$port || $username === '') throw new RuntimeException('Completá el servidor, puerto, nombre y usuario de la base de datos.');
            $config += ['host' => $host, 'port' => $port, 'database' => $database, 'username' => $username, 'password' => (string) ($_POST['db_password'] ?? '')];
        }
        $db = connectDatabase($config);
        initializeSchema($db);
        $db->beginTransaction();
        if ((int) $db->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0) throw new RuntimeException('Esta base ya tiene un administrador. Usá la configuración original o una base nueva.');
        if ($driver === 'mysql' && $legacy) {
            if ((int) $db->query('SELECT COUNT(*) FROM people')->fetchColumn() || (int) $db->query('SELECT COUNT(*) FROM favorites')->fetchColumn()) throw new RuntimeException('Para importar tus favoritos anteriores, elegí una base MySQL vacía.');
            $source = connectDatabase(['driver' => 'sqlite']);
            foreach ($source->query('SELECT * FROM people') as $row) $db->prepare('INSERT INTO people(id,name,color) VALUES (?,?,?)')->execute([$row['id'], $row['name'], $row['color']]);
            foreach ($source->query('SELECT * FROM favorites') as $row) $db->prepare('INSERT INTO favorites(id,person_id,title,video_id,image,position,link_url) VALUES (?,?,?,?,?,?,?)')->execute([$row['id'], $row['person_id'], $row['title'], $row['video_id'], $row['image'], $row['position'], $row['link_url'] ?? '']);
            $oldTitle = $source->query("SELECT value FROM settings WHERE `key`='title'")->fetchColumn();
            if ($oldTitle !== false) $db->prepare("UPDATE settings SET value=? WHERE `key`='title'")->execute([$oldTitle]);
        }
        $db->prepare('INSERT INTO admins(id,username,password_hash) VALUES (1,?,?)')->execute([$adminName, password_hash($adminPassword, PASSWORD_DEFAULT)]);
        $temporary = tempnam($root . '/data', 'setup-');
        if (!$temporary) throw new RuntimeException('No se pudo crear el archivo de configuración.');
        $contents = "<?php\n// Configuración privada generada por /install.\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) throw new RuntimeException('No se pudo guardar la configuración.');
        @chmod($temporary, 0600);
        if (!rename($temporary, $root . '/data/config.php')) throw new RuntimeException('No se pudo guardar data/config.php.');
        $saved = true;
        $db->commit();
        session_regenerate_id(true);
        $_SESSION = ['csrf' => bin2hex(random_bytes(24)), 'admin_id' => 1, 'installation_id' => $config['installation_id'], 'admin_last_seen' => time(), 'notice' => '¡Instalación completa! Ya podés editar tus favoritos.'];
        header('Location: ../?edit=1', true, 303);
    } catch (Throwable $exception) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        if ($saved) unlink($root . '/data/config.php');
        if ($temporary && is_file($temporary)) unlink($temporary);
        $error = $exception instanceof PDOException ? 'No se pudo configurar la base. Revisá las credenciales, que la base exista y que el usuario tenga permisos para crear y modificar tablas.' : $exception->getMessage();
    } finally {
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    }
    if (!$error) exit;
}
?>
<!doctype html><html lang="es-AR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Instalar · Mis videos</title><link rel="icon" type="image/svg+xml" href="../assets/favicon.svg"><link rel="stylesheet" href="../assets/style.css?v=4"><link rel="stylesheet" href="../assets/mobile.css?v=4"></head>
<body><header class="topbar"><span class="brand"><span class="brand-icon" aria-hidden="true">▶</span> Mis videos</span></header>
<main class="setup"><h1>Primero, un pequeño ajuste</h1><p>Configurá dónde guardar tus favoritos y quién puede editarlos.</p>
<?php if ($error): ?><p class="notice error" role="alert"><?= e($error) ?></p><?php endif ?>
<?php if ($legacy): ?><p class="notice">Encontramos tus favoritos anteriores. Con SQLite se conservan; si elegís MySQL, se copian a la nueva base junto con las personas.</p><?php endif ?>
<form method="post" class="setup-form"><?php csrf() ?>
<label>Base de datos<select name="driver"><option value="mysql" <?= $driver === 'mysql' ? 'selected' : '' ?>>MySQL del hosting</option><option value="sqlite" <?= $driver === 'sqlite' ? 'selected' : '' ?>>SQLite · sin credenciales</option></select></label>
<fieldset><legend>Conexión MySQL</legend><p class="hint">Solo si elegiste MySQL. Creá primero una base vacía y su usuario en el panel del hosting.</p>
<label>Servidor<input name="db_host" value="<?= e($_POST['db_host'] ?? 'localhost') ?>" autocomplete="off"></label>
<label>Puerto<input name="db_port" type="number" min="1" max="65535" value="<?= e($_POST['db_port'] ?? 3306) ?>"></label>
<label>Nombre de la base<input name="db_name" value="<?= e($_POST['db_name'] ?? '') ?>" autocomplete="off"></label>
<label>Usuario de la base<input name="db_user" value="<?= e($_POST['db_user'] ?? '') ?>" autocomplete="off"></label>
<label>Contraseña de la base<input name="db_password" type="password" autocomplete="new-password"></label></fieldset>
<fieldset><legend>Primer administrador</legend><label>Usuario para editar<input name="admin_username" required maxlength="80" autocomplete="username" value="<?= e($_POST['admin_username'] ?? '') ?>"></label>
<label>Contraseña <span class="hint">Al menos 8 caracteres</span><input name="admin_password" type="password" required minlength="8" maxlength="72" autocomplete="new-password"></label>
<label>Repetir contraseña<input name="admin_confirm" type="password" required minlength="8" maxlength="72" autocomplete="new-password"></label></fieldset>
<button class="button primary">Instalar y empezar</button>
</form></main></body></html>
