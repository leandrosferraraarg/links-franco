<?php
declare(strict_types=1);

require __DIR__ . '/app.php';
[$config, $db] = installedDatabase();
function youtubeId(string $url): ?string {
    $parts = parse_url($url);
    if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) return null;
    $host = strtolower($parts['host'] ?? '');
    $path = trim($parts['path'] ?? '', '/');
    if (in_array($host, ['youtu.be', 'www.youtu.be'], true)) $id = explode('/', $path)[0];
    elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com'], true)) {
        parse_str($parts['query'] ?? '', $query);
        $segments = explode('/', $path);
        $id = in_array($segments[0], ['shorts', 'embed', 'live'], true) ? ($segments[1] ?? '') : ($query['v'] ?? '');
    } else return null;
    return is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/D', $id) ? $id : null;
}
function youtubeTarget(string $url): ?array {
    $video = youtubeId($url);
    if ($video) return ['video_id' => $video, 'url' => 'https://www.youtube.com/watch?v=' . $video];
    $parts = parse_url($url);
    if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
        || !in_array(strtolower($parts['host'] ?? ''), ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) return null;
    $path = rawurldecode(trim($parts['path'] ?? '', '/'));
    if (!preg_match('~^(@[\p{L}\p{N}\p{M}_.·-]+|channel/UC[A-Za-z0-9_-]{22}|(?:c|user)/[\p{L}\p{N}\p{M}_.-]+)(?:/(?:videos|shorts|streams|featured|playlists|community|about))?$~uD', $path, $match)) return null;
    $channel = implode('/', array_map('rawurlencode', explode('/', $match[1])));
    return ['video_id' => '', 'url' => 'https://www.youtube.com/' . str_replace('%40', '@', $channel)];
}
function favoriteUrl(array $favorite): string {
    return ($favorite['link_url'] ?? '') ?: 'https://www.youtube.com/watch?v=' . $favorite['video_id'];
}
function cleanText(string $key, int $limit = 120): string {
    $value = trim((string) ($_POST[$key] ?? ''));
    if ($value === '' || strlen($value) > $limit) throw new RuntimeException('Completá los textos sin superar el largo permitido.');
    return $value;
}
function deleteUpload(string $image): void {
    if (preg_match('~^uploads/[a-f0-9]{32}\.(jpg|png|webp|gif)$~D', $image)) {
        $file = __DIR__ . '/' . $image;
        if (is_file($file)) unlink($file);
    }
}

$error = '';
$person = max(0, (int) ($_GET['person'] ?? 0));
$admin = isset($_GET['edit']);
if (($admin || $_SERVER['REQUEST_METHOD'] === 'POST') && !isAdmin($config)) {
    header('Location: login.php?person=' . $person, true, 303); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newUpload = '';
    try {
        checkCsrf();
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            header('Location: ./?person=' . $person, true, 303); exit;
        }
        $id = (int) ($_POST['id'] ?? 0);
        if ($action === 'person_save') {
            $name = cleanText('name', 80);
            $color = (string) ($_POST['color'] ?? '#348478');
            if (!preg_match('/^#[a-f0-9]{6}$/iD', $color)) $color = '#348478';
            $stmt = $db->prepare($id ? 'UPDATE people SET name=?, color=? WHERE id=?' : 'INSERT INTO people(name,color) VALUES (?,?)');
            $stmt->execute($id ? [$name, $color, $id] : [$name, $color]);
        } elseif ($action === 'person_delete') {
            $stmt = $db->prepare('SELECT image FROM favorites WHERE person_id=?'); $stmt->execute([$id]); $images = $stmt->fetchAll();
            $db->prepare('DELETE FROM people WHERE id=?')->execute([$id]);
            foreach ($images as $row) deleteUpload($row['image']);
            if ($person === $id) $person = 0;
        } elseif ($action === 'favorite_save') {
            $title = cleanText('title', 160);
            $target = youtubeTarget(cleanText('url', 2048));
            if (!$target) throw new RuntimeException('Pegá un enlace válido a un video, Short o canal de YouTube.');
            $owner = (int) ($_POST['person_id'] ?? 0);
            $stmt = $db->prepare('SELECT id FROM people WHERE id=?'); $stmt->execute([$owner]);
            if (!$stmt->fetch()) throw new RuntimeException('Primero creá o elegí una persona.');
            $oldImage = '';
            if ($id) {
                $stmt = $db->prepare('SELECT image FROM favorites WHERE id=?'); $stmt->execute([$id]); $old = $stmt->fetch();
                if (!$old) throw new RuntimeException('Ese favorito ya no existe.');
                $oldImage = $old['image'];
            }
            $image = trim((string) ($_POST['image'] ?? ''));
            if ($image !== '' && !preg_match('~^uploads/[a-f0-9]{32}\.(jpg|png|webp|gif)$~D', $image) && (!filter_var($image, FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($image, PHP_URL_SCHEME) ?: ''), ['https', 'http'], true))) throw new RuntimeException('La imagen debe ser una dirección http o https. También podés subir una foto.');
            $upload = $_FILES['photo'] ?? null;
            if ($upload && $upload['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > 5 * 1024 * 1024) throw new RuntimeException('No se pudo subir la foto. Usá una imagen de hasta 5 MB.');
                $info = @getimagesize($upload['tmp_name']);
                $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$info['mime'] ?? ''] ?? null;
                if (!$extension || $info[0] > 10000 || $info[1] > 10000) throw new RuntimeException('Usá una imagen JPG, PNG, WebP o GIF de hasta 10000 píxeles por lado.');
                $image = 'uploads/' . bin2hex(random_bytes(16)) . '.' . $extension;
                if (!move_uploaded_file($upload['tmp_name'], __DIR__ . '/' . $image)) throw new RuntimeException('No se pudo guardar la foto. Revisá los permisos de uploads.');
                $newUpload = $image;
            }
            $position = max(0, min(9999, (int) ($_POST['position'] ?? 0)));
            $values = [$owner, $title, $target['video_id'], $image, $position, $target['url']];
            if ($id) $values[] = $id;
            $db->prepare($id ? 'UPDATE favorites SET person_id=?, title=?, video_id=?, image=?, position=?, link_url=? WHERE id=?' : 'INSERT INTO favorites(person_id,title,video_id,image,position,link_url) VALUES (?,?,?,?,?,?)')->execute($values);
            if ($oldImage !== $image) deleteUpload($oldImage);
        } elseif ($action === 'favorite_delete') {
            $stmt = $db->prepare('SELECT image FROM favorites WHERE id=?'); $stmt->execute([$id]); $old = $stmt->fetch();
            $db->prepare('DELETE FROM favorites WHERE id=?')->execute([$id]);
            if ($old) deleteUpload($old['image']);
        } elseif ($action === 'settings') {
            $db->prepare("UPDATE settings SET value=? WHERE `key`='title'")->execute([cleanText('site_title', 100)]);
        } else throw new RuntimeException('Acción desconocida.');
        $_SESSION['notice'] = '¡Listo! Los cambios quedaron guardados.';
        header('Location: ?edit=1&person=' . $person, true, 303); exit;
    } catch (Throwable $exception) {
        if ($newUpload) deleteUpload($newUpload);
        $error = $exception instanceof PDOException ? 'No pudimos guardar el cambio. Intentá de nuevo.' : $exception->getMessage();
        $admin = true;
    }
}
$people = $db->query('SELECT * FROM people ORDER BY id')->fetchAll();
if ($person && !in_array($person, array_column($people, 'id'))) $person = 0;
$title = $db->query("SELECT value FROM settings WHERE `key`='title'")->fetchColumn();
$where = $person ? ' WHERE f.person_id=' . $person : '';
$count = (int) $db->query('SELECT COUNT(*) FROM favorites f' . $where)->fetchColumn();
$pages = max(1, (int) ceil($count / 9));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
$favorites = $db->query('SELECT f.*, p.name, p.color FROM favorites f JOIN people p ON p.id=f.person_id' . $where . ' ORDER BY f.position, f.id DESC LIMIT 9 OFFSET ' . (($page - 1) * 9))->fetchAll();
$editing = null;
if ($admin && isset($_GET['favorite'])) {
    $stmt = $db->prepare('SELECT * FROM favorites WHERE id=?'); $stmt->execute([(int) $_GET['favorite']]); $editing = $stmt->fetch() ?: null;
}
if ($error && ($_POST['action'] ?? '') === 'favorite_save') $editing = ['id' => (int) ($_POST['id'] ?? 0), 'title' => $_POST['title'] ?? '', 'person_id' => $_POST['person_id'] ?? 0, 'image' => $_POST['image'] ?? '', 'position' => $_POST['position'] ?? 0, 'video_id' => '', 'url' => $_POST['url'] ?? ''];
$notice = $_SESSION['notice'] ?? ''; unset($_SESSION['notice']);
?>
<!doctype html>
<html lang="es-AR">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#ffffff"><title><?= e($title) ?></title>
    <link rel="stylesheet" href="assets/style.css?v=4"><link rel="stylesheet" href="assets/mobile.css?v=4"><script src="assets/app.js?v=4" defer></script>
</head>
<body>
<header class="topbar"><a class="brand" href="?" aria-label="Mis videos, inicio"><span class="brand-icon" aria-hidden="true">▶</span> Mis videos</a><div class="header-actions"><a class="button quiet" href="<?= $admin ? '?person=' . $person : '?edit=1&person=' . $person ?>"><?= $admin ? '← Ver favoritos' : '✎ Editar' ?></a><?php if (isAdmin($config)): ?><form method="post"><?php csrf() ?><button class="button quiet" name="action" value="logout">Salir</button></form><?php endif ?></div></header>
<main>
    <?php if ($notice): ?><p class="notice" role="status"><?= e($notice) ?></p><?php endif ?>
    <?php if ($error): ?><p class="notice error" role="alert"><?= e($error) ?></p><?php endif ?>
    <nav class="filters" aria-label="Elegir persona"><a class="chip <?= !$person ? 'active' : '' ?>" href="?<?= $admin ? 'edit=1&' : '' ?>person=0" <?= !$person ? 'aria-current="true"' : '' ?>>Todos</a><?php foreach ($people as $p): ?><a class="chip <?= $person === (int) $p['id'] ? 'active' : '' ?>" href="?<?= $admin ? 'edit=1&' : '' ?>person=<?= $p['id'] ?>" <?= $person === (int) $p['id'] ? 'aria-current="true"' : '' ?>><span class="person-dot" style="--person-color:<?= e($p['color']) ?>"></span><?= e($p['name']) ?></a><?php endforeach ?></nav>

    <?php if ($admin): ?>
    <section class="editor" aria-label="Editar favoritos">
        <details <?= $editing || !$count || $error ? 'open' : '' ?> id="favorite-form"><summary><?= $editing && $editing['id'] ? '✎ Editar favorito' : '＋ Añadir un favorito' ?></summary>
        <?php if (!$people): ?><p>Primero añadí una persona en la sección de abajo.</p><?php else: ?>
        <form method="post" enctype="multipart/form-data" class="form-grid">
            <?php csrf() ?><input type="hidden" name="action" value="favorite_save"><input type="hidden" name="id" value="<?= e($editing['id'] ?? 0) ?>"><input type="hidden" name="MAX_FILE_SIZE" value="5242880">
            <label>Nombre del favorito<input name="title" required maxlength="160" placeholder="Por ejemplo: GameToons Español" value="<?= e($editing['title'] ?? '') ?>"></label>
            <label>Enlace de YouTube <span class="hint">Video, Short o canal (por ejemplo: youtube.com/@GameToonsEspanol)</span><input name="url" type="url" required maxlength="2048" placeholder="https://www.youtube.com/@GameToonsEspanol" value="<?= e($editing['url'] ?? ($editing ? favoriteUrl($editing) : '')) ?>"></label>
            <label>¿De quién es?<select name="person_id"><?php foreach ($people as $p): ?><option value="<?= $p['id'] ?>" <?= (int) ($editing['person_id'] ?? $person) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach ?></select></label>
            <label>Orden <span class="hint">Los números menores aparecen primero</span><input type="number" name="position" min="0" max="9999" value="<?= e($editing['position'] ?? 0) ?>"></label>
            <label>Imagen personalizada <span class="hint">Videos: miniatura automática. Canales: elegí una foto o usá la portada genérica.</span><input name="image" maxlength="2048" placeholder="https://…" value="<?= e($editing['image'] ?? '') ?>"></label>
            <label>O subí una foto <span class="hint">JPG, PNG, WebP o GIF · hasta 5 MB</span><input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/gif"></label>
            <div class="form-actions"><button class="button primary" type="submit">Guardar favorito</button><?php if ($editing): ?><a class="button quiet" href="?edit=1&person=<?= $person ?>">Cancelar</a><?php endif ?></div>
        </form><?php endif ?></details>
        <details <?= !$people ? 'open' : '' ?>><summary>☺ Personas y nombre de la página</summary>
            <div class="people-editor"><?php foreach ($people as $p): ?><form method="post" class="person-form"><?php csrf() ?><input type="hidden" name="id" value="<?= $p['id'] ?>"><input name="name" aria-label="Nombre de la persona" required maxlength="80" value="<?= e($p['name']) ?>"><input type="color" name="color" aria-label="Color de la persona" value="<?= e($p['color']) ?>"><button class="button small" name="action" value="person_save">Guardar</button><button class="button small danger" name="action" value="person_delete" formnovalidate data-confirm="¿Eliminar a esta persona y todos sus favoritos?">Eliminar</button></form><?php endforeach ?>
            <form method="post" class="person-form"><?php csrf() ?><input type="hidden" name="action" value="person_save"><input name="name" aria-label="Nombre de la nueva persona" required maxlength="80" placeholder="Nombre de una nueva persona"><input type="color" name="color" aria-label="Color de la nueva persona" value="#348478"><button class="button primary small">Añadir persona</button></form></div>
            <form method="post" class="title-form"><?php csrf() ?><input type="hidden" name="action" value="settings"><label>Nombre en la pestaña del navegador<input name="site_title" required maxlength="100" value="<?= e($title) ?>"></label><button class="button small">Guardar nombre</button></form>
        </details>
    </section>
    <?php endif ?>

    <h1 class="sr-only">Mis videos: <?= $count ?> <?= $count === 1 ? 'favorito' : 'favoritos' ?></h1>
    <?php if (!$favorites): ?>
    <section class="empty"><div class="empty-icon" aria-hidden="true">▷</div><h2>Los mejores momentos empiezan acá</h2><p><?= $people ? 'Añadí un video o canal favorito y elegí la foto que más te guste.' : 'Creá una persona y guardá su primer favorito.' ?></p><a class="button primary" href="?edit=1&person=<?= $person ?>#favorite-form">＋ <?= $people ? 'Añadir un favorito' : 'Empezar' ?></a></section>
    <?php else: ?>
    <section class="mosaic" aria-label="Videos y canales favoritos">
    <?php foreach ($favorites as $favorite): $isChannel = $favorite['video_id'] === ''; $thumbnail = $isChannel ? 'assets/channel.svg' : 'https://i.ytimg.com/vi/' . $favorite['video_id'] . '/hqdefault.jpg'; ?>
        <article class="card" data-id="<?= $favorite['id'] ?>"><a class="video-link" href="<?= e(favoriteUrl($favorite)) ?>" aria-label="Ver <?= e($favorite['title']) ?> en YouTube"><div class="thumbnail"><img src="<?= e($favorite['image'] ?: $thumbnail) ?>" data-fallback="<?= e($thumbnail) ?>" alt="" loading="lazy" width="480" height="360"><span class="play" aria-hidden="true"><?= $isChannel ? '↗' : '▶' ?></span><span class="youtube-label"><?= $isChannel ? 'Canal de YouTube' : 'YouTube' ?> ↗</span></div><div class="card-body"><span class="owner"><span class="person-dot" style="--person-color:<?= e($favorite['color']) ?>"></span><?= e($favorite['name']) ?></span><h3><?= e($favorite['title']) ?></h3></div></a>
        <?php if ($admin): ?><div class="card-actions"><a class="button small" href="?edit=1&person=<?= $person ?>&favorite=<?= $favorite['id'] ?>#favorite-form">✎ Editar</a><form method="post"><?php csrf() ?><input type="hidden" name="action" value="favorite_delete"><input type="hidden" name="id" value="<?= $favorite['id'] ?>"><button class="button small danger" data-confirm="¿Eliminar este favorito?">Eliminar</button></form></div><?php endif ?></article>
    <?php endforeach ?></section>
    <?php endif ?>
    <?php if ($pages > 1): ?><nav class="pagination" aria-label="Cargar más favoritos"><?php if ($page > 1): ?><a class="button previous-page" href="?person=<?= $person ?>&page=<?= $page - 1 ?><?= $admin ? '&edit=1' : '' ?>">← Anteriores</a><?php endif ?><?php if ($page < $pages): ?><a class="button" data-next href="?person=<?= $person ?>&page=<?= $page + 1 ?><?= $admin ? '&edit=1' : '' ?>">Cargar más</a><?php endif ?><span class="load-status" role="status" aria-live="polite"></span></nav><?php endif ?>
</main>
</body></html>
