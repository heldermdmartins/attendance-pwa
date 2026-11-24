<?php
declare(strict_types=1);

if ($method !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
session_write_close();

$before = filter_input(INPUT_GET, 'before', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (isset($_GET['before']) && !$before) {
    http_response_code(400);
    exit('Página inválida.');
}
$pdo = db();
if ($before) {
    $query = $pdo->prepare('SELECT id, user_name, event_type, athlete_name, trainer_name, occurred_at FROM activity_log WHERE id < ? ORDER BY id DESC LIMIT 51');
    $query->execute([$before]);
} else {
    $query = $pdo->query('SELECT id, user_name, event_type, athlete_name, trainer_name, occurred_at FROM activity_log ORDER BY id DESC LIMIT 51');
}
$entries = $query->fetchAll(PDO::FETCH_ASSOC);
$more = count($entries) > 50;
if ($more) {
    array_pop($entries);
}
$labels = ['login' => 'Início de sessão', 'attendance_marked' => 'Presença marcada', 'attendance_unmarked' => 'Presença desmarcada'];
$utc = new DateTimeZone('UTC');
$lisbon = new DateTimeZone('Europe/Lisbon');
?>
<!doctype html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registo de atividade</title>
    <link rel="stylesheet" href="assets/styles.css?v=20">
    <script src="assets/app.js?v=3" defer></script>
</head>
<body>
<?php require __DIR__ . '/navigation.php'; ?>
<main>
    <h1>Registo de atividade</h1>
    <?php if ($entries): ?>
        <p>Horas de Lisboa</p>
        <div class="users-table-scroll" role="region" aria-label="Atividade da aplicação" tabindex="0">
            <table class="users-table" role="table" aria-label="Atividade">
                <thead role="rowgroup">
                    <tr role="row">
                        <th scope="col" role="columnheader">Utilizador</th>
                        <th scope="col" role="columnheader">Atividade</th>
                        <th scope="col" role="columnheader">Atleta ou treinador</th>
                        <th scope="col" role="columnheader">Data e hora</th>
                    </tr>
                </thead>
                <tbody role="rowgroup">
                    <?php foreach ($entries as $entry): ?>
                        <?php $time = (new DateTimeImmutable($entry['occurred_at'], $utc))->setTimezone($lisbon); ?>
                        <tr role="row">
                            <th scope="row" role="rowheader"><?= escape($entry['user_name']) ?></th>
                            <td role="cell"><?= escape($labels[$entry['event_type']]) ?></td>
                            <td role="cell"><?= escape($entry['athlete_name'] ?? $entry['trainer_name'] ?? '—') ?></td>
                            <td role="cell"><time datetime="<?= $time->format(DATE_ATOM) ?>"><?= $time->format('d/m/Y H:i:s') ?></time></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p>Sem atividade registada nesta página.</p>
    <?php endif; ?>
    <p class="account-actions">
        <?php if ($before): ?><a href="?action=activity">Atividade mais recente</a><?php endif; ?>
        <?php if ($more): ?><a href="?action=activity&amp;before=<?= (int) end($entries)['id'] ?>">Ver atividade anterior</a><?php endif; ?>
    </p>
</main>
<footer class="app-version">Versão <?= APP_VERSION ?></footer>
</body>
</html>
