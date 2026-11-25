<?php
declare(strict_types=1);

const TRAINING_SCHEDULE = [
    1 => ['Segunda-feira', '19h00–20h00', 'Pavilhão Alvalade'],
    2 => ['Terça-feira', '17h15–18h15', 'Pavilhão do Sport Lisboa e Olivais'],
    5 => ['Sexta-feira', '17h30–18h30', 'Pavilhão do Sport Lisboa e Olivais'],
    6 => ['Sábado', '11h00–12h30', 'Pavilhão do Sport Lisboa e Olivais'],
];

function trainingSchedule(DateTimeImmutable $date): ?array
{
    return TRAINING_SCHEDULE[(int) $date->format('N')] ?? null;
}

function upcomingTrainings(DateTimeImmutable $today): array
{
    $trainings = [];
    for ($offset = 0; $offset < 14; $offset++) {
        $date = $today->modify("+$offset days");
        $training = trainingSchedule($date);
        if ($training !== null) {
            $trainings[$date->format('Y-m-d')] = $training[0] . ' · ' . $date->format('d/m/Y');
        }
    }
    return $trainings;
}

function trainingMonthLabel(string $month): string
{
    $names = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
    return $names[(int) substr($month, 5, 2) - 1] . ' de ' . substr($month, 0, 4);
}
