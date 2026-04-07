<?php

function aunqa_default_settings_seed_data(): array
{
    return [
        'gemini_api_model' => 'gemini-2.5-flash',
        'ai_auto_pass_threshold' => '80',
    ];
}

function aunqa_seed_default_settings(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO aunqa_settings (setting_key, setting_value) VALUES (:setting_key, :setting_value)"
    );

    foreach (aunqa_default_settings_seed_data() as $settingKey => $settingValue) {
        $stmt->execute([
            ':setting_key' => $settingKey,
            ':setting_value' => $settingValue,
        ]);
    }
}

function aunqa_seed_default_data(PDO $pdo): void
{
    aunqa_seed_default_settings($pdo);
}
