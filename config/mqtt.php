<?php

return [
    /*
    |--------------------------------------------------------------------------
    | MQTT Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for MQTT broker connection and topic subscriptions
    | for biometric device communication.
    |
    */

    'host' => env('MQTT_HOST', 'localhost'),
    'port' => env('MQTT_PORT', 1883),
    'username' => env('MQTT_USERNAME'),
    'password' => env('MQTT_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | MQTT Topics Configuration
    |--------------------------------------------------------------------------
    |
    | Define the topics that the application should subscribe to based on
    | the biometric device documentation.
    |
    */

    'topics' => [
        // Dynamic device topics (using + wildcard for any device ID)
        'recognition' => 'mqtt/face/+/Rec',        // Identity recognition records from ANY device
        'capture' => 'mqtt/face/+/Snap',           // Stranger capture records from ANY device
        'qr_code' => 'mqtt/face/+/QRCode',         // QR Code transmission from ANY device
        'id_card' => 'mqtt/face/+/IDCard',         // ID Card information from ANY device
        'ic_rf_card' => 'mqtt/face/+/Card',        // IC/RF card information from ANY device
        'alarm' => 'mqtt/face/+/Alarm',            // Door magnet/alarm messages from ANY device
        'acknowledgment' => 'mqtt/face/+/Ack',     // Downlink execution results from ANY device

        // Fixed topics (not device-specific)
        'heartbeat' => 'mqtt/face/heartbeat',      // Application layer heartbeat
        'basic' => 'mqtt/face/basic',              // Up/down notifications
        
        // Multi-level wildcard for all face recognition topics (alternative approach)
        'all_face_topics' => 'mqtt/face/#',       // Subscribe to ALL topics under mqtt/face/
    ],

    /*
    |--------------------------------------------------------------------------
    | Client Configuration
    |--------------------------------------------------------------------------
    |
    | MQTT client settings for connection management.
    |
    */

    'client_id_prefix' => env('MQTT_CLIENT_ID_PREFIX', 'rod-connect'),
    'keep_alive_interval' => 60,
    'clean_session' => true,

    /*
    |--------------------------------------------------------------------------
    | Last Will and Testament
    |--------------------------------------------------------------------------
    |
    | Configure the last will message that will be sent if the client
    | disconnects unexpectedly.
    |
    */

    'last_will' => [
        'topic' => 'clients/rod-connect/status',
        'message' => 'offline',
        'qos' => 1,
        'retain' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Publishing Topics for Device Commands
    |--------------------------------------------------------------------------
    |
    | Topics for sending commands to specific devices.
    | Format: mqtt/face/{device_id}
    |
    */

    'device_command_topic' => 'mqtt/face/%s',  // %s will be replaced with device_id

    /*
    |--------------------------------------------------------------------------
    | Message Processing Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for message processing and logging.
    |
    */

    'processing' => [
        'log_all_messages' => env('MQTT_LOG_ALL_MESSAGES', false),
        'log_unknown_messages' => env('MQTT_LOG_UNKNOWN_MESSAGES', true),
        'max_message_length' => 1024 * 10, // 10KB max message size
        'timeout' => 30, // Processing timeout in seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    |
    | Security-related configuration for MQTT connections.
    |
    */

    'security' => [
        'use_tls' => env('MQTT_USE_TLS', false),
        'ca_file' => env('MQTT_CA_FILE'),
        'cert_file' => env('MQTT_CERT_FILE'),
        'key_file' => env('MQTT_KEY_FILE'),
        'verify_peer' => env('MQTT_VERIFY_PEER', true),
    ],
];