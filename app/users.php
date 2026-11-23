<?php
declare(strict_types=1);

function beginAccountChange(PDO $pdo, int $coach): void
{
    $pdo->beginTransaction();
    // Serialize account changes and recheck access after acquiring the lock.
    $admins = $pdo->query("SELECT id FROM coaches WHERE role='admin' AND active=1 ORDER BY id FOR UPDATE")
        ->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array($coach, array_map('intval', $admins), true)) {
        $pdo->rollBack();
        http_response_code(403);
        exit('Área reservada a administradores.');
    }
}

$roles = ['user' => 'Utilizador', 'admin' => 'Administrador'];
$positions = ['coach' => 'Treinador', 'delegate' => 'Delegado', 'admin' => 'Administrador'];
$fields = ['name', 'email', 'phone', 'role', 'club_position'];
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
$adding = ($_GET['view'] ?? '') === 'new' && !$editing;
$confirming = ($_GET['view'] ?? '') === 'delete' && $editing && !$self;

if ($method === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) {
        http_response_code(403);
        exit('Atualize a página e tente novamente.');
    }

    $operation = $_POST['operation'] ?? 'save';
    if (in_array($operation, ['activate', 'deactivate', 'delete'], true)) {
        if (!$editing || $self || ($operation === 'delete' && !$confirming)) {
            http_response_code(403);
            exit('Ação indisponível para esta conta.');
        }
        try {
            beginAccountChange($pdo, $coach);
            $query = $pdo->prepare($operation === 'delete'
                ? 'DELETE FROM coaches WHERE id = ?'
                : 'UPDATE coaches SET active = ? WHERE id = ?');
            $query->execute($operation === 'delete' ? [$editing] : [(int) ($operation === 'activate'), $editing]);
            $pdo->commit();
            $_SESSION['users_saved'] = match ($operation) {
                'activate' => 'Conta ativada.',
                'deactivate' => 'Conta desativada.',
                'delete' => 'Conta apagada.',
            };
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($operation !== 'delete' || ($exception->errorInfo[1] ?? null) !== 1451) {
                throw $exception;
            }
            $_SESSION['users_error'] = 'Esta conta tem presenças associadas. Podes desativá-la para preservar o histórico.';
        }
        header('Location: ?action=users', true, 303);
        exit;
    }
    if ($operation !== 'save' || $confirming) {
        http_response_code(400);
        exit('Ação inválida.');
    }
    $adding = !$editing;

    foreach ($fields as $field) {
        $values[$field] = is_string($_POST[$field] ?? null) ? trim($_POST[$field]) : '';
    }
    if ($self) {
        $values['role'] = 'admin';
    }
    $active = $self || ($_POST['active'] ?? '') === '1';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $validPassword = strlen($password) >= 12 && strlen($password) <= 72 && !str_contains($password, "\0");

    if ($values['name'] === '' || !mb_check_encoding($values['name'], 'UTF-8') || mb_strlen($values['name']) > 150) {
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
            beginAccountChange($pdo, $coach);
            $valuesToSave = array_map(static fn ($field) => $values[$field], $fields);
            array_unshift($valuesToSave, $values['email']);
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
            $_SESSION['users_saved'] = 'Conta guardada.';
            header('Location: ?action=users', true, 303);
            exit;
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (($exception->errorInfo[1] ?? null) !== 1062) {
                throw $exception;
            }
            $error = 'Já existe uma conta com esse email.';
        }
    }
    http_response_code(422);
} elseif ($method !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

$saved = $_SESSION['users_saved'] ?? '';
$saved = $saved === true ? 'Conta guardada.' : $saved;
$actionError = $_SESSION['users_error'] ?? '';
unset($_SESSION['users_saved'], $_SESSION['users_error']);
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
    <link rel="stylesheet" href="assets/styles.css?v=19">
    <script src="assets/app.js?v=2" defer></script>
</head>
<body>
<?php require __DIR__ . '/navigation.php'; ?>
<main>
    <header>
        <h1>Gestão de utilizadores</h1>
    </header>
    <?php if ($saved !== ''): ?><p role="status"><?= escape($saved) ?></p><?php endif; ?>
    <?php if ($actionError !== ''): ?><p class="error" role="alert"><?= escape($actionError) ?></p><?php endif; ?>

    <?php if ($confirming): ?>
    <section>
        <h2>Apagar utilizador</h2>
        <p>Queres apagar definitivamente a conta de <strong><?= escape($account['name'] ?: $account['username']) ?></strong>?</p>
        <form class="account-actions" method="post" action="?action=users&amp;id=<?= $editing ?>&amp;view=delete">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <button class="secondary danger" name="operation" value="delete">Confirmar eliminação</button>
            <a href="?action=users">Cancelar</a>
        </form>
    </section>
    <?php elseif (!$adding && !$editing): ?>
    <p><a class="primary user-add" href="?action=users&amp;view=new#user-form">Adicionar utilizador</a></p>
    <div class="users-table-scroll" role="region" aria-label="Lista de utilizadores" tabindex="0">
        <table class="users-table" role="table" aria-label="Utilizadores">
            <thead role="rowgroup">
                <tr role="row">
                    <th scope="col" role="columnheader">Nome</th>
                    <th scope="col" role="columnheader">Contactos</th>
                    <th scope="col" role="columnheader">Perfil</th>
                    <th scope="col" role="columnheader">Posição no clube</th>
                    <th scope="col" role="columnheader">Estado</th>
                    <th scope="col" role="columnheader">Ações</th>
                </tr>
            </thead>
            <tbody role="rowgroup">
                <?php foreach ($accounts as $person): ?>
                <?php $id = (int) $person['id']; $username = escape($person['username']); ?>
                <tr role="row">
                    <th scope="row" role="rowheader">
                        <?= escape($person['name'] ?: $person['username']) ?>
                        <?php if ($person['name'] !== '' && $person['email'] === ''): ?><small><?= $username ?></small><?php endif; ?>
                    </th>
                    <td role="cell">
                        <?= escape($person['email'] ?: '—') ?>
                        <?php if ($person['phone'] !== ''): ?><small><?= escape($person['phone']) ?></small><?php endif; ?>
                    </td>
                    <td role="cell"><span class="users-mobile-label" aria-hidden="true">Perfil</span><?= $roles[$person['role']] ?></td>
                    <td role="cell"><span class="users-mobile-label" aria-hidden="true">Posição</span><?= $positions[$person['club_position']] ?></td>
                    <td role="cell"><span class="users-mobile-label" aria-hidden="true">Estado</span><?= $person['active'] ? 'Ativa' : 'Inativa' ?></td>
                    <td role="cell">
                        <div class="account-actions">
                            <a href="?action=users&amp;id=<?= $id ?>#user-form">Editar<span class="visually-hidden"> <?= $username ?></span></a>
                            <?php if ($id !== $coach): ?>
                            <form method="post" action="?action=users&amp;id=<?= $id ?>">
                                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                <button class="secondary" name="operation" value="<?= $person['active'] ? 'deactivate' : 'activate' ?>">
                                    <?= $person['active'] ? 'Desativar' : 'Ativar' ?><span class="visually-hidden"> <?= $username ?></span>
                                </button>
                            </form>
                            <a class="danger" href="?action=users&amp;id=<?= $id ?>&amp;view=delete">Apagar<span class="visually-hidden"> <?= $username ?></span></a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <section id="user-form">
        <h2><?= $editing ? 'Editar conta' : 'Nova conta' ?></h2>
        <form class="credentials user-form" method="post" action="?action=users<?= $editing ? '&amp;id=' . $editing : '' ?>#user-form">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <?php if ($error !== ''): ?><p class="error" role="alert"><?= escape($error) ?></p><?php endif; ?>
            <label>Nome <input name="name" value="<?= escape($values['name']) ?>" maxlength="150" required></label>
            <label>Email de acesso <input name="email" type="email" value="<?= escape($values['email']) ?>" maxlength="254" required></label>
            <label>Telemóvel <input name="phone" type="tel" value="<?= escape($values['phone']) ?>" maxlength="32" required></label>
            <label>Perfil
                <span class="form-select">
                    <select name="role" <?= $self ? 'disabled' : '' ?>>
                        <?php foreach ($roles as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $values['role'] === $key ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                        <path d="m6 9 6 6 6-6"/>
                    </svg>
                </span>
            </label>
            <label>Posição no clube
                <span class="form-select">
                    <select name="club_position">
                        <?php foreach ($positions as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $values['club_position'] === $key ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                        <path d="m6 9 6 6 6-6"/>
                    </svg>
                </span>
            </label>
            <label class="check"><input type="checkbox" name="active" value="1" <?= $active ? 'checked' : '' ?> <?= $self ? 'disabled' : '' ?>> Conta ativa</label>
            <label><?= $editing ? 'Nova palavra-passe (opcional)' : 'Palavra-passe' ?>
                <input name="password" type="password" autocomplete="new-password" minlength="12" <?= $editing ? '' : 'required' ?>>
            </label>
            <div class="account-actions">
                <button class="primary">Guardar conta</button>
                <a href="?action=users">Cancelar</a>
            </div>
        </form>
    </section>
    <?php endif; ?>
</main>
<footer class="app-version">Versão <?= APP_VERSION ?></footer>
</body>
</html>
