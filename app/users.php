<?php
declare(strict_types=1);

$roles = ['user' => 'Utilizador', 'admin' => 'Administrador'];
$positions = ['coach' => 'Treinador', 'delegate' => 'Delegado'];
$fields = ['username', 'name', 'email', 'phone', 'role', 'club_position'];
$values = ['username' => '', 'name' => '', 'email' => '', 'phone' => '', 'role' => 'user', 'club_position' => 'coach'];
$editing = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (isset($_GET['id']) && !$editing) {
    http_response_code(404);
    exit('Conta indisponível.');
}

$pdo = db();
$account = null;
if ($editing) {
    $query = $pdo->prepare('SELECT id, username, name, email, phone, role, club_position, active FROM coaches WHERE id = ?');
    $query->execute([$editing]);
    $account = $query->fetch(PDO::FETCH_ASSOC);
    if (!$account) {
        http_response_code(404);
        exit('Conta indisponível.');
    }
    $values = array_intersect_key($account, $values);
}

$self = $editing === $coach;
$active = $account === null || (bool) $account['active'];
$error = '';
if ($method === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) {
        http_response_code(403);
        exit('Atualize a página e tente novamente.');
    }

    foreach ($fields as $field) {
        $values[$field] = is_string($_POST[$field] ?? null) ? trim($_POST[$field]) : '';
    }
    if ($self) {
        $values['role'] = 'admin';
    }
    $active = $self || ($_POST['active'] ?? '') === '1';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $validPassword = strlen($password) >= 12 && strlen($password) <= 72 && !str_contains($password, "\0");

    if (!preg_match('/^[a-zA-Z0-9_.-]{1,64}$/D', $values['username'])) {
        $error = 'Indique um nome de utilizador válido. Use letras sem acentos, números, pontos, hífen ou underscore.';
    } elseif ($values['name'] === '' || !mb_check_encoding($values['name'], 'UTF-8') || mb_strlen($values['name']) > 150) {
        $error = 'Indique um nome com até 150 caracteres.';
    } elseif (strlen($values['email']) > 254 || !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Indique um endereço de email válido.';
    } elseif (!preg_match('/^\+?[0-9 ().-]{7,32}$/D', $values['phone'])
        || !preg_match('/^[0-9]{7,15}$/D', preg_replace('/\D/', '', $values['phone']))) {
        $error = 'Indique um número de telemóvel válido.';
    } elseif (!isset($roles[$values['role']], $positions[$values['club_position']])) {
        $error = 'Escolha um perfil e uma posição no clube.';
    } elseif ((!$editing || $password !== '') && !$validPassword) {
        $error = 'A palavra-passe deve ter entre 12 e 72 bytes.';
    } else {
        try {
            $pdo->beginTransaction();
            // Serialize account changes and recheck access after acquiring the lock.
            $admins = $pdo->query("SELECT id FROM coaches WHERE role='admin' AND active=1 ORDER BY id FOR UPDATE")
                ->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array($coach, array_map('intval', $admins), true)) {
                $pdo->rollBack();
                http_response_code(403);
                exit('Área reservada a administradores.');
            }
            $valuesToSave = array_map(static fn ($field) => $values[$field], $fields);
            $valuesToSave[] = (int) $active;
            $hash = $password !== '' ? password_hash($password, PASSWORD_BCRYPT) : null;
            if ($editing) {
                $query = $pdo->prepare(
                    'UPDATE coaches SET username=?, name=?, email=?, phone=?, role=?, club_position=?, active=?,
                     password_hash=COALESCE(?, password_hash) WHERE id=?'
                );
                $query->execute([...$valuesToSave, $hash, $editing]);
            } else {
                $query = $pdo->prepare(
                    'INSERT INTO coaches (username, name, email, phone, role, club_position, active, password_hash)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $query->execute([...$valuesToSave, $hash]);
            }
            $pdo->commit();
            $_SESSION['users_saved'] = true;
            header('Location: ?action=users', true, 303);
            exit;
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (($exception->errorInfo[1] ?? null) !== 1062) {
                throw $exception;
            }
            $error = 'Já existe uma conta com esse nome de utilizador.';
        }
    }
    http_response_code(422);
} elseif ($method !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

$saved = !empty($_SESSION['users_saved']);
unset($_SESSION['users_saved']);
session_write_close();
$accounts = $pdo->query('SELECT id, username, name, email, phone, role, club_position, active FROM coaches ORDER BY name, username')
    ->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestão de utilizadores</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<main>
    <header>
        <h1>Gestão de utilizadores</h1>
        <a href="./">Presenças</a>
    </header>
    <?php if ($saved): ?><p role="status">Conta guardada.</p><?php endif; ?>
    <ul class="users-list">
        <?php foreach ($accounts as $person): ?>
        <li>
            <strong><?= escape($person['name'] ?: $person['username']) ?></strong>
            <span><?= escape($person['username']) ?> · <?= $roles[$person['role']] ?> · <?= $positions[$person['club_position']] ?> · <?= $person['active'] ? 'Ativa' : 'Inativa' ?></span>
            <?php if ($person['email'] !== ''): ?><span><?= escape($person['email']) ?></span><?php endif; ?>
            <?php if ($person['phone'] !== ''): ?><span><?= escape($person['phone']) ?></span><?php endif; ?>
            <a href="?action=users&amp;id=<?= (int) $person['id'] ?>#user-form">Editar <span class="visually-hidden"><?= escape($person['username']) ?></span></a>
        </li>
        <?php endforeach; ?>
    </ul>

    <section id="user-form">
        <h2><?= $editing ? 'Editar conta' : 'Nova conta' ?></h2>
        <form class="credentials user-form" method="post" action="?action=users<?= $editing ? '&amp;id=' . $editing : '' ?>#user-form">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <?php if ($error !== ''): ?><p class="error" role="alert"><?= escape($error) ?></p><?php endif; ?>
            <label>Nome <input name="name" value="<?= escape($values['name']) ?>" maxlength="150" required></label>
            <label>Email <input name="email" type="email" value="<?= escape($values['email']) ?>" maxlength="254" required></label>
            <label>Telemóvel <input name="phone" type="tel" value="<?= escape($values['phone']) ?>" maxlength="32" required></label>
            <label>Nome de utilizador <input name="username" value="<?= escape($values['username']) ?>" maxlength="64" autocomplete="off" required></label>
            <label>Perfil
                <select name="role" <?= $self ? 'disabled' : '' ?>>
                    <?php foreach ($roles as $key => $label): ?>
                    <option value="<?= $key ?>" <?= $values['role'] === $key ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Posição no clube
                <select name="club_position">
                    <?php foreach ($positions as $key => $label): ?>
                    <option value="<?= $key ?>" <?= $values['club_position'] === $key ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="check"><input type="checkbox" name="active" value="1" <?= $active ? 'checked' : '' ?> <?= $self ? 'disabled' : '' ?>> Conta ativa</label>
            <label><?= $editing ? 'Nova palavra-passe (opcional)' : 'Palavra-passe' ?>
                <input name="password" type="password" autocomplete="new-password" minlength="12" <?= $editing ? '' : 'required' ?>>
            </label>
            <div class="account-actions">
                <button class="primary">Guardar conta</button>
                <?php if ($editing): ?><a href="?action=users">Cancelar</a><?php endif; ?>
            </div>
        </form>
    </section>
</main>
</body>
</html>
