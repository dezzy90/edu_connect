<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\School>
 */
class SchoolFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $schoolTypes = ['Government', 'Private Catholic', 'Private Presbyterian', 'Private Islamic', 'Private Lay'];
        $schoolType = $this->faker->randomElement($schoolTypes);
        
        $cities = [
            'Douala' => ['Akwa', 'Bonanjo', 'Deido', 'Makepe', 'Bonapriso', 'New Bell', 'Bali'],
            'Yaoundé' => ['Centre Ville', 'Bastos', 'Melen', 'Kondengui', 'Emombo', 'Ngousso', 'Etoug-Ebe'],
            'Bamenda' => ['Commercial Avenue', 'Ntarikon', 'Mile 4', 'Abakwa', 'Mankon', 'Nkwen'],
            'Garoua' => ['Centre', 'Bolloré', 'Djamboutou', 'Yelwa'],
            'Maroua' => ['Centre', 'Domayo', 'Pitoaré'],
            'Bafoussam' => ['Centre', 'Tamdja', 'Famla'],
            'Bertoua' => ['Centre', 'Mokolo'],
            'Ngaoundéré' => ['Centre', 'Sabongari', 'Haoussa']
        ];
        
        $city = $this->faker->randomElement(array_keys($cities));
        $neighborhood = $this->faker->randomElement($cities[$city]);
        
        $schoolNames = [
            'Government' => [
                'Lycée Général Leclerc', 'Lycée de Deido', 'Lycée Bilingue de Douala',
                'Lycée Général Leclerc Yaoundé', 'Lycée de Ngoa-Ekellé', 'Lycée Bilingue de Yaoundé',
                'Government Bilingual High School Bamenda', 'Government High School Bamenda',
                'Lycée de Garoua', 'Government High School Maroua', 'Lycée de Bafoussam'
            ],
            'Private Catholic' => [
                'Collège Saint Michel', 'Collège de la Salle', 'Collège Libermann',
                'Saint Joseph College', 'Sacred Heart College', 'Our Lady of Lourdes College',
                'Collège Vogt', 'Collège de la Retraite'
            ],
            'Private Presbyterian' => [
                'Presbyterian High School', 'Christ the King College', 'Presbyterian Secondary School',
                'Cameroon Protestant College', 'Presbyterian Comprehensive College'
            ],
            'Private Islamic' => [
                'Islamic Secondary School', 'Madrasa Secondary', 'Al-Azhar College'
            ],
            'Private Lay' => [
                'Collège Adventiste', 'New Covenant College', 'Rainbow International School',
                'Camtech College', 'Excellence Private School', 'Future Leaders Academy',
                'Bilingual Grammar School', 'International School of Cameroon'
            ]
        ];
        
        $name = $this->faker->randomElement($schoolNames[$schoolType]) . ' ' . $city;
        
        return [
            'name' => $name,
            'code' => strtoupper($this->faker->unique()->bothify('SCH###??')),
            'type' => $schoolType,
            'address' => $neighborhood . ', ' . $city . ', Cameroon',
            'city' => $city,
            'region' => $this->getRegionByCity($city),
            'country' => 'Cameroon',
            'phone' => $this->generateCameroonianPhone(),
            'email' => strtolower(str_replace(' ', '', $name)) . '@edu.cm',
            'website' => 'www.' . strtolower(str_replace([' ', '\''], ['', ''], $name)) . '.cm',
            'principal_name' => $this->generateCameroonianName(),
            'established_year' => $this->faker->numberBetween(1960, 2020),
            'student_capacity' => $this->faker->numberBetween(200, 2000),
            'is_active' => true,
        ];
    }
    
    private function getRegionByCity($city): string
    {
        $cityToRegion = [
            'Douala' => 'Littoral',
            'Yaoundé' => 'Centre',
            'Bamenda' => 'North West',
            'Garoua' => 'North',
            'Maroua' => 'Far North',
            'Bafoussam' => 'West',
            'Bertoua' => 'East',
            'Ngaoundéré' => 'Adamawa'
        ];
        
        return $cityToRegion[$city] ?? 'Centre';
    }
    
    private function generateCameroonianPhone(): string
    {
        $operators = ['237650', '237651', '237652', '237653', '237654', '237670', '237671', '237672', '237673', '237674', '237675', '237676', '237677', '237678', '237679', '237680', '237681', '237682', '237683', '237684', '237685', '237686', '237687', '237688', '237689', '237690', '237691', '237692', '237693', '237694', '237695', '237696', '237697', '237698', '237699'];
        return $this->faker->randomElement($operators) . $this->faker->randomNumber(6, true);
    }
    
    private function generateCameroonianName(): string
    {
        $firstNames = [
            'Jean', 'Marie', 'Paul', 'Pierre', 'François', 'Joseph', 'André', 'Michel',
            'David', 'Emmanuel', 'Samuel', 'Daniel', 'Isaac', 'Abraham', 'Moses',
            'Aisha', 'Fatima', 'Khadija', 'Aminata', 'Mariam',
            'Akono', 'Biya', 'Ekotto', 'Foe', 'Kotto', 'Mbarga', 'Ndoumbe', 'Owona',
            'Angeline', 'Bernadette', 'Catherine', 'Delphine', 'Esperance', 'Florence',
            'John', 'Peter', 'Mark', 'Luke', 'Matthew', 'James', 'Francis',
            'Mary', 'Elizabeth', 'Grace', 'Faith', 'Hope', 'Charity'
        ];
        
        $lastNames = [
            'Ahidjo', 'Biya', 'Foe', 'Mbarga', 'Owona', 'Ekotto', 'Kotto', 'Ndoumbe',
            'Tchoungi', 'Ngounou', 'Njoya', 'Bamenda', 'Douala', 'Yaoundé',
            'Nkomo', 'Mvondo', 'Essomba', 'Manga', 'Bello', 'Hamadou',
            'Fouda', 'Mballa', 'Ndzana', 'Olinga', 'Abega', 'Belibi',
            'Tabi', 'Fru', 'Ndi', 'Che', 'Arrey', 'Ayuk', 'Ewane',
            'Hassan', 'Ibrahim', 'Amadou', 'Oumarou', 'Issa', 'Mahamat'
        ];
        
        return $this->faker->randomElement($firstNames) . ' ' . $this->faker->randomElement($lastNames);
    }
}
