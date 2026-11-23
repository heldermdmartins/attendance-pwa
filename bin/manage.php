<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../app/bootstrap.php';

$command = $argv[1] ?? '';
$value = trim($argv[2] ?? '');
if ($argc !== 3 || !in_array($command, ['coach', 'admin', 'athlete', 'promote-admin'], true) || $value === '') {
    fwrite(STDERR, "Utilização: php bin/manage.php coach UTILIZADOR | admin UTILIZADOR | athlete NOME | promote-admin UTILIZADOR\n");
    exit(1);
}

try {
    if ($command === 'promote-admin') {
        $statement = db()->prepare("UPDATE coaches SET role='admin' WHERE username=? AND active=1 AND role='user'");
        $statement->execute([$value]);
        if ($statement->rowCount() !== 1) {
            throw new InvalidArgumentException('Indique uma conta ativa com o perfil Utilizador.');
        }
        echo "Perfil Administrador atribuído.\n";
    } elseif (in_array($command, ['coach', 'admin'], true)) {
        $email = strlen($value) <= 254 && filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
        if ($email === '' && !preg_match('/^[a-zA-Z0-9_.-]{1,64}$/D', $value)) {
            throw new InvalidArgumentException('Utilizador inválido.');
        }
        // Read from standard input so the password is never an argument or a file.
        $password = rtrim((string) fgets(STDIN, 256), "\r\n");
        if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new InvalidArgumentException('A palavra-passe deve ter entre 12 e 72 bytes.');
        }
        $role = $command === 'admin' ? 'admin' : 'user';
        $statement = db()->prepare('INSERT INTO coaches (username, password_hash, role, email) VALUES (?, ?, ?, ?)');
        $statement->execute([$value, password_hash($password, PASSWORD_BCRYPT), $role, $email]);
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
