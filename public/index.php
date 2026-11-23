<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function respond(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_THROW_ON_ERROR);
    exit;
}

function redirectHome(): never
{
    header('Location: ./', true, 303);
    exit;
}

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");

$route = $_GET['action'] ?? '';
try {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $local = PHP_SAPI === 'cli-server' && (config()['APP_LOCAL_HTTP'] ?? '') === '1'
        && in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'], true);
    if (!$https && !$local) {
        http_response_code(403);
        exit('A aplicação requer HTTPS.');
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('attendance_session');
    session_set_cookie_params(['path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    if (isset($_SESSION['coach']) && time() - $_SESSION['seen'] > 3600) {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    $csrf = $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $coach = $_SESSION['coach'] ?? null;
    $isAdmin = false;
    if ($coach !== null) {
        $query = db()->prepare('SELECT role FROM coaches WHERE id = ? AND active = 1');
        $query->execute([$coach]);
        $role = $query->fetchColumn();
        if (!$role) {
            $coach = null;
            unset($_SESSION['coach'], $_SESSION['seen']);
        } else {
            $isAdmin = $role === 'admin';
            $_SESSION['seen'] = time();
        }
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $today = date('Y-m-d');
    $error = '';
    if ($route === 'users') {
        if ($coach === null) {
            redirectHome();
        }
        if (!$isAdmin) {
            http_response_code(403);
            exit('Área reservada a administradores.');
        }
        require __DIR__ . '/../app/users.php';
        exit;
    }

    if ($route === 'attendance') {
        if ($method !== 'POST') {
            header('Allow: POST');
            respond(405, ['error' => 'Método inválido.']);
        }
        if ($coach === null) {
            respond(401, ['error' => 'Sessão expirada. Volte a entrar.']);
        }
        if (!hash_equals($csrf, $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
            respond(403, ['error' => 'Atualize a página e tente novamente.']);
        }
        session_write_close();

        $data = json_decode(file_get_contents('php://input', false, null, 0, 4096), true);
        if (!is_int($data['athlete'] ?? null) || $data['athlete'] < 1
            || !is_bool($data['present'] ?? null) || !is_string($data['date'] ?? null)) {
            respond(422, ['error' => 'Pedido inválido.']);
        }
        if ($data['date'] !== $today) {
            respond(409, ['error' => 'O dia mudou. Atualize a página.']);
        }

        $query = db()->prepare('SELECT id FROM athletes WHERE id = ? AND active = 1');
        $query->execute([$data['athlete']]);
        if (!$query->fetchColumn()) {
            respond(404, ['error' => 'Atleta indisponível.']);
        }
        $present = (int) $data['present'];
        $query = db()->prepare(
            'INSERT INTO attendance (athlete_id, training_date, present, coach_id) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE present = ?, coach_id = ?, updated_at = CURRENT_TIMESTAMP'
        );
        $query->execute([$data['athlete'], $today, $present, $coach, $present, $coach]);
        respond(200, ['present' => $data['present']]);
    }

    if ($route === 'photo' && $method === 'GET') {
        session_write_close();
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($coach === null || !$id) {
            http_response_code(404);
            exit;
        }
        $query = db()->prepare('SELECT id FROM athletes WHERE id = ? AND active = 1');
        $query->execute([$id]);
        $path = ROOT . "/storage/photos/$id.webp";
        if (!$query->fetchColumn() || !is_file($path) || !is_readable($path)) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: image/webp');
        readfile($path);
        exit;
    }

    if ($method === 'POST' && in_array($route, ['login', 'logout'], true)) {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) {
            http_response_code(403);
            exit('Atualize a página e tente novamente.');
        }
        if ($route === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            redirectHome();
        }
        if ($coach !== null) {
            redirectHome();
        }

        $pdo = db();
        $now = time();
        $address = inet_pton($_SERVER['REMOTE_ADDR']);
        $pdo->prepare('DELETE FROM login_limits WHERE expires_at <= ?')->execute([$now]);
        $query = $pdo->prepare(
            'INSERT INTO login_limits (address, attempts, expires_at) VALUES (?, 1, ?)
             ON DUPLICATE KEY UPDATE attempts = LEAST(attempts + 1, 11)'
        );
        $query->execute([$address, $now + 900]);
        $query = $pdo->prepare('SELECT attempts FROM login_limits WHERE address = ?');
        $query->execute([$address]);
        if ((int) $query->fetchColumn() > 10) {
            http_response_code(429);
            $error = 'Demasiadas tentativas. Volte a tentar dentro de alguns minutos.';
        } else {
            $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            $query = $pdo->prepare('SELECT id, password_hash FROM coaches WHERE username = ? AND active = 1');
            $query->execute([$username]);
            $account = $query->fetch(PDO::FETCH_ASSOC);
            // A fixed dummy hash makes unknown and existing usernames take similar time.
            $hash = $account['password_hash'] ?? '$2y$12$UNXb9XYBMdj0ZNnWyAFGYuM1.PPps3ed8eQqhBF3MRBFQ.OmTPdke';
            if (strlen($password) <= 72 && password_verify($password, $hash) && $account) {
                $pdo->prepare('DELETE FROM login_limits WHERE address = ?')->execute([$address]);
                session_regenerate_id(true);
                $_SESSION = ['coach' => (int) $account['id'], 'seen' => time()];
                redirectHome();
            }
            $error = 'Utilizador ou palavra-passe incorretos.';
        }
    } elseif ($method !== 'GET' || $route !== '') {
        http_response_code(404);
        exit;
    }

    $athletes = [];
    if ($coach !== null) {
        $query = db()->prepare(
            'SELECT a.id, a.name, COALESCE(r.present, 0) AS present FROM athletes a
             LEFT JOIN attendance r ON r.athlete_id = a.id AND r.training_date = ?
             WHERE a.active = 1 ORDER BY a.name, a.id'
        );
        $query->execute([$today]);
        $athletes = $query->fetchAll(PDO::FETCH_ASSOC);
    }
    session_write_close();
} catch (Throwable $exception) {
    error_log('Attendance request failed: ' . $exception::class . ' (' . $exception->getCode() . ')');
    if ($route === 'attendance') {
        respond(503, ['error' => 'Não foi possível confirmar a gravação.']);
    }
    http_response_code(503);
    exit('Serviço temporariamente indisponível.');
}
?>
<!doctype html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#163e36">
    <title>Registo de presenças</title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="apple-touch-icon" href="assets/icon-192.png">
    <link rel="stylesheet" href="assets/styles.css?v=5">
    <script src="assets/app.js" defer></script>
</head>
<body<?= $coach === null ? ' class="login-page"' : '' ?>>
<main class="<?= $coach === null ? 'login' : '' ?>">
    <header>
        <h1>Registo de presenças</h1>
        <?php if ($coach !== null): ?>
            <div class="account-actions">
            <?php if ($isAdmin): ?><a href="?action=users">Gerir utilizadores</a><?php endif; ?>
            <form method="post" action="?action=logout">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <button class="secondary">Sair</button>
            </form>
            </div>
        <?php endif; ?>
    </header>
    <?php if ($coach === null): ?>
        <form class="credentials" method="post" action="?action=login">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <?php if ($error !== ''): ?><p class="error" role="alert"><?= escape($error) ?></p><?php endif; ?>
            <label>Utilizador <input name="username" autocomplete="username" required maxlength="64"></label>
            <label>Palavra-passe <input name="password" type="password" autocomplete="current-password" required></label>
            <button class="primary">Entrar</button>
        </form>
    <?php else: ?>
        <div class="summary">
            <time datetime="<?= $today ?>"><?= date('d/m/Y') ?></time>
            <strong id="count"><?= array_sum(array_column($athletes, 'present')) ?> / <?= count($athletes) ?> presentes</strong>
        </div>
        <p class="feedback" id="feedback" role="status" aria-atomic="true"></p>
        <noscript><p>Ative o JavaScript para registar presenças.</p></noscript>
        <?php if (!$athletes): ?><p>Ainda não há atletas ativos.</p><?php endif; ?>
        <ul class="grid" id="roster" data-csrf="<?= $csrf ?>" data-date="<?= $today ?>">
            <?php foreach ($athletes as $athlete): ?>
                <?php $id = (int) $athlete['id']; $present = (bool) $athlete['present']; ?>
                <li>
                    <button type="button" class="athlete" data-id="<?= $id ?>" aria-pressed="<?= $present ? 'true' : 'false' ?>">
                        <span class="portrait" aria-hidden="true">
                            <?php if (is_file(ROOT . "/storage/photos/$id.webp")): ?>
                                <img src="?action=photo&amp;id=<?= $id ?>" alt="" loading="lazy">
                            <?php endif; ?>
                        </span>
                        <span class="name" id="athlete-<?= $id ?>"><?= escape($athlete['name']) ?></span>
                        <span class="state" aria-hidden="true"><?= $present ? '✓ Presente' : 'Por marcar' ?></span>
                    </button>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</main>
</body>
</html>
