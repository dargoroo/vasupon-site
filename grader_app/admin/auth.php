<?php

require_once dirname(__DIR__) . '/bootstrap.php';

const GRADERAPP_ADMIN_SESSION_KEY = 'graderapp_admin_user';

function graderapp_admin_username(): string
{
    return (string) graderapp_config('GRADERAPP_ADMIN_USERNAME', 'admin');
}

function graderapp_admin_password(): string
{
    return (string) graderapp_config('GRADERAPP_ADMIN_PASSWORD', 'password');
}

function graderapp_admin_password_hash_from_db(?PDO $pdo = null): string
{
    if (!$pdo) {
        return '';
    }

    try {
        $hash = graderapp_setting_get($pdo, 'grader_admin_password_hash', '');
        return is_string($hash) ? $hash : '';
    } catch (Throwable $e) {
        return '';
    }
}

function graderapp_admin_verify_password(string $password, ?PDO $pdo = null): bool
{
    $dbHash = graderapp_admin_password_hash_from_db($pdo);
    if ($dbHash !== '') {
        return password_verify($password, $dbHash);
    }

    $configured = graderapp_admin_password();
    if (strpos($configured, '$2y$') === 0 || strpos($configured, '$argon2') === 0) {
        return password_verify($password, $configured);
    }

    return hash_equals($configured, $password);
}

function graderapp_admin_start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function graderapp_admin_is_authenticated(): bool
{
    graderapp_admin_start_session();
    return isset($_SESSION[GRADERAPP_ADMIN_SESSION_KEY]) && $_SESSION[GRADERAPP_ADMIN_SESSION_KEY] === graderapp_admin_username();
}

function graderapp_admin_login(string $username, string $password, ?PDO $pdo = null): bool
{
    graderapp_admin_start_session();

    if (hash_equals(graderapp_admin_username(), $username) && graderapp_admin_verify_password($password, $pdo)) {
        $_SESSION[GRADERAPP_ADMIN_SESSION_KEY] = graderapp_admin_username();
        $_SESSION['graderapp_admin_login_at'] = time();
        return true;
    }

    return false;
}

function graderapp_admin_logout(): void
{
    graderapp_admin_start_session();
    unset($_SESSION[GRADERAPP_ADMIN_SESSION_KEY], $_SESSION['graderapp_admin_login_at']);
}

function graderapp_admin_require_auth(): void
{
    if (!graderapp_admin_is_authenticated()) {
        header('Location: ' . graderapp_path('grader.admin'));
        exit;
    }
}

function graderapp_admin_flash(string $type, string $message): void
{
    graderapp_admin_start_session();
    $_SESSION['graderapp_admin_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function graderapp_admin_consume_flash(): ?array
{
    graderapp_admin_start_session();
    if (!isset($_SESSION['graderapp_admin_flash'])) {
        return null;
    }

    $flash = $_SESSION['graderapp_admin_flash'];
    unset($_SESSION['graderapp_admin_flash']);
    return $flash;
}
