<?php
declare(strict_types=1);

$pdo = db();
$profile = $currentAccount;
$error = '';

if ($method === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) {
        http_response_code(403);
        exit('Atualiza a página e tenta novamente.');
    }

    $operation = $_POST['operation'] ?? '';
    if (!in_array($operation, ['contact', 'password', 'photo', 'remove-photo'], true)) {
        http_response_code(400);
        exit('Ação inválida.');
    }

    $message = '';
    try {
        if ($operation === 'contact') {
            foreach (['name', 'email', 'phone'] as $field) {
                $profile[$field] = is_string($_POST[$field] ?? null) ? trim($_POST[$field]) : '';
            }
            if ($profile['name'] === '' || !mb_check_encoding($profile['name'], 'UTF-8') || mb_strlen($profile['name']) > 150) {
                throw new InvalidArgumentException('Indica um nome com até 150 caracteres.');
            }
            if (strlen($profile['email']) > 254 || !filter_var($profile['email'], FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Indica um endereço de email válido.');
            }
            if ($profile['phone'] !== '' && (!preg_match('/^\+?[0-9 ().-]{7,32}$/D', $profile['phone'])
                || !preg_match('/^[0-9]{7,15}$/D', preg_replace('/\D/', '', $profile['phone'])))) {
                throw new InvalidArgumentException('Indica um número de telemóvel válido.');
            }
            $emailChanged = $profile['email'] !== $currentAccount['email'];
            if ($emailChanged && !verifyProfilePassword($currentAccount['password_hash'])) {
                throw new InvalidArgumentException('Confirma a palavra-passe atual para alterar o email de acesso.');
            }

            $statement = $pdo->prepare($emailChanged
                ? 'UPDATE coaches SET name=?, phone=?, email=?, username=? WHERE id=? AND active=1 AND password_hash=? AND email=?'
                : 'UPDATE coaches SET name=?, phone=? WHERE id=? AND active=1');
            $statement->execute($emailChanged
                ? [$profile['name'], $profile['phone'], $profile['email'], $profile['email'], $coach, $currentAccount['password_hash'], $currentAccount['email']]
                : [$profile['name'], $profile['phone'], $coach]);
            if ($emailChanged && $statement->rowCount() !== 1) {
                throw new InvalidArgumentException('A conta foi atualizada entretanto. Volta a abrir o perfil e tenta novamente.');
            }
            $message = 'Dados guardados.';
        } elseif ($operation === 'password') {
            if (!verifyProfilePassword($currentAccount['password_hash'])) {
                throw new InvalidArgumentException('A palavra-passe atual está incorreta.');
            }
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
                throw new InvalidArgumentException('A nova palavra-passe deve ter entre 12 e 72 bytes.');
            }
            if ($password !== ($_POST['password_confirmation'] ?? null)) {
                throw new InvalidArgumentException('A confirmação deve coincidir com a nova palavra-passe.');
            }
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $statement = $pdo->prepare('UPDATE coaches SET password_hash=? WHERE id=? AND active=1 AND password_hash=?');
            $statement->execute([$hash, $coach, $currentAccount['password_hash']]);
            if ($statement->rowCount() !== 1) {
                throw new InvalidArgumentException('A conta foi atualizada entretanto. Volta a abrir o perfil e tenta novamente.');
            }
            forgetRememberedLogin($https);
            session_regenerate_id(true);
            $_SESSION['credential'] = hash('sha256', $hash);
            unset($_SESSION['csrf']);
            $message = 'Palavra-passe atualizada.';
        } elseif ($operation === 'photo') {
            saveProfilePhoto(userPhotoPath($coach), $_FILES['photo'] ?? []);
            $message = 'Fotografia guardada.';
        } else {
            $path = userPhotoPath($coach);
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('Photo removal failed.');
            }
            $message = 'Fotografia removida.';
        }
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        if (($exception->errorInfo[1] ?? null) !== 1062) {
            throw $exception;
        }
        $error = 'Já existe uma conta com esse email.';
    }

    if ($error === '') {
        $_SESSION['profile_message'] = $message;
        header('Location: ?action=profile', true, 303);
        exit;
    }
    http_response_code(422);
} elseif ($method !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

$message = $_SESSION['profile_message'] ?? '';
unset($_SESSION['profile_message']);
session_write_close();
$hasPhoto = is_readable(userPhotoPath($coach));

function verifyProfilePassword(string $hash): bool
{
    $password = $_POST['current_password'] ?? null;
    return is_string($password) && strlen($password) <= 72 && !str_contains($password, "\0") && password_verify($password, $hash);
}
?>
<!doctype html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>O meu perfil</title>
    <link rel="icon" type="image/png" sizes="192x192" href="assets/sporting-icon-192.png">
    <link rel="stylesheet" href="assets/styles.css?v=24">
    <script src="assets/app.js?v=4" defer></script>
</head>
<body>
<?php require __DIR__ . '/navigation.php'; ?>
<main>
    <h1>O meu perfil</h1>
    <?php if ($message !== ''): ?><p role="status"><?= escape($message) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="error" role="alert"><?= escape($error) ?></p><?php endif; ?>
    <div class="athlete-photo-preview" aria-hidden="true">
        <?php if ($hasPhoto): ?>
            <img src="?action=user-photo&amp;id=<?= $coach ?>" alt="">
        <?php else: ?>
            <?= escape(nameInitials($profile['name'] ?: $profile['username'])) ?>
        <?php endif; ?>
    </div>

    <section>
        <h2>Dados da conta</h2>
        <form class="credentials user-form" method="post" action="?action=profile">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <label>Nome <input name="name" autocomplete="name" maxlength="150" value="<?= escape($profile['name']) ?>" required></label>
            <label>Email de acesso <input type="email" name="email" autocomplete="username" maxlength="254" value="<?= escape($profile['email']) ?>" required></label>
            <label>Telemóvel <input type="tel" name="phone" autocomplete="tel" maxlength="32" value="<?= escape($profile['phone']) ?>"></label>
            <label>Palavra-passe atual, se alterares o email
                <input type="password" name="current_password" autocomplete="current-password" maxlength="72">
            </label>
            <button class="primary" name="operation" value="contact">Guardar dados</button>
        </form>
    </section>

    <section>
        <h2>Fotografia</h2>
        <form class="credentials user-form" method="post" enctype="multipart/form-data" action="?action=profile">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <label>Escolher fotografia (JPEG, PNG ou WebP)
                <input name="photo" type="file" accept="image/jpeg,image/png,image/webp" required>
            </label>
            <button class="primary" name="operation" value="photo">Carregar fotografia</button>
        </form>
        <?php if ($hasPhoto): ?>
            <form class="remove-athlete-photo" method="post" action="?action=profile">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <button class="secondary danger" name="operation" value="remove-photo">Remover fotografia</button>
            </form>
        <?php endif; ?>
    </section>

    <section>
        <h2>Alterar palavra-passe</h2>
        <form class="credentials user-form" method="post" action="?action=profile">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <label>Palavra-passe atual <input type="password" name="current_password" autocomplete="current-password" maxlength="72" required></label>
            <label>Nova palavra-passe <input type="password" name="password" autocomplete="new-password" minlength="12" maxlength="72" required></label>
            <label>Confirmar nova palavra-passe <input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" maxlength="72" required></label>
            <button class="primary" name="operation" value="password">Alterar palavra-passe</button>
        </form>
    </section>
</main>
<footer class="app-version">Versão <?= APP_VERSION ?></footer>
</body>
</html>
