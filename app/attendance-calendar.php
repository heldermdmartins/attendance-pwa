<?php
declare(strict_types=1);

$months = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
$monthLabel = $months[(int) $calendarMonth->format('n') - 1] . ' de ' . $calendarMonth->format('Y');
$firstWeekday = (int) $calendarMonth->format('N');
$dayCount = (int) $calendarMonth->format('t');
?>
<details class="attendance-calendar"<?= $history || isset($_GET['month']) ? ' open' : '' ?>>
    <summary>Consultar marcações anteriores</summary>
    <div class="calendar-navigation">
        <?php if ($calendarMonth->format('Y-m') > '1000-01'): ?>
            <a class="calendar-arrow" href="?date=<?= $selected ?>&amp;month=<?= $calendarMonth->modify('-1 month')->format('Y-m') ?>" aria-label="Mês anterior">‹</a>
        <?php else: ?>
            <span></span>
        <?php endif; ?>
        <strong><?= $monthLabel ?></strong>
        <?php if ($calendarMonth < $todayDate->modify('first day of this month')->setTime(0, 0)): ?>
            <a class="calendar-arrow" href="?date=<?= $selected ?>&amp;month=<?= $calendarMonth->modify('+1 month')->format('Y-m') ?>" aria-label="Mês seguinte">›</a>
        <?php else: ?>
            <span></span>
        <?php endif; ?>
    </div>
    <div class="calendar-days">
        <?php foreach (['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'] as $weekday): ?>
            <span class="calendar-weekday"><?= $weekday ?></span>
        <?php endforeach; ?>
        <?php for ($position = 1; $position < $firstWeekday; $position++): ?>
            <span aria-hidden="true"></span>
        <?php endfor; ?>
        <?php for ($day = 1; $day <= $dayCount; $day++): ?>
            <?php
            $date = $calendarMonth->format('Y-m-') . sprintf('%02d', $day);
            $marked = isset($markedDates[$date]);
            ?>
            <?php if ($date < $today): ?>
                <a class="calendar-day<?= $marked ? ' has-marks' : '' ?>" href="?date=<?= $date ?>"
                   aria-label="<?= sprintf('%02d/%s', $day, $calendarMonth->format('m/Y')) ?><?= $marked ? ', com marcações' : ', sem marcações' ?>"
                   <?= $selected === $date ? 'aria-current="date"' : '' ?>><?= $day ?></a>
            <?php else: ?>
                <span class="calendar-day unavailable"><?= $day ?></span>
            <?php endif; ?>
        <?php endfor; ?>
    </div>
    <p class="calendar-legend"><span aria-hidden="true"></span> Com marcações</p>
</details>
