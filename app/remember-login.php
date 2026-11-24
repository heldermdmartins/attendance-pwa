<?php
declare(strict_types=1);

const REMEMBER_COOKIE = 'attendance_remember';
const REMEMBER_DAYS = 30;

function rememberedTokenHash(): ?string
{
    $token = $_COOKIE[REMEMBER_COOKIE] ?? null;
    return is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) ? hash('sha256', $token) : null;
}

function writeRememberCookie(string $token, int $expires, bool $secure): void
{
    setcookie(REMEMBER_COOKIE, $token, [
        'expires' => $expires,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function forgetRememberedLogin(bool $secure): void
{
    if (!isset($_COOKIE[REMEMBER_COOKIE])) {
        return;
    }
    $hash = rememberedTokenHash();
    if ($hash !== null) {
        db()->prepare('DELETE FROM remembered_logins WHERE token_hash = ?')->execute([$hash]);
    }
    writeRememberCookie('', time() - 3600, $secure);
}

function rememberLogin(int $coach, string $credential, bool $secure): void
{
    $token = bin2hex(random_bytes(32));
    $expires = time() + REMEMBER_DAYS * 86400;
    db()->exec('DELETE FROM remembered_logins WHERE expires_at <= UTC_TIMESTAMP()');
    $statement = db()->prepare('INSERT INTO remembered_logins (token_hash, coach_id, credential, expires_at) VALUES (?, ?, ?, ?)');
    $statement->execute([hash('sha256', $token), $coach, $credential, gmdate('Y-m-d H:i:s', $expires)]);
    writeRememberCookie($token, $expires, $secure);
}

function restoreRememberedLogin(bool $secure): void
{
    if (!isset($_COOKIE[REMEMBER_COOKIE])) {
        return;
    }
    $hash = rememberedTokenHash();
    if ($hash !== null) {
        $query = db()->prepare(
            'SELECT c.id, c.password_hash, r.credential FROM remembered_logins r
             INNER JOIN coaches c ON c.id = r.coach_id
             WHERE r.token_hash = ? AND r.expires_at > UTC_TIMESTAMP() AND c.active = 1'
        );
        $query->execute([$hash]);
        $account = $query->fetch(PDO::FETCH_ASSOC);
        if ($account && hash_equals(hash('sha256', $account['password_hash']), $account['credential'])) {
            session_regenerate_id(true);
            $_SESSION['coach'] = (int) $account['id'];
            $_SESSION['seen'] = time();
            $_SESSION['credential'] = $account['credential'];
            return;
        }
    }
    forgetRememberedLogin($secure);
}
