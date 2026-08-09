<?php

namespace Database\Factories;

use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SchoolParent>
 */
class SchoolParentFactory extends Factory
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
            'title' => $this->getTitle($gender),
            'first_name' => $names['first'],
            'last_name' => $names['last'],
            'middle_name' => $this->faker->optional(0.4)->randomElement($names['middle']),
            'email' => $this->faker->unique()->email(),
            'phone' => $this->generateCameroonianPhone(),
            'alternate_phone' => $this->faker->optional(0.6)->numerify('+237 6## ## ## ##'),
            'address' => $this->generateCameroonianAddress(),
            'occupation' => $this->getCameroonianOccupation(),
            'workplace' => $this->faker->optional(0.8)->sentence(3),
            'relationship_type' => $this->faker->randomElement(['father', 'mother', 'guardian', 'uncle', 'aunt', 'grandparent']),
            'national_id' => $this->generateNationalId(),
            'is_active' => true,
        ];
    }

    private function getTitle($gender): string
    {
        if ($gender === 'male') {
            return $this->faker->randomElement(['Mr.', 'Dr.', 'Prof.', 'Eng.', 'Rev.']);
        } else {
            return $this->faker->randomElement(['Mrs.', 'Ms.', 'Dr.', 'Prof.', 'Rev.']);
        }
    }

    private function generateNationalId(): string
    {
        return $this->faker->numerify('###########');
    }

    private function getCameroonianOccupation(): string
    {
        $occupations = [
            // Government/Public Service
            'Civil Servant', 'Government Official', 'Teacher', 'Police Officer', 'Military Officer',
            'Customs Officer', 'Tax Collector', 'Hospital Administrator', 'Court Clerk',
            
            // Education
            'Primary School Teacher', 'Secondary School Teacher', 'University Lecturer',
            'School Principal', 'Education Inspector', 'Librarian',
            
            // Healthcare
            'Doctor', 'Nurse', 'Pharmacist', 'Medical Technician', 'Midwife', 'Dentist',
            'Veterinarian', 'Traditional Healer',
            
            // Business/Commerce
            'Trader', 'Shop Owner', 'Market Vendor', 'Import/Export Business', 'Wholesaler',
            'Restaurant Owner', 'Hotel Manager', 'Travel Agent', 'Insurance Agent',
            
            // Agriculture
            'Farmer', 'Cocoa Farmer', 'Coffee Farmer', 'Plantation Owner', 'Livestock Farmer',
            'Agricultural Officer', 'Fisherman', 'Agricultural Equipment Dealer',
            
            // Technical/Engineering
            'Engineer', 'Architect', 'Electrician', 'Mechanic', 'Plumber', 'Carpenter',
            'Mason', 'Welder', 'Computer Technician', 'Telecommunications Technician',
            
            // Transport
            'Taxi Driver', 'Bus Driver', 'Truck Driver', 'Transport Company Owner',
            'Airline Pilot', 'Ship Captain', 'Port Worker',
            
            // Finance/Banking
            'Bank Manager', 'Accountant', 'Financial Advisor', 'Microfinance Officer',
            'Credit Union Manager', 'Auditor', 'Tax Consultant',
            
            // Legal
            'Lawyer', 'Judge', 'Court Interpreter', 'Legal Assistant', 'Notary Public',
            
            // Religious
            'Pastor', 'Priest', 'Imam', 'Church Elder', 'Mission Worker',
            
            // Media/Communications
            'Journalist', 'Radio Presenter', 'TV Reporter', 'Cameraman', 'Sound Engineer',
            'Graphic Designer', 'Web Developer',
            
            // Arts/Culture
            'Musician', 'Artist', 'Sculptor', 'Cultural Promoter', 'Event Organizer',
            
            // Oil & Gas/Mining
            'Oil Company Worker', 'Mining Engineer', 'Geologist', 'Petroleum Engineer',
            
            // NGO/International Organizations
            'NGO Worker', 'Development Officer', 'Project Manager', 'Humanitarian Worker',
            
            // Self-employed
            'Entrepreneur', 'Consultant', 'Freelancer', 'Small Business Owner',
            
            // Traditional/Informal
            'Traditional Ruler', 'Village Chief', 'Traditional Musician', 'Craft Maker',
            'Food Vendor', 'Hairdresser', 'Tailor', 'Motorcycle Taxi Driver'
        ];
        
        return $this->faker->randomElement($occupations);
    }

    private function generateCameroonianName($gender): array
    {
        $maleFirstNames = [
            'Jean', 'Paul', 'Pierre', 'François', 'Joseph', 'André', 'Michel', 'David',
            'Emmanuel', 'Samuel', 'Daniel', 'Isaac', 'Abraham', 'Moses', 'John',
            'Peter', 'Mark', 'Luke', 'Matthew', 'James', 'Francis', 'Patrick',
            'Akono', 'Biya', 'Ekotto', 'Foe', 'Kotto', 'Mbarga', 'Ndoumbe', 'Owona',
            'Amadou', 'Hassan', 'Ibrahim', 'Issa', 'Mahamat', 'Oumarou',
            'Tabi', 'Fru', 'Ndi', 'Che', 'Arrey', 'Ayuk', 'Ewane', 'Ngwa',
            'Maurice', 'Robert', 'Antoine', 'Bernard', 'Claude', 'Henri', 'Louis'
        ];

        $femaleFirstNames = [
            'Marie', 'Angeline', 'Bernadette', 'Catherine', 'Delphine', 'Esperance',
            'Florence', 'Grace', 'Helene', 'Irene', 'Justine', 'Lydie', 'Martine',
            'Mary', 'Elizabeth', 'Faith', 'Hope', 'Charity', 'Joyce', 'Patience',
            'Aisha', 'Fatima', 'Khadija', 'Aminata', 'Mariam', 'Aicha',
            'Viviane', 'Solange', 'Nadine', 'Pascaline', 'Georgette', 'Sandrine',
            'Magaret', 'Comfort', 'Blessing', 'Queen', 'Princess', 'Divine',
            'Christine', 'Françoise', 'Monique', 'Sylvie', 'Brigitte', 'Nicole'
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

    /**
     * Create a father
     */
    public function father()
    {
        return $this->state(function (array $attributes) {
            $names = $this->generateCameroonianName('male');
            return [
                'title' => $this->faker->randomElement(['Mr.', 'Dr.', 'Prof.', 'Eng.']),
                'first_name' => $names['first'],
                'last_name' => $names['last'],
                'relationship_type' => 'father',
            ];
        });
    }

    /**
     * Create a mother
     */
    public function mother()
    {
        return $this->state(function (array $attributes) {
            $names = $this->generateCameroonianName('female');
            return [
                'title' => $this->faker->randomElement(['Mrs.', 'Ms.', 'Dr.', 'Prof.']),
                'first_name' => $names['first'],
                'last_name' => $names['last'],
                'relationship_type' => 'mother',
            ];
        });
    }

    /**
     * Create a guardian
     */
    public function guardian()
    {
        return $this->state(function (array $attributes) {
            return [
                'relationship_type' => $this->faker->randomElement(['guardian', 'uncle', 'aunt', 'grandparent']),
            ];
        });
    }
}
