<?php

require_once dirname(__DIR__) . '/bootstrap.php';

const CPEPORTAL_ADMIN_SESSION_KEY = 'cpeportal_admin_user';

function cpeportal_admin_start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function cpeportal_admin_username(): string
{
    return (string) cpeportal_config('CPEPORTAL_ADMIN_USERNAME', 'admin');
}

function cpeportal_admin_password(): string
{
    return (string) cpeportal_config('CPEPORTAL_ADMIN_PASSWORD', 'password');
}

function cpeportal_admin_password_hash_from_db(?PDO $pdo = null): string
{
    if (!$pdo) {
        return '';
    }

    try {
        $hash = cpeportal_setting_get($pdo, 'cpeportal_admin_password_hash', '');
        return is_string($hash) ? $hash : '';
    } catch (Throwable $e) {
        return '';
    }
}

function cpeportal_admin_verify_password(string $password, ?PDO $pdo = null): bool
{
    $dbHash = cpeportal_admin_password_hash_from_db($pdo);
    if ($dbHash !== '') {
        return password_verify($password, $dbHash);
    }

    $configured = cpeportal_admin_password();
    if (strpos($configured, '$2y$') === 0 || strpos($configured, '$argon2') === 0) {
        return password_verify($password, $configured);
    }

    return hash_equals($configured, $password);
}

function cpeportal_admin_is_authenticated(): bool
{
    cpeportal_admin_start_session();
    return isset($_SESSION[CPEPORTAL_ADMIN_SESSION_KEY]) && $_SESSION[CPEPORTAL_ADMIN_SESSION_KEY] === cpeportal_admin_username();
}

function cpeportal_admin_login(string $username, string $password, ?PDO $pdo = null): bool
{
    cpeportal_admin_start_session();

    if (
        hash_equals(cpeportal_admin_username(), $username)
        && cpeportal_admin_verify_password($password, $pdo)
    ) {
        $_SESSION[CPEPORTAL_ADMIN_SESSION_KEY] = cpeportal_admin_username();
        return true;
    }

    return false;
}

function cpeportal_admin_logout(): void
{
    cpeportal_admin_start_session();
    unset($_SESSION[CPEPORTAL_ADMIN_SESSION_KEY]);
}

function cpeportal_admin_require_auth(): void
{
    if (!cpeportal_admin_is_authenticated()) {
        header('Location: ' . cpeportal_path('portal.admin'));
        exit;
    }
}

function cpeportal_admin_flash(string $type, string $message): void
{
    cpeportal_admin_start_session();
    $_SESSION['cpeportal_admin_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function cpeportal_admin_consume_flash(): ?array
{
    cpeportal_admin_start_session();
    if (!isset($_SESSION['cpeportal_admin_flash'])) {
        return null;
    }

    $flash = $_SESSION['cpeportal_admin_flash'];
    unset($_SESSION['cpeportal_admin_flash']);
    return $flash;
}
