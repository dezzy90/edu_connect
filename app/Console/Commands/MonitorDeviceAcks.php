<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use Illuminate\Support\Facades\Log;

class MonitorDeviceAcks extends Command
{
    protected $signature = 'mqtt:monitor-acks {--timeout=30}';
    protected $description = 'Monitor device acknowledgment messages to debug sync issues';

    public function handle()
    {
        $timeout = (int) $this->option('timeout');
        
        $this->info("Monitoring device acknowledgments for {$timeout} seconds...");
        $this->info("Listening for topics: mqtt/face/+/Ack");
        $this->line("");

        try {
            // Create MQTT client
            $host = config('mqtt.host');
            $port = config('mqtt.port');
            $clientId = 'rod-monitor-' . time();
            
            $mqttClient = new MqttClient($host, $port, $clientId);
            
            // Connection settings
            $connectionSettings = new ConnectionSettings();
            $connectionSettings->setKeepAliveInterval(60);
            
            if (config('mqtt.username')) {
                $connectionSettings->setUsername(config('mqtt.username'));
            }
            if (config('mqtt.password')) {
                $connectionSettings->setPassword(config('mqtt.password'));
            }

            // Connect
            $mqttClient->connect($connectionSettings, false);
            $this->info("✓ Connected to MQTT broker: {$host}:{$port}");

            // Subscribe to acknowledgment topics
            $mqttClient->subscribe('mqtt/face/+/Ack', function ($topic, $message) {
                $this->handleAckMessage($topic, $message);
            }, 1);

            $this->info("✓ Subscribed to acknowledgment topics");
            $this->line("Waiting for messages... (Press Ctrl+C to stop)");

            // Listen for messages
            $mqttClient->loop(true, true, $timeout);

            // Disconnect
            $mqttClient->disconnect();
            $this->info("\nDisconnected from MQTT broker");

        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            Log::error("MQTT monitoring failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }

        return 0;
    }

    private function handleAckMessage(string $topic, string $message)
    {
        try {
            $data = json_decode($message, true);
            
            if (!$data) {
                $this->line("Raw message on {$topic}: {$message}");
                return;
            }

            // Extract device ID from topic
            $topicParts = explode('/', $topic);
            $deviceId = $topicParts[2] ?? 'unknown';

            $this->line("--- Device Response ---");
            $this->info("Device: {$deviceId}");
            $this->info("Topic: {$topic}");
            
            if (isset($data['operator'])) {
                $this->line("Operator: " . $data['operator']);
            }
            
            if (isset($data['code'])) {
                $code = $data['code'];
                $this->line("Code: {$code} - " . $this->getCodeDescription($code));
                
                if ($code !== '200') {
                    $this->error("❌ Operation failed!");
                } else {
                    $this->info("✅ Operation successful!");
                }
            }
            
            if (isset($data['info'])) {
                $info = $data['info'];
                if (isset($info['customId'])) {
                    $this->line("Custom ID: " . $info['customId']);
                }
                if (isset($info['result'])) {
                    $this->line("Result: " . $info['result']);
                }
            }

            $this->line("Full response: " . json_encode($data, JSON_PRETTY_PRINT));
            $this->line(""); // Empty line for separation

            // Log to Laravel logs too
            Log::info("Device ACK received", [
                'device_id' => $deviceId,
                'topic' => $topic,
                'response' => $data
            ]);

        } catch (\Exception $e) {
            $this->error("Error parsing message: " . $e->getMessage());
            $this->line("Raw message: {$message}");
        }
    }

    private function getCodeDescription(string $code): string
    {
        $descriptions = [
            '200' => 'Operation successful',
            '460' => 'Data out of range (Single packet > 1M)',
            '461' => 'Cannot find customId',
            '462' => 'Parameter error (missing info)',
            '463' => 'Base64 data decode error',
            '464' => 'Failed to extract facial features (bad image)',
            '465' => 'Database operation failed',
            '467' => 'Person database is full',
            '468' => 'Image data too short (<1000 bytes)',
            '469' => 'Image data too long (>1M or >1080P)',
            '470' => 'Server IP error',
            '471' => 'Image download timeout/failure',
            '472' => 'Missing both pic and picURI',
            '473' => 'RFID card already exists',
            '474' => 'Missing WGFacilityCode',
            '475' => 'Missing cardNum2',
            '476' => 'cardNum2 already exists',
        ];

        return $descriptions[$code] ?? 'Unknown error code';
    }
}