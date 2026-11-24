<?php
declare(strict_types=1);

function recordActivity(PDO $pdo, string $name, string $event, ?string $athleteName = null, ?string $trainerName = null): void
{
    $statement = $pdo->prepare('INSERT INTO activity_log (user_name, event_type, athlete_name, trainer_name, occurred_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())');
    $statement->execute([$name, $event, $athleteName, $trainerName]);
}
