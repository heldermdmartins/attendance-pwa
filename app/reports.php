<?php
declare(strict_types=1);

if ($method !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
session_write_close();

$query = db()->prepare(
    'SELECT training_date, SUM(athletes) AS athletes, SUM(trainers) AS trainers
     FROM (
        SELECT training_date, SUM(present = 1) AS athletes, 0 AS trainers
        FROM attendance WHERE training_date <= ? GROUP BY training_date
        UNION ALL
        SELECT training_date, 0 AS athletes, SUM(present = 1) AS trainers
        FROM coach_attendance WHERE training_date <= ? GROUP BY training_date
     ) AS totals
     GROUP BY training_date ORDER BY training_date DESC'
);
$query->execute([$today, $today]);
$months = [];
while ($day = $query->fetch(PDO::FETCH_ASSOC)) {
    $months[substr($day['training_date'], 0, 7)][] = $day;
}
$athleteQuery = db()->prepare(
    'SELECT totals.month, a.name, a.details, totals.total FROM (
        SELECT LEFT(training_date, 7) AS month, athlete_id, SUM(present = 1) AS total
        FROM attendance WHERE training_date <= ? GROUP BY LEFT(training_date, 7), athlete_id
     ) AS totals INNER JOIN athletes a ON a.id = totals.athlete_id ORDER BY a.name, a.id'
);
$athleteQuery->execute([$today]);
$athletesByMonth = [];
while ($athlete = $athleteQuery->fetch(PDO::FETCH_ASSOC)) {
    $athletesByMonth[$athlete['month']][] = $athlete;
}
?>
<!doctype html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Relatórios de presenças</title>
    <link rel="icon" type="image/png" sizes="192x192" href="assets/sporting-icon-192.png">
    <link rel="stylesheet" href="assets/styles.css?v=30">
    <script src="assets/app.js?v=4" defer></script>
</head>
<body>
<?php require __DIR__ . '/navigation.php'; ?>
<main>
    <h1>Relatórios de presenças</h1>
    <?php if (!$months): ?><p>Ainda não há marcações de presença.</p><?php endif; ?>
    <?php foreach ($months as $month => $days): ?>
        <details class="report-month"<?= $month === array_key_first($months) ? ' open' : '' ?>>
            <summary>
                <strong><?= trainingMonthLabel($month) ?></strong>
                <span><?= count($days) ?> <?= count($days) === 1 ? 'dia com registos' : 'dias com registos' ?></span>
            </summary>
            <div class="report-month-body">
                <p><strong>Presenças no mês</strong> · Atletas <?= array_sum(array_column($days, 'athletes')) ?>
                    · Treinadores <?= array_sum(array_column($days, 'trainers')) ?></p>
                <div class="users-table-scroll" role="region" aria-label="Presenças de <?= trainingMonthLabel($month) ?>" tabindex="0">
                    <table class="users-table" role="table" aria-label="Presenças por dia">
                        <thead role="rowgroup">
                            <tr role="row">
                                <th scope="col" role="columnheader">Data</th>
                                <th scope="col" role="columnheader">Atletas presentes</th>
                                <th scope="col" role="columnheader">Treinadores presentes</th>
                            </tr>
                        </thead>
                        <tbody role="rowgroup">
                            <?php foreach ($days as $day): ?>
                                <?php $date = new DateTimeImmutable($day['training_date']); ?>
                                <tr role="row">
                                    <th scope="row" role="rowheader">
                                        <a href="?date=<?= $date->format('Y-m-d') ?>">
                                            <time datetime="<?= $date->format('Y-m-d') ?>"><?= $date->format('d/m/Y') ?></time>
                                        </a>
                                    </th>
                                    <td role="cell"><span class="users-mobile-label" aria-hidden="true">Atletas presentes</span><?= (int) $day['athletes'] ?></td>
                                    <td role="cell"><span class="users-mobile-label" aria-hidden="true">Treinadores presentes</span><?= (int) $day['trainers'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (!empty($athletesByMonth[$month])): ?>
                    <?php $trainingWeeks = trainingWeeksInMonth(new DateTimeImmutable($month . '-01')); ?>
                    <h2>Limites mensais por atleta</h2>
                    <p><?= $trainingWeeks ?> semanas com treino previsto. Limites calculados pela frequência da turma na ficha do atleta.</p>
                    <div class="users-table-scroll" role="region" aria-label="Limites mensais por atleta" tabindex="0">
                        <table class="users-table" role="table" aria-label="Limites mensais">
                            <thead role="rowgroup">
                                <tr role="row">
                                    <th scope="col" role="columnheader">Atleta</th>
                                    <th scope="col" role="columnheader">Presenças</th>
                                    <th scope="col" role="columnheader">Limite</th>
                                    <th scope="col" role="columnheader">Estado</th>
                                </tr>
                            </thead>
                            <tbody role="rowgroup">
                                <?php foreach ($athletesByMonth[$month] as $athlete): ?>
                                    <?php
                                    $frequency = weeklyTrainingFrequency($athlete['details']);
                                    $limit = $frequency === null ? null : $frequency * $trainingWeeks;
                                    $excess = $limit === null ? 0 : max(0, (int) $athlete['total'] - $limit);
                                    ?>
                                    <tr role="row">
                                        <th scope="row" role="rowheader"><?= escape($athlete['name']) ?></th>
                                        <td role="cell"><span class="users-mobile-label" aria-hidden="true">Presenças</span><?= (int) $athlete['total'] ?></td>
                                        <td role="cell"><span class="users-mobile-label" aria-hidden="true">Limite</span><?= $limit ?? '—' ?></td>
                                        <td role="cell">
                                            <?php if ($excess): ?>
                                                <span class="monthly-usage is-over-limit">Limite ultrapassado (+<?= $excess ?>)</span>
                                            <?php else: ?>
                                                <?= $limit === null ? 'Frequência por definir' : 'Dentro do limite' ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </details>
    <?php endforeach; ?>
</main>
<footer class="app-version">Versão <?= APP_VERSION ?></footer>
</body>
</html>
