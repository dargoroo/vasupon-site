<?php

function cpeapp_load_root_config(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $configPath = dirname(__DIR__) . '/config.php';
    if (file_exists($configPath)) {
        $configValues = (static function ($__configPath) {
            require $__configPath;
            return get_defined_vars();
        })($configPath);

        foreach ($configValues as $key => $value) {
            if ($key === '__configPath') {
                continue;
            }
            $GLOBALS[$key] = $value;
        }
    }

    $loaded = true;
}

function cpeapp_config(string $key, $default = null)
{
    cpeapp_load_root_config();

    if (array_key_exists($key, $GLOBALS) && $GLOBALS[$key] !== '') {
        return $GLOBALS[$key];
    }

    $env = getenv($key);
    if ($env !== false && $env !== '') {
        return $env;
    }

    return $default;
}

function cpeapp_required_config(string $key)
{
    $value = cpeapp_config($key);
    if ($value === null || $value === '') {
        throw new RuntimeException("Missing required config: {$key}");
    }

    return $value;
}

function cpeapp_pdo_from_root_config(): PDO
{
    $host = cpeapp_required_config('DB_HOST');
    $name = cpeapp_required_config('DB_NAME');
    $user = cpeapp_required_config('DB_USER');
    $pass = cpeapp_required_config('DB_PASS');

    $pdo = new PDO(
        "mysql:host={$host};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ]
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}
