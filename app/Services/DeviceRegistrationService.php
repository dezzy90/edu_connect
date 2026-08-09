<?php

namespace App\Services;

use App\Models\BiometricDevice;
use App\Models\School;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class DeviceRegistrationService
{
    /**
     * Auto-register a device when it first sends a message
     */
    public function autoRegisterDevice(string $deviceId, array $messageInfo = []): BiometricDevice
    {
        // Check if device already exists
        $device = BiometricDevice::where('device_id', $deviceId)->first();
        
        if ($device) {
            $this->updateDeviceLastSeen($device);
            return $device;
        }

        Log::info("Auto-registering new device", ['device_id' => $deviceId]);

        // Extract device info from message if available
        $deviceName = $messageInfo['facesluiceName'] ?? "Auto-registered Device - {$deviceId}";
        $location = $this->guessLocationFromDeviceId($deviceId);
        
        // Assign to a school (you can modify this logic)
        $school = $this->assignDeviceToSchool($deviceId, $messageInfo);

        // Create new device
        $device = BiometricDevice::create([
            'device_id' => $deviceId,
            'name' => $deviceName,
            'location' => $location,
            'school_id' => $school->id,
            'device_type' => 'face_recognition',
            'is_active' => true,
            'ip_address' => $this->extractIpFromDeviceId($deviceId),
            'firmware_version' => '1.0.0',
            'mac_address' => '00:00:00:00:00:00',
            'last_heartbeat' => now()
        ]);

        Log::info("Device auto-registered successfully", [
            'device_id' => $deviceId,
            'school' => $school->name,
            'location' => $location
        ]);

        return $device;
    }

    /**
     * Update device's last seen timestamp
     */
    private function updateDeviceLastSeen(BiometricDevice $device, bool $forceUpdate = false): void
    {
        // Use cache to avoid too frequent database updates
        $cacheKey = "device_last_seen_{$device->device_id}";
        
        // Force update bypasses cache (useful for critical heartbeat messages)
        if ($forceUpdate || !Cache::has($cacheKey)) {
            $device->update(['last_heartbeat' => now()]);
            Cache::put($cacheKey, true, 60); // Cache for 1 minute (reduced from 5 minutes)
            
            Log::debug("Device heartbeat updated", [
                'device_id' => $device->device_id,
                'last_heartbeat' => now()->toDateTimeString(),
                'forced' => $forceUpdate
            ]);
        }
    }
    
    /**
     * Force update device heartbeat (bypasses cache)
     */
    public function forceUpdateHeartbeat(string $deviceId): bool
    {
        $device = BiometricDevice::where('device_id', $deviceId)->first();
        
        if (!$device) {
            return false;
        }
        
        $this->updateDeviceLastSeen($device, true);
        return true;
    }

    /**
     * Assign device to a school based on device ID or rules
     */
    private function assignDeviceToSchool(string $deviceId, array $messageInfo = []): School
    {
        // Strategy 1: Try to map by device ID pattern
        if (preg_match('/^(\d+)_/', $deviceId, $matches)) {
            $schoolId = (int) $matches[1];
            $school = School::find($schoolId);
            if ($school) {
                return $school;
            }
        }

        // Strategy 2: Check if facesluiceName contains school info
        if (isset($messageInfo['facesluiceName'])) {
            $deviceName = strtolower($messageInfo['facesluiceName']);
            
            $schoolMappings = [
                'douala' => ['name_contains' => ['douala', 'leclerc', 'libermann']],
                'yaounde' => ['name_contains' => ['yaoundé', 'yaounde', 'paix']],
                'bafoussam' => ['name_contains' => ['bafoussam', 'bilingue']],
                'bamenda' => ['name_contains' => ['bamenda', 'government']]
            ];

            foreach ($schoolMappings as $city => $rules) {
                foreach ($rules['name_contains'] as $keyword) {
                    if (str_contains($deviceName, $keyword)) {
                        $school = School::where('name', 'like', "%{$keyword}%")->first();
                        if ($school) {
                            return $school;
                        }
                    }
                }
            }
        }

        // Strategy 3: Default to first available school
        $defaultSchool = School::first();
        
        if (!$defaultSchool) {
            throw new \Exception("No schools available for device assignment. Please create schools first.");
        }

        Log::warning("Device assigned to default school", [
            'device_id' => $deviceId,
            'school' => $defaultSchool->name
        ]);

        return $defaultSchool;
    }

    /**
     * Guess location from device ID
     */
    private function guessLocationFromDeviceId(string $deviceId): string
    {
        $locationPatterns = [
            'entrance' => 'Main Entrance',
            'exit' => 'Main Exit', 
            'gate' => 'Security Gate',
            'office' => 'Administration Office',
            'library' => 'Library Entrance'
        ];

        $deviceLower = strtolower($deviceId);
        
        foreach ($locationPatterns as $pattern => $location) {
            if (str_contains($deviceLower, $pattern)) {
                return $location;
            }
        }

        return "Location - {$deviceId}";
    }

    /**
     * Extract IP from device ID if possible
     */
    private function extractIpFromDeviceId(string $deviceId): ?string
    {
        // If device ID contains IP-like pattern, extract it
        if (preg_match('/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/', $deviceId, $matches)) {
            return $matches[1];
        }

        // Default to unknown
        return null;
    }

    /**
     * Get all active devices with statistics
     */
    public function getDeviceStatistics(): array
    {
        $devices = BiometricDevice::with('school')
            ->where('is_active', true)
            ->get();

        $stats = [];
        foreach ($devices as $device) {
            $stats[] = [
                'device_id' => $device->device_id,
                'name' => $device->name,
                'school' => $device->school->name,
                'location' => $device->location,
                'last_heartbeat' => $device->last_heartbeat?->diffForHumans(),
                'is_online' => $device->last_heartbeat && $device->last_heartbeat->diffInMinutes() < 10,
                'uplink_topic' => "mqtt/face/{$device->device_id}/Rec",
                'downlink_topic' => "mqtt/face/{$device->device_id}"
            ];
        }

        return $stats;
    }

    /**
     * Bulk register devices from configuration
     */
    public function bulkRegisterDevices(array $deviceConfigs): array
    {
        $registered = [];
        
        foreach ($deviceConfigs as $config) {
            try {
                $device = $this->registerDeviceFromConfig($config);
                $registered[] = $device;
            } catch (\Exception $e) {
                Log::error("Failed to register device", [
                    'config' => $config,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $registered;
    }

    /**
     * Register device from configuration array
     */
    private function registerDeviceFromConfig(array $config): BiometricDevice
    {
        $school = School::where('name', 'like', "%{$config['school']}%")->firstOrFail();
        
        return BiometricDevice::updateOrCreate(
            ['device_id' => $config['device_id']],
            [
                'name' => $config['name'],
                'location' => $config['location'],
                'school_id' => $school->id,
                'device_type' => $config['type'] ?? 'face_recognition',
                'is_active' => $config['active'] ?? true,
                'ip_address' => $config['ip_address'] ?? null,
                'firmware_version' => $config['firmware'] ?? '1.0.0',
                'mac_address' => $config['mac_address'] ?? '00:00:00:00:00:00',
                'last_heartbeat' => now()
            ]
        );
    }
}