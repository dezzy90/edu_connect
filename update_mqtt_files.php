<?php

$files = [
    'direct_device_subscriber.php',
    'test_student_addition.php',
    'test_lastwill.php',
    'test_connect_params.php',
    'test_clean_session.php',
    'detailed_mqtt_debug.php',
    'complete_student_integration.php'
];

foreach ($files as $file) {
    if (!file_exists($file)) {
        echo "Skipping (not found): $file\n";
        continue;
    }
    
    $content = file_get_contents($file);
    $original = $content;
    
    // Add helper include if not present
    if (strpos($content, 'mqtt_config_helper.php') === false) {
        $content = str_replace(
            "require_once 'vendor/autoload.php';",
            "require_once 'vendor/autoload.php';\nrequire_once 'mqtt_config_helper.php';",
            $content
        );
    }
    
    // Replace hardcoded values with config array access
    $content = preg_replace(
        '/\$host = [\'\"]\d+\.\d+\.\d+\.\d+[\'\"];/',
        '$host = $config[\'host\'];',
        $content
    );
    
    $content = preg_replace(
        '/\$port = \d+;/',
        '$port = $config[\'port\'];',
        $content
    );
    
    $content = preg_replace(
        '/\$username = [\'\"]\w+[\'\"];/',
        '$username = $config[\'username\'];',
        $content
    );
    
    $content = preg_replace(
        '/\$password = [\'\"

][^\'\"]*[\'\"];/',
        '$password = $config[\'password\'];',
        $content
    );
    
    // Add config loading after use statements if not present
    if (strpos($content, 'getMqttConfig()') === false) {
        // Find the position after the last 'use' statement
        if (preg_match('/(use [^;]+;)(\s*\n)/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $insertPos = $matches[0][1] + strlen($matches[0][0]);
            $configCode = "\n\$config = getMqttConfig();\ndisplayMqttConfig(\$config);\n";
            $content = substr_replace($content, $configCode, $insertPos, 0);
        }
    }
    
    if ($content !== $original) {
        file_put_contents($file, $content);
        echo "✅ Updated: $file\n";
    } else {
        echo "⏭️  No changes needed: $file\n";
    }
}

echo "\n✅ All files processed!\n";
