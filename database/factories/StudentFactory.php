<?php

namespace Database\Factories;

use App\Models\School;
use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Student>
 */
class StudentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $gender = $this->faker->randomElement(['male', 'female']);
        $names = $this->generateCameroonianName($gender);
        
        return [
            'school_id' => School::factory(),
            'class_id' => SchoolClass::factory(),
            'student_number' => $this->generateStudentNumber(),
            'first_name' => $names['first'],
            'last_name' => $names['last'],
            'middle_name' => $this->faker->optional(0.6)->randomElement($names['middle']),
            'date_of_birth' => $this->faker->dateTimeBetween('-18 years', '-12 years'),
            'gender' => $gender,
            'address' => $this->generateCameroonianAddress(),
            'phone' => $this->faker->optional(0.4)->numerify('+237 6## ## ## ##'),
            'email' => $this->faker->optional(0.3)->email(),
            'emergency_contact' => $this->generateCameroonianPhone(),
            'medical_info' => $this->faker->optional(0.2)->sentence(),
            'biometric_id' => $this->faker->optional(0.8)->uuid(),
            'is_active' => true,
            'enrollment_date' => $this->faker->dateTimeBetween('-3 years', 'now'),
            'graduation_date' => $this->faker->optional(0.1)->dateTimeBetween('now', '+2 years'),
            'guardian_name' => $this->generateGuardianName(),
            'guardian_phone' => $this->generateCameroonianPhone(),
            'parent_link_code' => $this->generateLinkCode(),
            'parent_link_code_expires_at' => $this->faker->dateTimeBetween('now', '+1 year'),
            'parent_link_enabled' => true,
        ];
    }

    private function generateStudentNumber(): string
    {
        $year = date('Y');
        $number = $this->faker->unique()->numberBetween(1000, 9999);
        return $year . '/' . $number;
    }

    private function generateLinkCode(): string
    {
        return strtoupper($this->faker->bothify('??####??####'));
    }

    private function generateCameroonianName($gender): array
    {
        $maleFirstNames = [
            'Jean', 'Paul', 'Pierre', 'François', 'Joseph', 'André', 'Michel', 'David',
            'Emmanuel', 'Samuel', 'Daniel', 'Isaac', 'Abraham', 'Moses', 'John',
            'Peter', 'Mark', 'Luke', 'Matthew', 'James', 'Francis', 'Patrick',
            'Akono', 'Biya', 'Ekotto', 'Foe', 'Kotto', 'Mbarga', 'Ndoumbe', 'Owona',
            'Amadou', 'Hassan', 'Ibrahim', 'Issa', 'Mahamat', 'Oumarou',
            'Tabi', 'Fru', 'Ndi', 'Che', 'Arrey', 'Ayuk', 'Ewane', 'Ngwa'
        ];

        $femaleFirstNames = [
            'Marie', 'Angeline', 'Bernadette', 'Catherine', 'Delphine', 'Esperance',
            'Florence', 'Grace', 'Helene', 'Irene', 'Justine', 'Lydie', 'Martine',
            'Mary', 'Elizabeth', 'Faith', 'Hope', 'Charity', 'Joyce', 'Patience',
            'Aisha', 'Fatima', 'Khadija', 'Aminata', 'Mariam', 'Aicha',
            'Viviane', 'Solange', 'Nadine', 'Pascaline', 'Georgette', 'Sandrine',
            'Magaret', 'Comfort', 'Blessing', 'Queen', 'Princess', 'Divine'
        ];

        $lastNames = [
            'Ahidjo', 'Biya', 'Foe', 'Mbarga', 'Owona', 'Ekotto', 'Kotto', 'Ndoumbe',
            'Tchoungi', 'Ngounou', 'Njoya', 'Bamenda', 'Douala', 'Yaoundé',
            'Nkomo', 'Mvondo', 'Essomba', 'Manga', 'Bello', 'Hamadou',
            'Fouda', 'Mballa', 'Ndzana', 'Olinga', 'Abega', 'Belibi',
            'Tabi', 'Fru', 'Ndi', 'Che', 'Arrey', 'Ayuk', 'Ewane',
            'Hassan', 'Ibrahim', 'Amadou', 'Oumarou', 'Issa', 'Mahamat',
            'Ngomo', 'Ateba', 'Akoa', 'Anya', 'Eyenga', 'Minkoulou',
            'Betote', 'Mbassi', 'Ondoa', 'Tchoupe', 'Feudjio', 'Kamga'
        ];

        $middleNames = [
            'Claude', 'Marie', 'Joseph', 'Paul', 'Pierre', 'Anne', 'Rose',
            'Emmanuel', 'Grace', 'David', 'Sarah', 'Ruth', 'Esther', 'Daniel'
        ];

        $firstName = $gender === 'male' 
            ? $this->faker->randomElement($maleFirstNames)
            : $this->faker->randomElement($femaleFirstNames);

        return [
            'first' => $firstName,
            'last' => $this->faker->randomElement($lastNames),
            'middle' => $middleNames
        ];
    }

    private function generateCameroonianAddress(): string
    {
        $cities = ['Douala', 'Yaoundé', 'Bamenda', 'Garoua', 'Maroua', 'Bafoussam', 'Bertoua', 'Ngaoundéré'];
        $neighborhoods = [
            'Douala' => ['Akwa', 'Bonanjo', 'Deido', 'Makepe', 'Bonapriso', 'New Bell', 'Bali', 'Logbaba'],
            'Yaoundé' => ['Centre Ville', 'Bastos', 'Melen', 'Kondengui', 'Emombo', 'Ngousso', 'Etoug-Ebe'],
            'Bamenda' => ['Commercial Avenue', 'Ntarikon', 'Mile 4', 'Abakwa', 'Mankon', 'Nkwen'],
            'Garoua' => ['Centre', 'Bolloré', 'Djamboutou', 'Yelwa'],
            'Maroua' => ['Centre', 'Domayo', 'Pitoaré'],
            'Bafoussam' => ['Centre', 'Tamdja', 'Famla'],
            'Bertoua' => ['Centre', 'Mokolo'],
            'Ngaoundéré' => ['Centre', 'Sabongari', 'Haoussa']
        ];
        
        $city = $this->faker->randomElement($cities);
        $neighborhood = $this->faker->randomElement($neighborhoods[$city]);
        $street = $this->faker->optional(0.7)->sentence(2);
        
        return trim($street ? $street . ', ' . $neighborhood . ', ' . $city : $neighborhood . ', ' . $city);
    }

    private function generateCameroonianPhone(): string
    {
        $operators = ['650', '651', '652', '653', '654', '670', '671', '672', '673', '674', '675', '676', '677', '678', '679', '680', '681', '682', '683', '684', '685', '686', '687', '688', '689', '690', '691', '692', '693', '694', '695', '696', '697', '698', '699'];
        $operator = $this->faker->randomElement($operators);
        $number = $this->faker->numerify('######');
        return '+237 ' . $operator . ' ' . substr($number, 0, 2) . ' ' . substr($number, 2, 2) . ' ' . substr($number, 4, 2);
    }

    private function generateGuardianName(): string
    {
        $titles = ['Mr.', 'Mrs.', 'Ms.', 'Dr.', 'Prof.'];
        $names = $this->generateCameroonianName($this->faker->randomElement(['male', 'female']));
        $title = $this->faker->randomElement($titles);
        
        return $title . ' ' . $names['first'] . ' ' . $names['last'];
    }

    /**
     * Create a male student
     */
    public function male()
    {
        return $this->state(function (array $attributes) {
            $names = $this->generateCameroonianName('male');
            return [
                'gender' => 'male',
                'first_name' => $names['first'],
                'last_name' => $names['last'],
            ];
        });
    }

    /**
     * Create a female student
     */
    public function female()
    {
        return $this->state(function (array $attributes) {
            $names = $this->generateCameroonianName('female');
            return [
                'gender' => 'female',
                'first_name' => $names['first'],
                'last_name' => $names['last'],
            ];
        });
    }

    /**
     * Create an inactive student
     */
    public function inactive()
    {
        return $this->state(function (array $attributes) {
            return [
                'is_active' => false,
                'graduation_date' => $this->faker->dateTimeBetween('-2 years', 'now'),
            ];
        });
    }
}
