<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\DeviceRegistrationService;
use App\Models\BiometricDevice;
use App\Models\School;

class ManageDevices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'devices:manage 
                            {action : Action to perform: list, register, bulk-register, stats}
                            {--device-id= : Device ID for specific actions}
                            {--school= : School name for device assignment}
                            {--name= : Device name}
                            {--location= : Device location}
                            {--config-file= : Config file for bulk registration}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage biometric devices dynamically';

    private DeviceRegistrationService $deviceService;

    public function __construct(DeviceRegistrationService $deviceService)
    {
        parent::__construct();
        $this->deviceService = $deviceService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $action = $this->argument('action');

        match ($action) {
            'list' => $this->listDevices(),
            'register' => $this->registerDevice(),
            'bulk-register' => $this->bulkRegisterDevices(),
            'stats' => $this->showDeviceStats(),
            default => $this->error("Unknown action: {$action}. Use: list, register, bulk-register, stats")
        };

        return 0;
    }

    /**
     * List all devices
     */
    private function listDevices(): void
    {
        $this->info('🇨🇲 Rod-Connect Biometric Devices');
        $this->line('===================================');

        $devices = BiometricDevice::with('school')->orderBy('school_id')->get();

        if ($devices->isEmpty()) {
            $this->warn('No devices found.');
            return;
        }

        $headers = ['Device ID', 'Name', 'School', 'Location', 'Status', 'Last Heartbeat', 'MQTT Topics'];
        $rows = [];

        foreach ($devices as $device) {
            $status = $device->is_active ? '🟢 Active' : '🔴 Inactive';
            $lastSeen = $device->last_heartbeat?->diffForHumans() ?? 'Never';
            $topics = "↗️ mqtt/face/{$device->device_id}/Rec\n↙️ mqtt/face/{$device->device_id}";

            $rows[] = [
                $device->device_id,
                $device->name,
                $device->school->name,
                $device->location,
                $status,
                $lastSeen,
                $topics
            ];
        }

        $this->table($headers, $rows);

        $this->newLine();
        $this->info('💡 MQTT Subscriber automatically handles ALL these devices with wildcards:');
        $this->line('   📥 Listens to: mqtt/face/+/Rec (any device recognition)');
        $this->line('   📤 Replies to: mqtt/face/{device_id} (specific device responses)');
    }

    /**
     * Register a single device
     */
    private function registerDevice(): void
    {
        $deviceId = $this->option('device-id') ?? $this->ask('Enter device ID');
        $name = $this->option('name') ?? $this->ask('Enter device name', "Biometric Device - {$deviceId}");
        $location = $this->option('location') ?? $this->ask('Enter location', 'Main Entrance');
        $schoolName = $this->option('school') ?? $this->choice('Select school', 
            School::pluck('name')->toArray()
        );

        $school = School::where('name', 'like', "%{$schoolName}%")->first();
        if (!$school) {
            $this->error("School not found: {$schoolName}");
            return;
        }

        try {
            $device = BiometricDevice::updateOrCreate(
                ['device_id' => $deviceId],
                [
                    'name' => $name,
                    'location' => $location,
                    'school_id' => $school->id,
                    'device_type' => 'face_recognition',
                    'is_active' => true,
                    'firmware_version' => '1.0.0',
                    'mac_address' => '00:00:00:00:00:00',
                    'last_heartbeat' => now()
                ]
            );

            $this->info("✅ Device registered successfully!");
            $this->line("Device ID: {$device->device_id}");
            $this->line("School: {$school->name}");
            $this->line("MQTT Topics:");
            $this->line("  📥 Uplink: mqtt/face/{$device->device_id}/Rec");
            $this->line("  📤 Downlink: mqtt/face/{$device->device_id}");

        } catch (\Exception $e) {
            $this->error("Failed to register device: " . $e->getMessage());
        }
    }

    /**
     * Bulk register devices from config
     */
    private function bulkRegisterDevices(): void
    {
        $configFile = $this->option('config-file') ?? 'device_config.json';
        
        if (!file_exists($configFile)) {
            $this->error("Config file not found: {$configFile}");
            $this->line("Create a JSON file with device configurations:");
            $this->line('[');
            $this->line('  {');
            $this->line('    "device_id": "DEVICE_SCHOOL1_01",');
            $this->line('    "name": "School 1 Main Entrance",');
            $this->line('    "school": "Lycée Général Leclerc Douala",');
            $this->line('    "location": "Main Entrance",');
            $this->line('    "ip_address": "192.168.1.100"');
            $this->line('  }');
            $this->line(']');
            return;
        }

        $configs = json_decode(file_get_contents($configFile), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error("Invalid JSON in config file: " . json_last_error_msg());
            return;
        }

        $registered = $this->deviceService->bulkRegisterDevices($configs);
        
        $this->info("✅ Bulk registration completed!");
        $this->line("Registered " . count($registered) . " devices.");
        
        foreach ($registered as $device) {
            $this->line("  📱 {$device->device_id} → {$device->school->name}");
        }
    }

    /**
     * Show device statistics
     */
    private function showDeviceStats(): void
    {
        $stats = $this->deviceService->getDeviceStatistics();
        
        $this->info('📊 Device Statistics');
        $this->line('==================');

        $totalDevices = count($stats);
        $onlineDevices = collect($stats)->where('is_online', true)->count();
        $offlineDevices = $totalDevices - $onlineDevices;

        $this->line("Total Devices: {$totalDevices}");
        $this->line("🟢 Online: {$onlineDevices}");
        $this->line("🔴 Offline: {$offlineDevices}");
        $this->newLine();

        // Group by school
        $bySchool = collect($stats)->groupBy('school');
        
        foreach ($bySchool as $school => $devices) {
            $this->line("🏫 {$school} ({$devices->count()} devices):");
            foreach ($devices as $device) {
                $status = $device['is_online'] ? '🟢' : '🔴';
                $this->line("  {$status} {$device['device_id']} - {$device['location']} ({$device['last_heartbeat']})");
            }
            $this->newLine();
        }
    }
}
