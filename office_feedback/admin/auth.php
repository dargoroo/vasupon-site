<?php

require_once dirname(__DIR__) . '/bootstrap.php';

const OFFICEFB_ADMIN_SESSION_KEY = 'officefb_admin_user';

function officefb_admin_username()
{
    $configured = officefb_config('OFFICEFB_ADMIN_USERNAME', 'admin');
    return (string) $configured;
}

function officefb_admin_password()
{
    $configured = officefb_config('OFFICEFB_ADMIN_PASSWORD', 'password');
    return (string) $configured;
}

function officefb_admin_password_hash_from_db($pdo = null)
{
    if (!$pdo) {
        return '';
    }

    try {
        $hash = officefb_setting_get($pdo, 'officefb_admin_password_hash', '');
        return is_string($hash) ? $hash : '';
    } catch (Throwable $e) {
        return '';
    }
}

function officefb_admin_verify_password($password, $pdo = null)
{
    $password = (string) $password;
    $db_hash = officefb_admin_password_hash_from_db($pdo);

    if ($db_hash !== '') {
        return password_verify($password, $db_hash);
    }

    $configured = officefb_admin_password();
    if (strpos($configured, '$2y$') === 0 || strpos($configured, '$argon2') === 0) {
        return password_verify($password, $configured);
    }

    return hash_equals($configured, $password);
}

function officefb_admin_start_session()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function officefb_admin_is_authenticated()
{
    officefb_admin_start_session();
    return isset($_SESSION[OFFICEFB_ADMIN_SESSION_KEY]) && $_SESSION[OFFICEFB_ADMIN_SESSION_KEY] === officefb_admin_username();
}

function officefb_admin_login($username, $password, $pdo = null)
{
    officefb_admin_start_session();

    $username_ok = hash_equals(officefb_admin_username(), (string) $username);
    $password_ok = officefb_admin_verify_password($password, $pdo);

    if ($username_ok && $password_ok) {
        $_SESSION[OFFICEFB_ADMIN_SESSION_KEY] = officefb_admin_username();
        $_SESSION['officefb_admin_login_at'] = time();
        return true;
    }

    return false;
}

function officefb_admin_update_password($pdo, $new_password)
{
    $hash = password_hash((string) $new_password, PASSWORD_DEFAULT);
    return officefb_setting_set($pdo, 'officefb_admin_password_hash', $hash);
}

function officefb_admin_reset_password_override($pdo)
{
    return officefb_setting_delete($pdo, 'officefb_admin_password_hash');
}

function officefb_admin_logout()
{
    officefb_admin_start_session();
    unset($_SESSION[OFFICEFB_ADMIN_SESSION_KEY], $_SESSION['officefb_admin_login_at']);
}

function officefb_admin_require_auth()
{
    if (!officefb_admin_is_authenticated()) {
        header('Location: ' . officefb_path('admin.home'));
        exit;
    }
}

function officefb_admin_flash($type, $message)
{
    officefb_admin_start_session();
    $_SESSION['officefb_admin_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function officefb_admin_consume_flash()
{
    officefb_admin_start_session();
    if (!isset($_SESSION['officefb_admin_flash'])) {
        return null;
    }

    $flash = $_SESSION['officefb_admin_flash'];
    unset($_SESSION['officefb_admin_flash']);
    return $flash;
}
