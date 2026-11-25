<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/athlete-profile.php';
require __DIR__ . '/../app/profile-photo.php';
require __DIR__ . '/../app/remember-login.php';
require __DIR__ . '/../app/training-schedule.php';
require __DIR__ . '/../app/attendance-limit.php';
require __DIR__ . '/../app/activity-log.php';

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
        unset($_SESSION['coach'], $_SESSION['seen'], $_SESSION['credential']);
        session_regenerate_id(true);
    }
    if (!isset($_SESSION['coach']) && !in_array($route, ['login', 'logout'], true)) {
        restoreRememberedLogin($https);
    }

    $csrf = $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $coach = $_SESSION['coach'] ?? null;
    $isAdmin = false;
    $canViewAthletes = false;
    if ($coach !== null) {
        $query = db()->prepare('SELECT role, club_position, name, username, email, phone, password_hash FROM coaches WHERE id = ? AND active = 1');
        $query->execute([$coach]);
        $currentAccount = $query->fetch(PDO::FETCH_ASSOC);
        if (!$currentAccount || !hash_equals(hash('sha256', $currentAccount['password_hash']), $_SESSION['credential'] ?? '')) {
            $coach = null;
            $_SESSION = ['csrf' => $csrf];
            session_regenerate_id(true);
        } else {
            $isAdmin = $currentAccount['role'] === 'admin';
            $canViewAthletes = $isAdmin || $currentAccount['club_position'] === 'coach';
            $_SESSION['seen'] = time();
        }
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $todayDate = new DateTimeImmutable('today');
    $today = $todayDate->format('Y-m-d');
    $error = '';
    if (in_array($route, ['users', 'athletes', 'activity', 'profile', 'reports'], true)) {
        if ($coach === null) {
            redirectHome();
        }
        if (!in_array($route, ['profile', 'reports'], true) && !$isAdmin && ($route !== 'athletes' || !$canViewAthletes || $method !== 'GET' || isset($_GET['view']))) {
            http_response_code(403);
            exit('Não tens permissão para esta ação.');
        }
        require __DIR__ . '/../app/' . $route . '.php';
        exit;
    }

    if ($route === 'training-status' && $method === 'POST') {
        if (!$isAdmin || !is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) {
            http_response_code(403);
            exit('Ação reservada a administradores.');
        }

        $date = $_POST['date'] ?? '';
        if (!is_string($date) || !isset(upcomingTrainings($todayDate)[$date])) {
            http_response_code(422);
            exit('Escolhe um treino de hoje ou da lista de próximos treinos.');
        }
        $cancelled = ($_POST['cancelled'] ?? '') === '1';
        $reason = is_string($_POST['reason'] ?? null) ? trim($_POST['reason']) : '';
        if ($cancelled && ($reason === '' || !mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason) > 500)) {
            $_SESSION['training_error'] = 'Indica um motivo de cancelamento com até 500 caracteres.';
            $_SESSION['training_cancel_draft'] = $date;
        } else {
            $statement = db()->prepare(
                'INSERT INTO training_status (training_date, cancellation_reason) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE cancellation_reason = ?'
            );
            $reason = $cancelled ? $reason : null;
            $statement->execute([$date, $reason, $reason]);
            $_SESSION['training_message'] = $cancelled ? 'Treino cancelado.' : 'Treino reativado.';
        }
        header('Location: ./?date=' . $date, true, 303);
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
        $kind = $data['kind'] ?? 'athlete';
        $personId = $data['id'] ?? $data['athlete'] ?? null;
        if (!in_array($kind, ['athlete', 'trainer'], true) || !is_int($personId) || $personId < 1
            || !is_bool($data['present'] ?? null) || !is_string($data['date'] ?? null)) {
            respond(422, ['error' => 'Pedido inválido.']);
        }
        if ($data['date'] !== $today || trainingSchedule($todayDate) === null) {
            respond(409, ['error' => 'Só é possível marcar presenças no dia de um treino.']);
        }

        $isTrainer = $kind === 'trainer';
        $table = $isTrainer ? 'coach_attendance' : 'attendance';
        $personColumn = $isTrainer ? 'trainer_id' : 'athlete_id';
        $pdo = db();
        $pdo->beginTransaction();
        // Serialize cancellation and attendance writes for the selected day.
        $query = $pdo->prepare(
            'INSERT INTO training_status (training_date) VALUES (?) ON DUPLICATE KEY UPDATE training_date = training_date'
        );
        $query->execute([$today]);
        $query = $pdo->prepare('SELECT cancellation_reason FROM training_status WHERE training_date = ? FOR UPDATE');
        $query->execute([$today]);
        if ($query->fetchColumn() !== null) {
            $pdo->rollBack();
            respond(409, ['error' => 'O treino foi cancelado. Atualiza a página para veres o motivo.']);
        }
        $query = $pdo->prepare($isTrainer
            ? "SELECT COALESCE(NULLIF(name, ''), CONCAT('Treinador #', id)) AS name FROM coaches
               WHERE id = ? AND active = 1 AND club_position = 'coach' FOR UPDATE"
            : 'SELECT name, details FROM athletes WHERE id = ? AND active = 1 FOR UPDATE');
        $query->execute([$personId]);
        $participant = $query->fetch(PDO::FETCH_ASSOC);
        if (!$participant) {
            $pdo->rollBack();
            respond(404, ['error' => $isTrainer ? 'Treinador indisponível.' : 'Atleta indisponível.']);
        }

        $present = (int) $data['present'];
        $previous = $pdo->prepare("SELECT present FROM $table WHERE $personColumn = ? AND training_date = ?");
        $previous->execute([$personId, $today]);
        $previousPresent = $previous->fetchColumn();
        if ($previousPresent === false || (int) $previousPresent !== $present) {
            $query = $pdo->prepare(
                "INSERT INTO $table ($personColumn, training_date, present, coach_id) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE present = ?, coach_id = ?, updated_at = CURRENT_TIMESTAMP"
            );
            $query->execute([$personId, $today, $present, $coach, $present, $coach]);
            if (!$isTrainer) {
                $snapshot = $pdo->prepare(
                    'UPDATE training_status SET athlete_roster_count = (SELECT COUNT(*) FROM athletes WHERE active = 1)
                     WHERE training_date = ? AND athlete_roster_count IS NULL'
                );
                $snapshot->execute([$today]);
            }
            recordActivity($pdo, $currentAccount['name'] ?: $currentAccount['username'],
                $present ? 'attendance_marked' : 'attendance_unmarked',
                $isTrainer ? null : $participant['name'], $isTrainer ? $participant['name'] : null);
        }
        $result = ['present' => $data['present']];
        if (!$isTrainer) {
            $frequency = weeklyTrainingFrequency($participant['details']);
            $result['monthly'] = (int) (monthlyAthletePresences($pdo, $todayDate, $personId)[$personId] ?? 0);
            $result['limit'] = $frequency === null ? null : $frequency * trainingWeeksInMonth($todayDate);
        }
        $pdo->commit();
        respond(200, $result);
    }

    if (in_array($route, ['user-photo', 'trainer-photo'], true) && $method === 'GET') {
        session_write_close();
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($coach === null || ($route === 'user-photo' && !$isAdmin && $id !== $coach) || !$id || !is_readable(userPhotoPath($id))) {
            http_response_code(404);
            exit;
        }
        $query = db()->prepare($route === 'trainer-photo'
            ? "SELECT id FROM coaches WHERE id = ? AND active = 1 AND club_position = 'coach'"
            : 'SELECT id FROM coaches WHERE id = ?');
        $query->execute([$id]);
        if (!$query->fetchColumn()) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: image/webp');
        readfile(userPhotoPath($id));
        exit;
    }

    if ($route === 'photo' && $method === 'GET') {
        session_write_close();
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($coach === null || !$id) {
            http_response_code(404);
            exit;
        }
        $query = db()->prepare('SELECT id FROM athletes WHERE id = ? AND (active = 1 OR ? = 1)');
        $query->execute([$id, (int) $isAdmin]);
        $path = athletePhotoPath($id);
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
            forgetRememberedLogin($https);
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
            $query = $pdo->prepare('SELECT id, name, username, password_hash FROM coaches WHERE username = ? AND active = 1');
            $query->execute([$username]);
            $account = $query->fetch(PDO::FETCH_ASSOC);
            // A fixed dummy hash makes unknown and existing usernames take similar time.
            $hash = $account['password_hash'] ?? '$2y$12$UNXb9XYBMdj0ZNnWyAFGYuM1.PPps3ed8eQqhBF3MRBFQ.OmTPdke';
            if (strlen($password) <= 72 && password_verify($password, $hash) && $account) {
                $pdo->prepare('DELETE FROM login_limits WHERE address = ?')->execute([$address]);
                recordActivity($pdo, $account['name'] ?: $account['username'], 'login');
                forgetRememberedLogin($https);
                $credential = hash('sha256', $account['password_hash']);
                if (($_POST['remember'] ?? '') === '1') {
                    rememberLogin((int) $account['id'], $credential, $https);
                }
                session_regenerate_id(true);
                $_SESSION = ['coach' => (int) $account['id'], 'seen' => time(), 'credential' => $credential];
                redirectHome();
            }
            $error = 'Utilizador ou palavra-passe incorretos.';
        }
    } elseif ($method !== 'GET' || $route !== '') {
        http_response_code(404);
        exit;
    }

    $athletes = [];
    $trainers = [];
    if ($coach !== null) {
        $upcoming = upcomingTrainings($todayDate);
        $selected = array_key_first($upcoming);
        $requested = $_GET['date'] ?? null;
        if ($requested !== null) {
            $parsed = is_string($requested) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $requested)
                ? DateTimeImmutable::createFromFormat('!Y-m-d', $requested) : false;
            if ($parsed && $parsed->format('Y-m-d') === $requested
                && ($requested < $today || isset($upcoming[$requested]))) {
                $selected = $requested;
            } else {
                $error = 'Escolhe um treino da lista ou uma data anterior.';
            }
        }
        $selectedDate = new DateTimeImmutable($selected);
        $training = trainingSchedule($selectedDate);
        $history = $selected < $today;
        $calendarMonth = ($history ? $selectedDate : $todayDate)->modify('first day of this month');
        $month = $_GET['month'] ?? null;
        if (is_string($month) && preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $month)
            && $month >= '1000-01' && $month <= $todayDate->format('Y-m')) {
            $calendarMonth = new DateTimeImmutable($month . '-01');
        }
        $firstDay = $calendarMonth->format('Y-m-d');
        $end = min($today, $calendarMonth->modify('+1 month')->format('Y-m-d'));
        $query = db()->prepare(
            'SELECT training_date FROM attendance WHERE training_date >= ? AND training_date < ?
             UNION
             SELECT training_date FROM coach_attendance WHERE training_date >= ? AND training_date < ?'
        );
        $query->execute([$firstDay, $end, $firstDay, $end]);
        $markedDates = array_fill_keys($query->fetchAll(PDO::FETCH_COLUMN), true);
        $status = db()->prepare('SELECT cancellation_reason, athlete_roster_count FROM training_status WHERE training_date = ?');
        $status->execute([$selected]);
        $trainingStatus = $status->fetch(PDO::FETCH_ASSOC);
        $cancellationReason = $trainingStatus['cancellation_reason'] ?? null;
        $athleteRosterCount = $trainingStatus['athlete_roster_count'] ?? null;
        $cancelled = $cancellationReason !== null;
        $canMark = !$cancelled && $selected === $today && $training !== null;
        $readOnlyRecords = $history || $cancelled;
        $trainingError = $_SESSION['training_error'] ?? '';
        $trainingMessage = $_SESSION['training_message'] ?? '';
        $showCancellationForm = $cancelled || ($_SESSION['training_cancel_draft'] ?? '') === $selected;
        unset($_SESSION['training_error'], $_SESSION['training_message'], $_SESSION['training_cancel_draft']);
        if ($readOnlyRecords || $canMark) {
            $query = db()->prepare($readOnlyRecords
                ? 'SELECT a.id, a.name, a.details, r.present FROM attendance r
                   INNER JOIN athletes a ON a.id = r.athlete_id
                   WHERE r.training_date = ? AND r.present = 1 ORDER BY a.name, a.id'
                : 'SELECT a.id, a.name, a.details, COALESCE(r.present, 0) AS present FROM athletes a
                   LEFT JOIN attendance r ON r.athlete_id = a.id AND r.training_date = ?
                   WHERE a.active = 1 ORDER BY a.name, a.id');
            $query->execute([$selected]);
            $athletes = $query->fetchAll(PDO::FETCH_ASSOC);
            $monthlyPresences = monthlyAthletePresences(db(), $selectedDate);
            $trainingWeeks = trainingWeeksInMonth($selectedDate);

            $query = db()->prepare($readOnlyRecords
                ? "SELECT c.id, COALESCE(NULLIF(c.name, ''), CONCAT('Treinador #', c.id)) AS name, r.present,
                          (c.active = 1 AND c.club_position = 'coach') AS photo_allowed
                   FROM coach_attendance r INNER JOIN coaches c ON c.id = r.trainer_id
                   WHERE r.training_date = ? ORDER BY name, c.id"
                : "SELECT c.id, COALESCE(NULLIF(c.name, ''), CONCAT('Treinador #', c.id)) AS name,
                          COALESCE(r.present, 0) AS present, 1 AS photo_allowed
                   FROM coaches c LEFT JOIN coach_attendance r ON r.trainer_id = c.id AND r.training_date = ?
                   WHERE c.active = 1 AND c.club_position = 'coach' ORDER BY name, c.id");
            $query->execute([$selected]);
            $trainers = $query->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    session_write_close();
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
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
    <link rel="manifest" href="manifest.webmanifest?v=2">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/sporting-icon-180.png">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/sporting-icon-192.png">
    <link rel="stylesheet" href="assets/styles.css?v=30">
    <script src="assets/app.js?v=6" defer></script>
</head>
<body<?= $coach === null ? ' class="login-page"' : '' ?>>
<?php if ($coach !== null) require __DIR__ . '/../app/navigation.php'; ?>
<main class="<?= $coach === null ? 'login' : '' ?>">
    <header>
        <h1>Registo de presenças</h1>
    </header>
    <?php if ($coach === null): ?>
        <form class="credentials" method="post" action="?action=login">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <?php if ($error !== ''): ?><p class="error" role="alert"><?= escape($error) ?></p><?php endif; ?>
            <label>Email <input name="username" autocomplete="username" inputmode="email" autocapitalize="none" spellcheck="false" required maxlength="254"></label>
            <label>Palavra-passe <input name="password" type="password" autocomplete="current-password" required></label>
            <label class="login-remember">
                <input type="checkbox" name="remember" value="1" <?= ($_POST['remember'] ?? '') === '1' ? 'checked' : '' ?>>
                Manter sessão iniciada
            </label>
            <button class="primary">Entrar</button>
        </form>
    <?php else: ?>
        <section class="training-controls" aria-label="Calendário de treinos">
            <form method="get">
                <label>Treino
                    <span class="form-select">
                        <select name="date">
                            <?php if ($history): ?>
                                <option value="<?= $selected ?>" selected><?= $selectedDate->format('d/m/Y') ?> (histórico)</option>
                            <?php endif; ?>
                            <?php foreach ($upcoming as $date => $label): ?>
                                <option value="<?= $date ?>" <?= $date === $selected ? 'selected' : '' ?>><?= escape($label) ?><?= $date === $today ? ' (hoje)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                            <path d="m6 9 6 6 6-6"/>
                        </svg>
                    </span>
                </label>
                <button class="secondary">Ver treino</button>
            </form>
            <?php require __DIR__ . '/../app/attendance-calendar.php'; ?>
        </section>
        <?php if ($error !== ''): ?><p class="error" role="alert"><?= escape($error) ?></p><?php endif; ?>
        <div class="summary attendance-summary">
            <div class="training-meta">
                <time datetime="<?= $selected ?>"><?= $selectedDate->format('d/m/Y') ?></time>
                <?php if ($training): ?>
                    <span><?= escape($training[1]) ?></span>
                    <span><?= escape($training[2]) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($cancelled): ?>
            <p class="training-cancelled"><strong>Treino cancelado.</strong> <?= escape($cancellationReason) ?></p>
        <?php endif; ?>
        <?php if ($trainingMessage !== ''): ?><p role="status"><?= escape($trainingMessage) ?></p><?php endif; ?>
        <?php if ($trainingError !== ''): ?><p class="error" role="alert"><?= escape($trainingError) ?></p><?php endif; ?>
        <?php if ($isAdmin && !$history): ?>
            <form class="training-cancellation" id="training-cancellation" method="post" action="?action=training-status">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="date" value="<?= $selected ?>">
                <label class="check">
                    <input type="checkbox" name="cancelled" value="1" aria-controls="training-reason" <?= $showCancellationForm ? 'checked' : '' ?>>
                    Cancelar treino
                </label>
                <label id="training-reason" for="cancellation-reason">Motivo do cancelamento
                    <textarea id="cancellation-reason" name="reason" maxlength="500" rows="3"><?= $cancelled ? escape($cancellationReason) : '' ?></textarea>
                </label>
                <button class="secondary">Guardar estado do treino</button>
            </form>
        <?php endif; ?>
        <?php if ($canMark): ?>
            <p class="feedback" id="feedback" role="status" aria-atomic="true"></p>
            <noscript><p>Ative o JavaScript para registar presenças.</p></noscript>
        <?php elseif ($history): ?>
            <p><?= $athletes || $trainers ? 'Histórico de presenças' : 'Sem presenças visíveis nesta data.' ?></p>
        <?php elseif (!$cancelled): ?>
            <p>As marcações abrem no dia do treino.</p>
        <?php endif; ?>
        <?php foreach (['trainer' => ['Treinadores', $trainers], 'athlete' => ['Atletas', $athletes]] as $kind => [$label, $participants]): ?>
            <?php if (!$canMark && !$participants) continue; ?>
            <section class="attendance-group" aria-labelledby="attendance-<?= $kind ?>">
                <header>
                    <h2 id="attendance-<?= $kind ?>"><?= $label ?></h2>
                    <?php if ($participants): ?>
                        <?php $total = !$canMark && $kind === 'athlete' && $athleteRosterCount !== null
                            ? max((int) $athleteRosterCount, count($participants)) : count($participants); ?>
                        <?php $presentCount = array_sum(array_column($participants, 'present')); ?>
                        <strong data-attendance-count><?php if (!$canMark && $kind === 'athlete' && $athleteRosterCount === null): ?>
                            <?= $presentCount ?> <?= $presentCount === 1 ? 'presente' : 'presentes' ?>
                        <?php else: ?>
                            <?= $presentCount ?>/<?= $total ?> presentes
                        <?php endif; ?></strong>
                    <?php endif; ?>
                </header>
                <?php if (!$participants): ?>
                    <p><?= $kind === 'trainer' ? 'Ainda não há treinadores ativos.' : 'Ainda não há atletas ativos.' ?></p>
                <?php else: ?>
                <ul class="grid"<?= $canMark ? ' data-roster="' . $kind . '" data-csrf="' . $csrf . '" data-date="' . $selected . '"' : '' ?>>
                    <?php foreach ($participants as $person): ?>
                        <?php
                        $id = (int) $person['id'];
                        $present = (bool) $person['present'];
                        $photo = $kind === 'trainer' ? userPhotoPath($id) : athletePhotoPath($id);
                        $photoAction = $kind === 'trainer' ? 'trainer-photo' : 'photo';
                        $frequency = $kind === 'athlete' ? weeklyTrainingFrequency($person['details']) : null;
                        $limit = $frequency === null ? null : $frequency * $trainingWeeks;
                        $monthly = $kind === 'athlete' ? (int) ($monthlyPresences[$id] ?? 0) : 0;
                        $overLimit = $limit !== null && $monthly > $limit;
                        ?>
                        <li>
                            <?php if ($canMark): ?>
                            <button type="button" class="athlete" data-id="<?= $id ?>" aria-pressed="<?= $present ? 'true' : 'false' ?>">
                            <?php else: ?>
                            <div class="athlete history-card">
                            <?php endif; ?>
                                <span class="portrait" aria-hidden="true">
                                    <?= escape(nameInitials($person['name'])) ?>
                                    <?php if (($person['photo_allowed'] ?? true) && is_readable($photo)): ?>
                                        <img src="?action=<?= $photoAction ?>&amp;id=<?= $id ?>" alt="" loading="lazy">
                                    <?php endif; ?>
                                </span>
                                <span class="name"><?= escape($person['name']) ?></span>
                                <span class="state"<?= $canMark ? ' aria-hidden="true"' : '' ?>>
                                    <?= $present ? '✓ Presente' : ($readOnlyRecords ? 'Desmarcada' : 'Por marcar') ?>
                                </span>
                                <?php if ($kind === 'athlete'): ?>
                                    <span class="monthly-usage<?= $overLimit ? ' is-over-limit' : '' ?>" data-monthly-usage<?= $limit === null ? ' hidden' : '' ?>>
                                        <?= $limit === null ? '' : "$monthly/$limit no mês" ?><?= $overLimit ? ' · Limite ultrapassado' : '' ?>
                                    </span>
                                <?php endif; ?>
                            <?= $canMark ? '</button>' : '</div>' ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</main>
<footer class="app-version">Versão <?= APP_VERSION ?></footer>
</body>
</html>
