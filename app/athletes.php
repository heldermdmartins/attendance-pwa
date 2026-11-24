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
$categories = ['Bambi', 'Escolar', 'Infantil', 'Iniciado', 'Cadete', 'Juvenil', 'Júnior', 'Sénior', 'Master'];
$active = true;
if ($id) {
    $statement = $pdo->prepare('SELECT name, details, active FROM athletes WHERE id = ? AND (active = 1 OR ? = 1)');
    $statement->execute([$id, (int) $isAdmin]);
    $athlete = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$athlete) {
        http_response_code(404);
        exit('Atleta indisponível.');
    }
    $name = $athlete['name'];
    $details = $athlete['details'] === null ? [] : json_decode($athlete['details'], true, 512, JSON_THROW_ON_ERROR);
    $active = (bool) $athlete['active'];
}

$storedCategory = $details['category'] ?? '';
if ($storedCategory !== '' && !in_array($storedCategory, $categories, true)) {
    $categories[] = $storedCategory;
}
$currentYear = (int) substr($today, 0, 4);
$seasons = array_map('strval', range($currentYear, $currentYear + 20));
$storedSeason = $details['season'] ?? '';
if ($storedSeason !== '' && !in_array($storedSeason, $seasons, true)) {
    array_unshift($seasons, $storedSeason);
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
            $value = $field === 'medical_exam'
                ? (($_POST[$field] ?? '') === '1' ? 'Sim' : 'Não')
                : ($_POST[$field] ?? '');
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > 255) {
                $error = "Verifica o campo «$label».";
                break;
            }
            $details[$field] = trim($value);
            if ($field === 'category' && $details[$field] !== '' && !in_array($details[$field], $categories, true)) {
                $error = 'Seleciona um escalão da lista.';
                break;
            }
            if ($field === 'season' && $details[$field] !== '' && !in_array($details[$field], $seasons, true)) {
                $error = 'Seleciona uma época da lista.';
                break;
            }
            if ($field === 'birth_date' && $details[$field] !== '') {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $details[$field]);
                if (!$date || $date->format('Y-m-d') !== $details[$field] || $details[$field] > $today) {
                    $error = 'Indica uma data de nascimento válida e que não seja futura.';
                    break;
                }
            }
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
            saveProfilePhoto(athletePhotoPath($id), $_FILES['photo'] ?? []);
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
$athletes = $id || $creating ? [] : $pdo->query(
    'SELECT id, name, active FROM athletes' . ($isAdmin ? '' : ' WHERE active = 1') . ' ORDER BY name, id'
)->fetchAll(PDO::FETCH_ASSOC);
$pageTitle = $isAdmin ? 'Gestão de atletas' : 'Atletas';

?>
<!doctype html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="assets/styles.css?v=19">
    <script src="assets/app.js?v=2" defer></script>
</head>
<body>
<?php require __DIR__ . '/navigation.php'; ?>
<main>
    <h1><?= $pageTitle ?></h1>
    <?php if ($message !== ''): ?><p role="status"><?= escape($message) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="error" role="alert"><?= escape($error) ?></p><?php endif; ?>
    <?php if ($id || $creating): ?>
        <p><a href="?action=athletes">Voltar aos atletas</a></p>
        <?php if ($isAdmin): ?><h2><?= $id ? 'Editar atleta' : 'Novo atleta' ?></h2><?php endif; ?>
        <?php if ($id): ?>
            <div class="athlete-photo-preview" aria-hidden="true">
                <?php if ($hasPhoto): ?>
                    <img src="?action=photo&amp;id=<?= $id ?>" alt="">
                <?php else: ?>
                    <?= escape(nameInitials($name)) ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if (!$isAdmin): ?>
            <h2><?= escape($name) ?></h2>
            <dl>
                <?php foreach (['class', 'category', 'season'] as $field): ?>
                    <dt><strong><?= escape(ATHLETE_FIELDS[$field]) ?></strong></dt>
                    <dd><?= escape(($details[$field] ?? '') ?: '—') ?></dd>
                <?php endforeach; ?>
            </dl>
        <?php else: ?>
        <form class="credentials user-form" method="post">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <label>Nome <input name="name" value="<?= escape($name) ?>" maxlength="150" required></label>
            <?php foreach (ATHLETE_FIELDS as $field => $label): ?>
                <?php if ($field === 'medical_exam'): ?>
                    <label class="check">
                        <input type="checkbox" name="medical_exam" value="1" <?= mb_strtolower($details['medical_exam'] ?? '', 'UTF-8') === 'sim' ? 'checked' : '' ?>>
                        <?= escape($label) ?>
                    </label>
                    <?php continue; ?>
                <?php endif; ?>
                <label><?= escape($label) ?>
                    <?php if ($field === 'birth_date'): ?>
                        <input type="date" name="birth_date" value="<?= escape($details[$field] ?? '') ?>" max="<?= $today ?>">
                    <?php elseif ($field === 'category'): ?>
                        <span class="form-select">
                            <select name="category">
                                <option value="">Selecionar escalão</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= escape($category) ?>" <?= ($details['category'] ?? '') === $category ? 'selected' : '' ?>><?= escape($category) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                                <path d="m6 9 6 6 6-6"/>
                            </svg>
                        </span>
                    <?php elseif ($field === 'season'): ?>
                        <span class="form-select">
                            <select name="season">
                                <option value="">Selecionar época</option>
                                <?php foreach ($seasons as $season): ?>
                                    <option value="<?= escape($season) ?>" <?= ($details['season'] ?? '') === $season ? 'selected' : '' ?>><?= escape($season) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                                <path d="m6 9 6 6 6-6"/>
                            </svg>
                        </span>
                    <?php else: ?>
                        <input name="<?= $field ?>" value="<?= escape($details[$field] ?? '') ?>" maxlength="255"
                            <?= in_array($field, ['phone', 'guardian_phone'], true) ? 'type="tel"' : '' ?>>
                    <?php endif; ?>
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
        <?php endif; ?>
    <?php else: ?>
        <?php if ($isAdmin): ?>
            <p><a class="primary user-add" href="?action=athletes&amp;view=new">Adicionar atleta</a></p>
        <?php endif; ?>
        <ul class="athlete-directory">
            <?php foreach ($athletes as $athlete): ?>
                <?php $athleteId = (int) $athlete['id']; ?>
                <li>
                    <a href="?action=athletes&amp;id=<?= $athleteId ?>">
                        <span class="portrait athlete-directory-portrait" aria-hidden="true">
                            <?= escape(nameInitials($athlete['name'])) ?>
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
