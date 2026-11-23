<?php
declare(strict_types=1);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (isset($_GET['id']) && !$id) {
    http_response_code(404);
    exit('Atleta indisponível.');
}
$creating = !$id && (($_GET['view'] ?? '') === 'new');
$pdo = db();
$name = '';
$details = [];
$active = true;
if ($id) {
    $statement = $pdo->prepare('SELECT name, details, active FROM athletes WHERE id = ?');
    $statement->execute([$id]);
    $athlete = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$athlete) {
        http_response_code(404);
        exit('Atleta indisponível.');
    }
    $name = $athlete['name'];
    $details = $athlete['details'] === null ? [] : json_decode($athlete['details'], true, 512, JSON_THROW_ON_ERROR);
    $active = (bool) $athlete['active'];
}

$error = '';
if ($method === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) {
        http_response_code(403);
        exit('Atualize a página e tente novamente.');
    }
    $operation = $_POST['operation'] ?? '';
    if ($operation === 'save' && ($id || $creating)) {
        $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
        $active = ($_POST['active'] ?? '') === '1';
        $details = [];
        foreach (ATHLETE_FIELDS as $field => $label) {
            $value = $_POST[$field] ?? '';
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > 255) {
                $error = "Verifica o campo «$label».";
                break;
            }
            $details[$field] = trim($value);
        }
        if ($name === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > 150) {
            $error = 'Indica um nome válido com até 150 caracteres.';
        }
        if ($error === '') {
            $data = [$name, json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), (int) $active];
            if ($id) {
                $pdo->prepare('UPDATE athletes SET name=?, details=?, active=? WHERE id=?')->execute([...$data, $id]);
            } else {
                $pdo->prepare('INSERT INTO athletes (name, details, active) VALUES (?, ?, ?)')->execute($data);
                $id = (int) $pdo->lastInsertId();
            }
            $_SESSION['athlete_message'] = 'Dados guardados.';
        }
    } elseif ($operation === 'photo' && $id) {
        try {
            saveAthletePhoto($id, $_FILES['photo'] ?? []);
            $_SESSION['athlete_message'] = 'Fotografia guardada.';
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        }
    } elseif ($operation === 'remove-photo' && $id) {
        $path = athletePhotoPath($id);
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Photo removal failed.');
        }
        $_SESSION['athlete_message'] = 'Fotografia removida.';
    } else {
        http_response_code(400);
        exit('Ação inválida.');
    }
    if ($error === '') {
        header('Location: ?action=athletes&id=' . $id, true, 303);
        exit;
    }
    http_response_code(422);
} elseif ($method !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

$message = $_SESSION['athlete_message'] ?? '';
unset($_SESSION['athlete_message']);
session_write_close();
$hasPhoto = $id && is_readable(athletePhotoPath($id));
$athletes = $id || $creating ? [] : $pdo->query('SELECT id, name, active FROM athletes ORDER BY name, id')->fetchAll(PDO::FETCH_ASSOC);

function saveAthletePhoto(int $id, array $upload): void
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || ($upload['size'] ?? 0) > 8_000_000 || !is_uploaded_file($upload['tmp_name'] ?? '')) {
        throw new InvalidArgumentException('Escolhe uma fotografia JPEG, PNG ou WebP até 8 MB.');
    }
    if (!function_exists('imagewebp')) {
        throw new InvalidArgumentException('O carregamento de fotografias está temporariamente indisponível.');
    }
    $source = $upload['tmp_name'];
    $info = @getimagesize($source);
    if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 16_000_000
        || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new InvalidArgumentException('Escolhe uma imagem JPEG, PNG ou WebP com resolução até 16 megapíxeis.');
    }
    $bytes = file_get_contents($source);
    $image = $bytes === false ? false : @imagecreatefromstring($bytes);
    if (!$image) {
        throw new InvalidArgumentException('Não foi possível ler esta imagem.');
    }

    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $orientation = @exif_read_data($source)['Orientation'] ?? 1;
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }
        $angle = match ($orientation) { 3, 4 => 180, 5, 8 => 90, 6, 7 => -90, default => 0 };
        if ($angle !== 0) {
            $image = imagerotate($image, $angle, 0);
        }
    }
    $width = imagesx($image);
    $height = imagesy($image);
    $scale = min(1, 640 / max($width, $height));
    $photo = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
    $path = athletePhotoPath($id);
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Photo directory unavailable.');
    }
    $temp = tempnam($directory, '.photo-');
    if (!$temp) {
        throw new RuntimeException('Photo staging failed.');
    }
    try {
        if (!$photo || !imagewebp($photo, $temp, 85) || !filesize($temp) || !rename($temp, $path)) {
            throw new RuntimeException('Photo write failed.');
        }
    } finally {
        if (is_file($temp)) {
            unlink($temp);
        }
    }
}
?>
<!doctype html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestão de atletas</title>
    <link rel="stylesheet" href="assets/styles.css?v=12">
</head>
<body>
<?php require __DIR__ . '/navigation.php'; ?>
<main>
    <h1>Gestão de atletas</h1>
    <?php if ($message !== ''): ?><p role="status"><?= escape($message) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="error" role="alert"><?= escape($error) ?></p><?php endif; ?>
    <?php if ($id || $creating): ?>
        <p><a href="?action=athletes">Voltar aos atletas</a></p>
        <h2><?= $id ? 'Editar atleta' : 'Novo atleta' ?></h2>
        <?php if ($id): ?>
            <div class="athlete-photo-preview" aria-hidden="true">
                <?php if ($hasPhoto): ?>
                    <img src="?action=photo&amp;id=<?= $id ?>" alt="">
                <?php else: ?>
                    <?= escape(athleteInitials($name)) ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <form class="credentials user-form" method="post">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <label>Nome <input name="name" value="<?= escape($name) ?>" maxlength="150" required></label>
            <?php foreach (ATHLETE_FIELDS as $field => $label): ?>
                <label><?= escape($label) ?>
                    <input name="<?= $field ?>" value="<?= escape($details[$field] ?? '') ?>" maxlength="255"
                        <?= in_array($field, ['phone', 'guardian_phone'], true) ? 'type="tel"' : '' ?>>
                </label>
            <?php endforeach; ?>
            <label class="check"><input type="checkbox" name="active" value="1" <?= $active ? 'checked' : '' ?>> Atleta ativo</label>
            <button class="primary" name="operation" value="save">Guardar dados</button>
        </form>
        <?php if ($id): ?>
            <h2>Fotografia</h2>
            <form class="credentials user-form" method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <label>Escolher fotografia (JPEG, PNG ou WebP)
                    <input name="photo" type="file" accept="image/jpeg,image/png,image/webp" required>
                </label>
                <button class="primary" name="operation" value="photo">Carregar fotografia</button>
            </form>
            <?php if ($hasPhoto): ?>
                <form method="post" class="remove-athlete-photo">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <button class="secondary danger" name="operation" value="remove-photo">Remover fotografia</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    <?php else: ?>
        <p><a class="primary user-add" href="?action=athletes&amp;view=new">Adicionar atleta</a></p>
        <ul class="athlete-directory">
            <?php foreach ($athletes as $athlete): ?>
                <?php $athleteId = (int) $athlete['id']; ?>
                <li>
                    <a href="?action=athletes&amp;id=<?= $athleteId ?>">
                        <span class="portrait athlete-directory-portrait" aria-hidden="true">
                            <?= escape(athleteInitials($athlete['name'])) ?>
                            <?php if (is_readable(athletePhotoPath($athleteId))): ?>
                                <img src="?action=photo&amp;id=<?= $athleteId ?>" alt="" loading="lazy">
                            <?php endif; ?>
                        </span>
                        <span><?= escape($athlete['name']) ?></span>
                    </a>
                    <span><?= $athlete['active'] ? 'Ativo' : 'Inativo' ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</main>
<footer class="app-version">Versão <?= APP_VERSION ?></footer>
</body>
</html>
