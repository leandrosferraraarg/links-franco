<?php
declare(strict_types=1);
require __DIR__ . '/app.php';
[$config, $db] = installedDatabase();
$person = max(0, (int) ($_GET['person'] ?? 0));
$error = '';
if (isAdmin($config)) { header('Location: ./?edit=1&person=' . $person); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        checkCsrf();
        if (($_SESSION['login_wait_until'] ?? 0) > time()) throw new RuntimeException('Esperá 30 segundos antes de volver a intentar.');
        $stmt = $db->prepare('SELECT * FROM admins WHERE username=?');
        $stmt->execute([trim((string) ($_POST['username'] ?? ''))]);
        $user = $stmt->fetch();
        $password = (string) ($_POST['password'] ?? '');
        $valid = password_verify($password, $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        if (!$user || !$valid) {
            $_SESSION['login_failures'] = ($_SESSION['login_failures'] ?? 0) + 1;
            if ($_SESSION['login_failures'] >= 5) { $_SESSION['login_wait_until'] = time() + 30; $_SESSION['login_failures'] = 0; }
            throw new RuntimeException('Usuario o contraseña incorrectos.');
        }
        session_regenerate_id(true);
        $_SESSION = ['csrf' => bin2hex(random_bytes(24)), 'admin_id' => 1, 'installation_id' => $config['installation_id'], 'admin_last_seen' => time()];
        header('Location: ./?edit=1&person=' . $person, true, 303); exit;
    } catch (Throwable $exception) {
        $error = $exception instanceof PDOException ? 'No se pudo iniciar sesión. Intentá de nuevo.' : $exception->getMessage();
    }
}
?>
<!doctype html><html lang="es-AR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Entrar · Mis videos</title><link rel="icon" type="image/svg+xml" href="assets/favicon.svg"><link rel="stylesheet" href="assets/style.css?v=4"><link rel="stylesheet" href="assets/mobile.css?v=4"></head>
<body><header class="topbar"><a class="brand" href="./"><span class="brand-icon" aria-hidden="true">▶</span> Mis videos</a></header>
<main class="setup"><h1>Entrar para editar</h1><p>Usá el usuario y la contraseña del administrador.</p>
<?php if ($error): ?><p class="notice error" role="alert"><?= e($error) ?></p><?php endif ?>
<form method="post" class="setup-form"><?php csrf() ?><label>Usuario<input name="username" autocomplete="username" required maxlength="80" value="<?= e($_POST['username'] ?? '') ?>"></label><label>Contraseña<input name="password" type="password" autocomplete="current-password" required></label><button class="button primary">Entrar</button></form><a class="back-link" href="./?person=<?= $person ?>">← Volver a los favoritos</a>
</main></body></html>
