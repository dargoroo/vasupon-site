<?php

function app_load_root_config() {
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

function app_config($key, $default = null) {
    app_load_root_config();

    if (array_key_exists($key, $GLOBALS) && $GLOBALS[$key] !== '') {
        return $GLOBALS[$key];
    }

    $env = getenv($key);
    if ($env !== false && $env !== '') {
        return $env;
    }

    return $default;
}

function app_required_config($key) {
    $value = app_config($key);
    if ($value === null || $value === '') {
        throw new RuntimeException("Missing required config: $key");
    }
    return $value;
}

function app_pdo() {
    $host = app_required_config('DB_HOST');
    $name = app_required_config('DB_NAME');
    $user = app_required_config('DB_USER');
    $pass = app_required_config('DB_PASS');

    $pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}
