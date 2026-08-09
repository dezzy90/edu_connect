<?php

/**
 * MQTT Configuration Helper
 * Loads MQTT configuration from .env file for standalone PHP scripts
 */

function loadEnvFile($path = __DIR__ . '/.env') {
    if (!file_exists($path)) {
        throw new Exception(".env file not found at: {$path}");
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // Parse KEY=VALUE
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Remove quotes if present
            $value = trim($value, '"\'');
            
            // Set as environment variable if not already set
            if (!getenv($key)) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
}

function getMqttConfig() {
    // Try to load .env file
    try {
        loadEnvFile();
    } catch (Exception $e) {
        echo "⚠️  Warning: Could not load .env file: " . $e->getMessage() . "\n";
        echo "Using default configuration...\n\n";
    }

    return [
        'host' => getenv('MQTT_HOST') ?: 'test.mosquitto.org',
        'port' => (int)(getenv('MQTT_PORT') ?: 1883),
        'username' => getenv('MQTT_USERNAME') ?: null,
        'password' => getenv('MQTT_PASSWORD') ?: null,
        'client_id_prefix' => getenv('MQTT_CLIENT_ID_PREFIX') ?: 'rod-connect',
    ];
}

function displayMqttConfig($config) {
    echo "MQTT Configuration (from .env):\n";
    echo "  Host: {$config['host']}\n";
    echo "  Port: {$config['port']}\n";
    echo "  Username: " . ($config['username'] ?: '(none)') . "\n";
    echo "  Password: " . ($config['password'] ? '***' : '(none)') . "\n";
    echo "  Client ID Prefix: {$config['client_id_prefix']}\n\n";
}
