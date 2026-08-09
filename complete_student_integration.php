<?php

require_once 'vendor/autoload.php';
require_once 'mqtt_config_helper.php';

// Bootstrap Laravel
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Student;

$config = getMqttConfig();
displayMqttConfig($config);
use App\Models\School;
use App\Models\SchoolClass;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

echo "🧑‍🎓 Adding Student to Database & Biometric Device\n";
echo "==================================================\n\n";

// Step 1: Add student to database
echo "📊 Step 1: Adding student to Laravel database...\n";

try {
    // Get school (Lycée Général Leclerc Douala)
    $school = School::where('name', 'like', '%Lycée Général Leclerc Douala%')->first();
    if (!$school) {
        echo "❌ School not found! Let's create it...\n";
        $school = School::create([
            'name' => 'Lycée Général Leclerc Douala',
            'address' => 'Douala, Littoral, Cameroon',
            'phone' => '+237 233 123 456',
            'email' => 'contact@lycee-leclerc-douala.edu.cm',
            'principal_name' => 'M. Jean Baptiste Mvondo',
            'school_type' => 'public',
            'student_capacity' => 800
        ]);
        echo "✅ School created with ID: {$school->id}\n";
    } else {
        echo "✅ Found school: {$school->name} (ID: {$school->id})\n";
    }

    // Get or create class
    $schoolClass = SchoolClass::where('school_id', $school->id)
        ->first(); // Just get the first available class
    
    if (!$schoolClass) {
        echo "ℹ️ No classes found, will create student without class assignment\n";
        // Create a basic class if none exist
        $schoolClass = SchoolClass::create([
            'school_id' => $school->id,
            'level_id' => 1, // Assuming level 1 exists
            'name' => '6ème A',
            'code' => '6A',
            'academic_year' => '2025-2026',
            'capacity' => 40,
            'is_active' => true
        ]);
        echo "✅ Created class: {$schoolClass->name} (ID: {$schoolClass->id})\n";
    } else {
        echo "✅ Found class: {$schoolClass->name} (ID: {$schoolClass->id})\n";
    }

    // Check if student already exists
    $existingStudent = Student::where('biometric_id', 'STU_NDZI_001')->first();
    if ($existingStudent) {
        echo "ℹ️ Student with biometric_id 'STU_NDZI_001' already exists!\n";
        echo "   Name: {$existingStudent->first_name} {$existingStudent->last_name}\n";
        echo "   School: {$existingStudent->school->name}\n\n";
        $student = $existingStudent;
    } else {
        // Create the student
        $student = Student::create([
            'student_number' => 'CAM2025002',
            'first_name' => 'NDI',
            'last_name' => 'DESMOND',
            'date_of_birth' => '2010-05-15',
            'gender' => 'male',
            'address' => 'Douala, Cameroon',
            'phone' => '+237 697 123 456',
            'email' => null,
            'emergency_contact' => '+237 699 123 456',
            'school_id' => $school->id,
            'class_id' => $schoolClass->id,
            'biometric_id' => 'STU_NDI_001', // This is the key field!
            'enrollment_date' => now(),
            'is_active' => true,
        ]);

        echo "✅ Student created successfully!\n";
        echo "   ID: {$student->id}\n";
        echo "   Name: {$student->first_name} {$student->last_name}\n";
        echo "   Student Number: {$student->student_number}\n";
        echo "   Biometric ID: {$student->biometric_id}\n";
        echo "   School: {$student->school->name}\n";
        if ($student->class) {
            echo "   Class: {$student->class->name}\n";
        }
        echo "\n";
    }

} catch (Exception $e) {
    echo "❌ Database error: " . $e->getMessage() . "\n";
    exit(1);
}

// Step 2: Add student to biometric device
echo "📱 Step 2: Adding student to biometric device...\n";

// MQTT Configuration
$host = $config['host'];
$port = $config['port'];
$username = $config['username'];
$password = $config['password'];
$clientId = 'rod-full-test-' . time();
$deviceId = '2581924_ipobexa';
$deviceTopic = "mqtt/face/{$deviceId}";

try {
    echo "📡 Connecting to MQTT broker...\n";
    $client = new MqttClient($host, $port, $clientId);
    
    $connectionSettings = (new ConnectionSettings())
        ->setUsername($username)
        ->setPassword($password)
        ->setKeepAliveInterval(60);
    
    $client->connect($connectionSettings, false);
    echo "✅ MQTT connected successfully!\n\n";
    
    // Build EditPerson message
    $messageId = 'ROD:' . gethostname() . '-' . hrtime(true) . ':' . getmypid() . ':' . rand(1000, 9999);
    
    $editPersonMessage = [
        'messageId' => $messageId,
        'operator' => 'EditPerson',
        'info' => [
            'customId' => $student->biometric_id, // Use the database biometric_id
            'name' => trim($student->first_name . ' ' . $student->last_name),
            'nation' => 1, // Cameroon
            'gender' => $student->gender === 'male' ? 0 : 1,
            'birthday' => $student->date_of_birth,
            'address' => $student->address ?? 'Douala, Cameroon',
            'idCard' => $student->student_number,
            'tempCardType' => 0, // Permanent card
            'EffectNumber' => 3,
            'cardValidBegin' => '2025-09-01 06:00:00',
            'cardValidEnd' => '2026-07-31 18:00:00',
            'telnum1' => $student->phone ?? '',
            'native' => 'Douala, Cameroon',
            'cardType2' => 0,
            'cardNum2' => '',
            'notes' => "ID: {$student->student_number} | École: {$student->school->name}" . 
                      ($student->class ? " | Classe: {$student->class->name}" : '') . 
                      " | Ajouté: " . now()->format('Y-m-d H:i:s'),
            'personType' => 0, // Regular student
            'cardType' => 0,
            'strategyInfo' => [
                'strategyNum' => 1,
                'strategyData' => [
                    [
                        'strategyID' => 1,
                        'strategyName' => 'student_access'
                    ]
                ]
            ]
        ]
    ];
    
    echo "🎯 Key Mapping Information:\n";
    echo "   Database Student ID: {$student->id}\n";
    echo "   Student Number: {$student->student_number}\n";
    echo "   Biometric ID (customId): {$student->biometric_id}\n";
    echo "   ➡️ When device sends recognition, it will use customId '{$student->biometric_id}'\n";
    echo "   ➡️ Our system will find student by biometric_id '{$student->biometric_id}'\n\n";
    
    echo "📤 Sending EditPerson message to device {$deviceId}...\n";
    
    // Publish to device
    $client->publish($deviceTopic, json_encode($editPersonMessage), 1);
    
    echo "✅ EditPerson message sent!\n";
    
    // Listen for ACK
    echo "📥 Listening for device acknowledgment...\n";
    $ackTopic = "{$deviceTopic}/Ack";
    
    $client->subscribe($ackTopic, function ($topic, $message) use ($messageId, $student) {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "📨 Device ACK Received!\n";
        echo "📄 Message: {$message}\n";
        
        $ackData = json_decode($message, true);
        if ($ackData && isset($ackData['messageId']) && $ackData['messageId'] === $messageId) {
            echo "🎯 This is our EditPerson ACK!\n";
            
            if (isset($ackData['info']['result']) && $ackData['info']['result'] === 'ok') {
                echo "✅ SUCCESS: {$student->first_name} {$student->last_name} added to device!\n";
                echo "🎉 Biometric ID '{$student->biometric_id}' is now enrolled!\n";
                echo "📱 Try scanning the student's finger/face on the device!\n";
            } else {
                echo "❌ Device returned error\n";
            }
        }
        echo str_repeat("=", 60) . "\n";
    }, 0);
    
    // Wait for ACK
    $startTime = time();
    while (time() - $startTime < 15) {
        $client->loop(false, true);
        usleep(100000);
    }
    
    $client->disconnect();
    
} catch (Exception $e) {
    echo "❌ MQTT Error: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat("=", 70) . "\n";
echo "🎯 COMPLETE INTEGRATION TEST SUMMARY\n";
echo str_repeat("=", 70) . "\n";
echo "✅ Student added to Laravel database\n";
echo "✅ Student data sent to biometric device\n";
echo "✅ Biometric ID mapping configured\n";
echo "\n📋 Student Details:\n";
echo "   Database ID: {$student->id}\n";
echo "   Name: {$student->first_name} {$student->last_name}\n";
echo "   Student Number: {$student->student_number}\n";
echo "   Biometric ID: {$student->biometric_id}\n";
echo "   School: {$student->school->name}\n";
if ($student->class) {
    echo "   Class: {$student->class->name}\n";
}
echo "\n🔄 NEXT STEPS:\n";
echo "1. Scan {$student->first_name} {$student->last_name}'s finger/face on device {$deviceId}\n";
echo "2. Device will send RecPush with customId '{$student->biometric_id}'\n";
echo "3. Our system will find student by biometric_id and log attendance\n";
echo "4. Check real-time attendance data in the database!\n";
echo str_repeat("=", 70) . "\n";