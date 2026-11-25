<?php
declare(strict_types=1);

function weeklyTrainingFrequency(?string $details): ?int
{
    if ($details === null) {
        return null;
    }
    $class = json_decode($details, true, 512, JSON_THROW_ON_ERROR)['class'] ?? '';
    if (preg_match('/\b([123])\s*(?:x|×|vez(?:es)?)\s*(?:(?:por|na|à)\s+)?semana\b/iu', $class, $matches)) {
        return (int) $matches[1];
    }
    return null;
}

function monthlyAthletePresences(PDO $pdo, DateTimeImmutable $date, ?int $athleteId = null): array
{
    $month = $date->modify('first day of this month');
    $query = $pdo->prepare(
        'SELECT athlete_id, COUNT(*) AS total FROM attendance
         WHERE present = 1 AND training_date >= ? AND training_date < ?'
         . ($athleteId !== null ? ' AND athlete_id = ?' : '') . ' GROUP BY athlete_id'
    );
    $params = [$month->format('Y-m-d'), $month->modify('+1 month')->format('Y-m-d')];
    if ($athleteId !== null) {
        $params[] = $athleteId;
    }
    $query->execute($params);
    return $query->fetchAll(PDO::FETCH_KEY_PAIR);
}

function trainingWeeksInMonth(DateTimeImmutable $date): int
{
    $day = $date->modify('first day of this month');
    $end = $day->modify('+1 month');
    $weeks = [];
    for (; $day < $end; $day = $day->modify('+1 day')) {
        if (trainingSchedule($day) !== null) {
            $weeks[$day->format('o-W')] = true;
        }
    }
    return count($weeks);
}
