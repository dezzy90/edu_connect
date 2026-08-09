<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use App\Services\BiometricMessageProcessor;
use App\Services\RealDeviceMessageProcessor;
use Exception;

class MqttSubscriberCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mqtt:subscribe 
                            {--host= : MQTT broker host (overrides config)}
                            {--port= : MQTT broker port (overrides config)}
                            {--username= : MQTT username (overrides config)}
                            {--password= : MQTT password (overrides config)}
                            {--topics=* : Specific topics to subscribe to (overrides default)}
                            {--all : Subscribe to all configured topics}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Subscribe to MQTT broker and process biometric device messages';

    private MqttClient $mqtt;
    private ?BiometricMessageProcessor $processor = null;
    private ?RealDeviceMessageProcessor $realDeviceProcessor;

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
        // Processor will be initialized when needed
        $this->realDeviceProcessor = null;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Use config/env values with command line overrides
        $host = $this->option('host') ?: config('mqtt.host', env('MQTT_HOST', 'localhost'));
        $port = (int) ($this->option('port') ?: config('mqtt.port', env('MQTT_PORT', 1883)));
        $username = $this->option('username') ?: config('mqtt.username', env('MQTT_USERNAME'));
        $password = $this->option('password') ?: config('mqtt.password', env('MQTT_PASSWORD'));
        
        // Determine topics to subscribe to
        $topics = $this->getTopicsToSubscribe();

        $this->info("Starting MQTT subscriber...");
        $this->info("Host: {$host}:{$port}");
        $this->info("Username: " . ($username ? '***' : 'none'));
        $this->info("Topics: " . implode(', ', $topics));

        try {
            $this->setupMqttClient($host, $port, $username, $password);
            $this->subscribeToTopics($topics);
        } catch (Exception $e) {
            $this->error("MQTT Subscriber failed: " . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Determine which topics to subscribe to.
     */
    private function getTopicsToSubscribe(): array
    {
        $customTopics = $this->option('topics');
        
        if (!empty($customTopics)) {
            return $customTopics;
        }

        if ($this->option('all')) {
            return array_values(config('mqtt.topics'));
        }

        // Default to essential topics for student check-in/out
        return [
            config('mqtt.topics.recognition'),
            config('mqtt.topics.capture'),
            config('mqtt.topics.heartbeat'),
            config('mqtt.topics.basic'),
        ];
    }

    /**
     * Setup MQTT client connection.
     */
    private function setupMqttClient(string $host, int $port, ?string $username, ?string $password): void
    {
        // Debug output
        $this->line("DEBUG: Attempting connection with:");
        $this->line("  Host: {$host}");
        $this->line("  Port: {$port}");
        $this->line("  Username: " . ($username ?? 'NULL'));
        $this->line("  Password: " . ($password ? str_repeat('*', strlen($password)) : 'NULL'));
        $this->line("  Username length: " . strlen($username ?? ''));
        $this->line("  Password length: " . strlen($password ?? ''));
        
        // Create client with unique ID first (simpler format like test scripts)
        $clientId = 'rod-subscriber-' . time();
        $this->line("DEBUG: Client ID: {$clientId}");
        $this->mqtt = new MqttClient($host, $port, $clientId);
        
        // Setup connection settings (matching working test files)
        $connectionSettings = (new ConnectionSettings())
            ->setKeepAliveInterval(config('mqtt.keep_alive_interval', 60));
        
        // Add authentication if provided
        if ($username && $password) {
            $this->line("DEBUG: Setting username and password on connection settings");
            $connectionSettings->setUsername($username)->setPassword($password);
        } else {
            $this->line("DEBUG: No authentication (username or password is empty)");
        }
        
        // TLS/SSL settings (basic implementation)
        if (config('mqtt.security.use_tls', false)) {
            $connectionSettings->setUseTls(true);
        }
        
        $this->line("DEBUG: Calling connect()...");
        
        // Connect with clean session = false (matching working test files)
        $this->mqtt->connect($connectionSettings, false);
        
        $this->info("Connected to MQTT broker successfully!");
        $this->line("Client ID: {$clientId}");
        $this->line("Host: {$host}:{$port}");
        $this->line("Authentication: " . ($username ? "Yes (user: {$username})" : "No"));
        
        // Initialize real device processor for disconnected mode with reply capability
        $this->realDeviceProcessor = new RealDeviceMessageProcessor($this->mqtt, true);
    }

    /**
     * Subscribe to multiple MQTT topics and process messages.
     */
    private function subscribeToTopics(array $topics): void
    {
        foreach ($topics as $topic) {
            $this->mqtt->subscribe($topic, function (string $topic, string $message) {
                $this->processMessage($topic, $message);
            }, 1);
            
            $this->info("Subscribed to topic: {$topic}");
        }

        $this->info("Listening for messages... (Press Ctrl+C to stop)");

        // Handle graceful shutdown (only on Unix/Linux systems)
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'shutdown']);
            pcntl_signal(SIGINT, [$this, 'shutdown']);
        }

        // Keep the script running
        $this->mqtt->loop(true);
    }

    /**
     * Subscribe to MQTT topic and process messages.
     * @deprecated Use subscribeToTopics instead
     */
    private function subscribe(string $topic): void
    {
        $this->subscribeToTopics([$topic]);
    }

    /**
     * Process incoming MQTT message.
     */
    private function processMessage(string $topic, string $message): void
    {
        try {
            $this->info("Received message from topic: {$topic}");
            $this->line("Message: " . substr($message, 0, 100) . (strlen($message) > 100 ? '...' : ''));

            // Initialize processor if not already done
            if (!$this->processor) {
                $this->processor = app(BiometricMessageProcessor::class);
            }

            // Determine message type and extract device ID
            $messageInfo = $this->analyzeTopicAndMessage($topic, $message);
            
            if (!$messageInfo) {
                $this->warn("Could not process topic: {$topic}");
                return;
            }

            // Process different message types
            switch ($messageInfo['type']) {
                case 'recognition':
                case 'capture':
                    // Check if this is the real device
                    if ($messageInfo['device_id'] === '2581924_ipobexa' && $this->realDeviceProcessor) {
                        $this->info("Processing real device message with specialized processor");
                        $result = $this->realDeviceProcessor->processRealDeviceMessage($messageInfo['device_id'], $message, $messageInfo);
                    } else {
                        // Process with standard biometric processor
                        $result = $this->processor->processMessage($messageInfo['device_id'], $message, $messageInfo);
                    }
                    break;
                    
                case 'heartbeat':
                    // Process device heartbeat (pass device_id if available from topic)
                    $result = $this->processor->processHeartbeat($message, $messageInfo['device_id'] ?? null);
                    break;
                    
                case 'basic':
                    // Process up/down notifications
                    $result = $this->processor->processBasicNotification($message);
                    break;
                    
                case 'ack':
                    // Process downlink execution results
                    $result = $this->processor->processAcknowledgment($messageInfo['device_id'], $message);
                    break;
                    
                default:
                    $this->warn("Unknown message type: {$messageInfo['type']}");
                    return;
            }

            if ($result['success']) {
                $this->info("Message processed successfully: {$result['message']}");
            } else {
                $this->warn("Message processing failed: {$result['message']}");
            }

        } catch (Exception $e) {
            $this->error("Error processing message: " . $e->getMessage());
            logger()->error('MQTT message processing error', [
                'topic' => $topic,
                'message' => $message,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Analyze topic and determine message type and device ID.
     */
    private function analyzeTopicAndMessage(string $topic, string $message): ?array
    {
        // Pattern matching for different topic types
        $patterns = [
            // Identity recognition records: mqtt/face/ID/Rec
            '/^mqtt\/face\/([^\/]+)\/Rec$/' => 'recognition',
            // Stranger capture records: mqtt/face/ID/Snap
            '/^mqtt\/face\/([^\/]+)\/Snap$/' => 'capture',
            // QR Code transmission: mqtt/face/ID/QRCode
            '/^mqtt\/face\/([^\/]+)\/QRCode$/' => 'qrcode',
            // ID Card information: mqtt/face/ID/IDCard
            '/^mqtt\/face\/([^\/]+)\/IDCard$/' => 'idcard',
            // IC/RF card information: mqtt/face/ID/Card
            '/^mqtt\/face\/([^\/]+)\/Card$/' => 'card',
            // Door magnet/alarm: mqtt/face/ID/Alarm
            '/^mqtt\/face\/([^\/]+)\/Alarm$/' => 'alarm',
            // Downlink execution result: mqtt/face/ID/Ack
            '/^mqtt\/face\/([^\/]+)\/Ack$/' => 'ack',
        ];

        // Check device-specific topics
        foreach ($patterns as $pattern => $type) {
            if (preg_match($pattern, $topic, $matches)) {
                return [
                    'type' => $type,
                    'device_id' => $matches[1],
                    'topic' => $topic
                ];
            }
        }

        // Check fixed topics
        switch ($topic) {
            case 'mqtt/face/heartbeat':
                return ['type' => 'heartbeat', 'device_id' => null, 'topic' => $topic];
            case 'mqtt/face/basic':
                return ['type' => 'basic', 'device_id' => null, 'topic' => $topic];
        }

        return null;
    }

    /**
     * Extract device ID from MQTT topic.
     * @deprecated Use analyzeTopicAndMessage instead
     */
    private function extractDeviceIdFromTopic(string $topic): ?string
    {
        $info = $this->analyzeTopicAndMessage($topic, '');
        return $info['device_id'] ?? null;
    }

    /**
     * Graceful shutdown handler.
     */
    public function shutdown(): void
    {
        $this->info("\nShutting down MQTT subscriber...");
        
        if (isset($this->mqtt)) {
            $this->mqtt->disconnect();
            $this->info("Disconnected from MQTT broker.");
        }
        
        exit(0);
    }
}
