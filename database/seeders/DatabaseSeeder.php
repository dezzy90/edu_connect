<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\School;
use App\Models\Section;
use App\Models\Option;
use App\Models\Level;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\SchoolParent;
use App\Models\BiometricDevice;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->command->info('🇨🇲 Seeding Cameroon School System Data...');

        // Create admin user
        $admin = User::firstOrCreate(
            ['email' => 'admin@rodconnect.cm'],
            [
                'name' => 'System Administrator',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'role' => 'super_admin'
            ]
        );
        $this->command->info('✅ Admin user created');

        // Create sample schools with realistic Cameroon data
        $schools = collect();
        
        // Douala Schools
        $lyceeDouala = School::create([
            'name' => 'Lycée Général Leclerc Douala',
            'code' => 'LGL-DLA',
            'address' => 'Akwa, Douala, Littoral Region, Cameroon',
            'phone' => '+237 233 42 15 67',
            'email' => 'lycee.leclerc@edu.cm',
            'is_active' => true,
        ]);

        $collegeLibermann = School::create([
            'name' => 'Collège Libermann Douala',
            'code' => 'CLB-DLA',
            'address' => 'Bonanjo, Douala, Littoral Region, Cameroon',
            'phone' => '+237 233 42 89 12',
            'email' => 'college.libermann@edu.cm',
            'is_active' => true,
        ]);

        // Yaoundé School
        $lyceeYaounde = School::create([
            'name' => 'Lycée Général Leclerc Yaoundé',
            'code' => 'LGL-YDE',
            'address' => 'Centre Ville, Yaoundé, Centre Region, Cameroon',
            'phone' => '+237 222 23 45 67',
            'email' => 'lycee.leclerc.yde@edu.cm',
            'is_active' => true,
        ]);

        // Bamenda School (Anglophone)
        $gbhsBamenda = School::create([
            'name' => 'Government Bilingual High School Bamenda',
            'code' => 'GBHS-BDA',
            'address' => 'Commercial Avenue, Bamenda, North West Region, Cameroon',
            'phone' => '+237 233 36 12 34',
            'email' => 'gbhs.bamenda@edu.cm',
            'is_active' => true,
        ]);

        $schools = collect([$lyceeDouala, $collegeLibermann, $lyceeYaounde, $gbhsBamenda]);
        $this->command->info('✅ Schools created');

        // Create sections for each school
        $schools->each(function ($school) {
            // Create Francophone section
            $francophone = Section::create([
                'school_id' => $school->id,
                'name' => 'Francophone',
                'code' => 'FR',
                'description' => 'French-speaking section following the French educational system',
                'is_active' => true,
            ]);

            // Create Anglophone section
            $anglophone = Section::create([
                'school_id' => $school->id,
                'name' => 'Anglophone',
                'code' => 'EN',
                'description' => 'English-speaking section following the Anglo-Saxon educational system',
                'is_active' => true,
            ]);

            // Create options for Francophone section
            $this->createFrancophoneOptions($francophone);
            
            // Create options for Anglophone section  
            $this->createAnglophoneOptions($anglophone);
        });
        $this->command->info('✅ Sections and Options created');

        // Create levels, classes, and students for the first school as example
        $mainSchool = $schools->first();
        $this->createSchoolStructure($mainSchool);
        $this->command->info('✅ Complete school structure created for ' . $mainSchool->name);

        // Create biometric devices for schools
        $schools->each(function ($school, $index) {
            BiometricDevice::create([
                'school_id' => $school->id,
                'name' => $school->name . ' - Main Entrance',
                'device_id' => 'DEVICE_' . $school->id . '_01',
                'mac_address' => fake()->macAddress(),
                'ip_address' => fake()->localIpv4(),
                'location' => 'Main Entrance',
                'device_type' => 'face_recognition',
                'firmware_version' => '1.0.0',
                'is_active' => true,
                'last_heartbeat' => fake()->dateTimeBetween('-1 hour', 'now'),
            ]);
        });
        $this->command->info('✅ Biometric devices created');

        $this->command->info('🎉 Cameroon School System seeding completed successfully!');
        $this->command->info('📊 Summary:');
        $this->command->info('   • Schools: ' . School::count());
        $this->command->info('   • Sections: ' . Section::count());
        $this->command->info('   • Options: ' . Option::count());
        $this->command->info('   • Levels: ' . Level::count());
        $this->command->info('   • Classes: ' . SchoolClass::count());
        $this->command->info('   • Students: ' . Student::count());
        $this->command->info('   • Parents: ' . SchoolParent::count());
        $this->command->info('   • Devices: ' . BiometricDevice::count());
    }

    private function createFrancophoneOptions(Section $section): void
    {
        // General options for Francophone section
        $generalOption = Option::create([
            'school_id' => $section->school_id,
            'section_id' => $section->id,
            'name' => 'Enseignement Général',
            'code' => 'GEN',
            'type' => 'general',
            'description' => 'General education option preparing students for university studies',
            'is_active' => true,
        ]);

        // Technical options for Francophone section
        $technicalOption = Option::create([
            'school_id' => $section->school_id,
            'section_id' => $section->id,
            'name' => 'Enseignement Technique',
            'code' => 'TECH',
            'type' => 'technical',
            'description' => 'Technical and vocational education option',
            'is_active' => true,
        ]);

        // Create levels for general option
        $this->createFrancophoneLevels($generalOption);
        
        // Create levels for technical option
        $this->createTechnicalLevels($technicalOption);
    }

    private function createAnglophoneOptions(Section $section): void
    {
        // General options for Anglophone section
        $generalOption = Option::create([
            'school_id' => $section->school_id,
            'section_id' => $section->id,
            'name' => 'General Education',
            'code' => 'GEN',
            'type' => 'general',
            'description' => 'General education option following Anglo-Saxon curriculum',
            'is_active' => true,
        ]);

        // Technical options for Anglophone section
        $technicalOption = Option::create([
            'school_id' => $section->school_id,
            'section_id' => $section->id,
            'name' => 'Technical Education',
            'code' => 'TECH',
            'type' => 'technical',
            'description' => 'Technical and vocational education option',
            'is_active' => true,
        ]);

        // Create levels for general option
        $this->createAnglophoneLevels($generalOption);
        
        // Create levels for technical option
        $this->createTechnicalLevels($technicalOption);
    }

    private function createFrancophoneLevels(Option $option): void
    {
        $levels = [
            ['name' => 'Sixième (6ème)', 'code' => '6E', 'order' => 1, 'description' => 'First year of secondary education'],
            ['name' => 'Cinquième (5ème)', 'code' => '5E', 'order' => 2, 'description' => 'Second year of secondary education'],
            ['name' => 'Quatrième (4ème)', 'code' => '4E', 'order' => 3, 'description' => 'Third year of secondary education'],
            ['name' => 'Troisième (3ème)', 'code' => '3E', 'order' => 4, 'description' => 'Fourth year of secondary education - BEPC year'],
            ['name' => 'Seconde (2nde)', 'code' => '2E', 'order' => 5, 'description' => 'Fifth year of secondary education'],
            ['name' => 'Première (1ère)', 'code' => '1E', 'order' => 6, 'description' => 'Sixth year of secondary education - Probatoire year'],
            ['name' => 'Terminale (Tle)', 'code' => 'TLE', 'order' => 7, 'description' => 'Final year of secondary education - Baccalauréat year'],
        ];

        foreach ($levels as $levelData) {
            Level::create([
                'school_id' => $option->school_id,
                'option_id' => $option->id,
                'name' => $levelData['name'],
                'code' => $levelData['code'],
                'order' => $levelData['order'],
                'description' => $levelData['description'],
                'is_active' => true,
            ]);
        }
    }

    private function createAnglophoneLevels(Option $option): void
    {
        $levels = [
            ['name' => 'Form 1', 'code' => 'F1', 'order' => 1, 'description' => 'First year of secondary education'],
            ['name' => 'Form 2', 'code' => 'F2', 'order' => 2, 'description' => 'Second year of secondary education'],
            ['name' => 'Form 3', 'code' => 'F3', 'order' => 3, 'description' => 'Third year of secondary education'],
            ['name' => 'Form 4', 'code' => 'F4', 'order' => 4, 'description' => 'Fourth year of secondary education - GCE O/L year'],
            ['name' => 'Form 5 (Lower Sixth)', 'code' => 'F5', 'order' => 5, 'description' => 'Fifth year of secondary education'],
            ['name' => 'Form 6 (Upper Sixth)', 'code' => 'F6', 'order' => 6, 'description' => 'Final year of secondary education - GCE A/L year'],
        ];

        foreach ($levels as $levelData) {
            Level::create([
                'school_id' => $option->school_id,
                'option_id' => $option->id,
                'name' => $levelData['name'],
                'code' => $levelData['code'],
                'order' => $levelData['order'],
                'description' => $levelData['description'],
                'is_active' => true,
            ]);
        }
    }

    private function createTechnicalLevels(Option $option): void
    {
        $levels = [
            ['name' => 'SIL (Seconde Industrielle)', 'code' => 'SIL', 'order' => 1, 'description' => 'First year of technical education'],
            ['name' => 'PIB (Première Industrielle B)', 'code' => 'PIB', 'order' => 2, 'description' => 'Second year of technical education'],
            ['name' => 'TIB (Terminale Industrielle B)', 'code' => 'TIB', 'order' => 3, 'description' => 'Third year of technical education - CAP year'],
            ['name' => 'TIC (Terminale Industrielle C)', 'code' => 'TIC', 'order' => 4, 'description' => 'Fourth year of technical education - BEP year'],
            ['name' => 'TID (Terminale Industrielle D)', 'code' => 'TID', 'order' => 5, 'description' => 'Fifth year of technical education - Bac Technique year'],
        ];

        foreach ($levels as $levelData) {
            Level::create([
                'school_id' => $option->school_id,
                'option_id' => $option->id,
                'name' => $levelData['name'],
                'code' => $levelData['code'],
                'order' => $levelData['order'],
                'description' => $levelData['description'],
                'is_active' => true,
            ]);
        }
    }

    private function createSchoolStructure(School $school): void
    {
        // Get some levels from this school
        $levels = Level::whereHas('option.section', function ($query) use ($school) {
            $query->where('school_id', $school->id);
        })->take(5)->get();

        foreach ($levels as $level) {
            // Create 2-3 classes per level
            $classCount = rand(2, 3);
            for ($i = 1; $i <= $classCount; $i++) {
                $class = SchoolClass::create([
                    'school_id' => $school->id,
                    'level_id' => $level->id,
                    'name' => 'A' . $i,
                    'code' => 'A' . $i,
                    'academic_year' => '2024-2025',
                    'capacity' => rand(35, 55),
                    'is_active' => true,
                ]);

                // Create 15-25 students per class
                $studentCount = rand(15, 25);
                for ($j = 1; $j <= $studentCount; $j++) {
                    $student = Student::create([
                        'school_id' => $school->id,
                        'class_id' => $class->id,
                        'student_number' => date('Y') . '/' . str_pad($class->id * 1000 + $j, 4, '0', STR_PAD_LEFT),
                        'first_name' => $this->getRandomCameroonianName('first'),
                        'last_name' => $this->getRandomCameroonianName('last'),
                        'middle_name' => rand(0, 1) ? $this->getRandomCameroonianName('middle') : null,
                        'date_of_birth' => fake()->dateTimeBetween('-18 years', '-12 years'),
                        'gender' => fake()->randomElement(['male', 'female']),
                        'address' => $this->generateCameroonianAddress(),
                        'phone' => rand(0, 1) ? $this->generateCameroonianPhone() : null,
                        'emergency_contact' => $this->generateCameroonianPhone(),
                        'guardian_name' => $this->getRandomCameroonianName('full'),
                        'guardian_phone' => $this->generateCameroonianPhone(),
                        'parent_link_code' => strtoupper(fake()->bothify('??####??####')),
                        'parent_link_code_expires_at' => fake()->dateTimeBetween('now', '+1 year'),
                        'parent_link_enabled' => true,
                        'biometric_id' => fake()->uuid(),
                        'is_active' => true,
                        'enrollment_date' => fake()->dateTimeBetween('-3 years', 'now'),
                    ]);

                    // Create parents for some students
                    if (rand(0, 1)) {
                        $parent = SchoolParent::create([
                            'first_name' => $this->getRandomCameroonianName('first'),
                            'last_name' => $student->last_name, // Same family name
                            'phone' => $this->generateCameroonianPhone(),
                            'email' => fake()->unique()->email(),
                            'address' => $student->address,
                            'occupation' => fake()->randomElement([
                                'Teacher', 'Trader', 'Civil Servant', 'Farmer', 'Doctor', 'Nurse',
                                'Engineer', 'Lawyer', 'Business Owner', 'Mechanic', 'Driver'
                            ]),
                            'workplace' => fake()->optional(0.8)->sentence(3),
                            'emergency_contact' => $this->generateCameroonianPhone(),
                            'is_active' => true,
                        ]);

                        // Link parent to student
                        $student->parents()->attach($parent->id, [
                            'link_code' => strtoupper(fake()->bothify('??####')),
                            'relationship_type' => fake()->randomElement(['father', 'mother', 'guardian']),
                            'is_primary' => true,
                            'linked_at' => now(),
                        ]);
                    }
                }
            }
        }
    }

    private function getRandomCameroonianName($type)
    {
        $firstNames = [
            'Jean', 'Marie', 'Paul', 'Pierre', 'François', 'Joseph', 'André', 'Michel',
            'David', 'Emmanuel', 'Samuel', 'Daniel', 'Isaac', 'Abraham', 'Moses',
            'Aisha', 'Fatima', 'Khadija', 'Aminata', 'Mariam', 'Angeline', 'Catherine'
        ];
        
        $lastNames = [
            'Ahidjo', 'Biya', 'Foe', 'Mbarga', 'Owona', 'Ekotto', 'Kotto', 'Ndoumbe',
            'Tchoungi', 'Ngounou', 'Njoya', 'Nkomo', 'Mvondo', 'Essomba'
        ];
        
        $middleNames = ['Marie', 'Joseph', 'Paul', 'Grace', 'Emmanuel', 'Rose'];

        switch ($type) {
            case 'first':
                return fake()->randomElement($firstNames);
            case 'last':
                return fake()->randomElement($lastNames);
            case 'middle':
                return fake()->randomElement($middleNames);
            case 'full':
                return fake()->randomElement(['Mr.', 'Mrs.']) . ' ' . 
                       fake()->randomElement($firstNames) . ' ' . 
                       fake()->randomElement($lastNames);
            default:
                return fake()->randomElement($firstNames);
        }
    }

    private function generateCameroonianAddress(): string
    {
        $cities = ['Douala', 'Yaoundé', 'Bamenda', 'Garoua', 'Bafoussam'];
        $neighborhoods = ['Centre', 'Akwa', 'Bonanjo', 'Deido', 'Bastos', 'Melen'];
        
        return fake()->randomElement($neighborhoods) . ', ' . fake()->randomElement($cities) . ', Cameroon';
    }

    private function generateCameroonianPhone(): string
    {
        $operators = ['650', '651', '652', '670', '671', '677', '678', '679', '680', '681'];
        return '+237 ' . fake()->randomElement($operators) . ' ' . fake()->numerify('## ## ##');
    }
}
