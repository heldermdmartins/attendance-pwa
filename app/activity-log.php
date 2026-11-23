<?php
declare(strict_types=1);

function recordActivity(PDO $pdo, string $name, string $event): void
{
    $statement = $pdo->prepare('INSERT INTO activity_log (user_name, event_type, occurred_at) VALUES (?, ?, UTC_TIMESTAMP())');
    $statement->execute([$name, $event]);
}
