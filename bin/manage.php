<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../app/bootstrap.php';

$command = $argv[1] ?? '';
$value = trim($argv[2] ?? '');
if ($argc !== 3 || !in_array($command, ['coach', 'athlete'], true) || $value === '') {
    fwrite(STDERR, "Utilização: php bin/manage.php coach UTILIZADOR | athlete NOME\n");
    exit(1);
}

try {
    if ($command === 'coach') {
        if (!preg_match('/^[a-zA-Z0-9_.-]{1,64}$/D', $value)) {
            throw new InvalidArgumentException('Utilizador inválido.');
        }
        // Read from standard input so the password is never an argument or a file.
        $password = rtrim((string) fgets(STDIN, 256), "\r\n");
        if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new InvalidArgumentException('A palavra-passe deve ter entre 12 e 72 bytes.');
        }
        $statement = db()->prepare('INSERT INTO coaches (username, password_hash) VALUES (?, ?)');
        $statement->execute([$value, password_hash($password, PASSWORD_BCRYPT)]);
        echo "Conta criada.\n";
    } else {
        if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > 150) {
            throw new InvalidArgumentException('Nome inválido.');
        }
        $statement = db()->prepare('INSERT INTO athletes (name) VALUES (?)');
        $statement->execute([$value]);
        echo 'ID do atleta: ' . db()->lastInsertId() . PHP_EOL;
    }
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, "A operação falhou. Verifique a configuração e se o utilizador já existe.\n");
    exit(1);
}
