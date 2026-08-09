<?php

namespace Database\Factories;

use App\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Option>
 */
class OptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = $this->faker->randomElement(['technical', 'general']);
        
        $technicalOptions = [
            'Génie Civil', 'Électricité', 'Mécanique Auto', 'Électronique', 'Informatique',
            'Secrétariat Bureautique', 'Comptabilité', 'Commerce', 'Hôtellerie',
            'Menuiserie', 'Maçonnerie', 'Plomberie'
        ];
        
        $generalOptions = [
            'Littéraire (A)', 'Sciences Économiques (B)', 'Sciences Mathématiques (C)', 
            'Sciences Biologiques (D)', 'Sciences et Technologies (E)', 'Arts (ART)',
            'Musique (MUS)', 'Langues Vivantes'
        ];
        
        return [
            'section_id' => Section::factory(),
            'name' => $type === 'technical' 
                ? $this->faker->randomElement($technicalOptions)
                : $this->faker->randomElement($generalOptions),
            'type' => $type,
            'description' => $this->getOptionDescription($type),
            'is_active' => true,
        ];
    }

    /**
     * Create a technical option
     */
    public function technical()
    {
        return $this->state(function (array $attributes) {
            $technicalOptions = [
                'Génie Civil', 'Électricité', 'Mécanique Auto', 'Électronique', 'Informatique',
                'Secrétariat Bureautique', 'Comptabilité', 'Commerce', 'Hôtellerie',
                'Menuiserie', 'Maçonnerie', 'Plomberie'
            ];
            
            return [
                'name' => $this->faker->randomElement($technicalOptions),
                'type' => 'technical',
                'description' => 'Technical and vocational education option',
            ];
        });
    }

    /**
     * Create a general option
     */
    public function general()
    {
        return $this->state(function (array $attributes) {
            $generalOptions = [
                'Littéraire (A)', 'Sciences Économiques (B)', 'Sciences Mathématiques (C)', 
                'Sciences Biologiques (D)', 'Sciences et Technologies (E)', 'Arts (ART)',
                'Musique (MUS)', 'Langues Vivantes'
            ];
            
            return [
                'name' => $this->faker->randomElement($generalOptions),
                'type' => 'general',
                'description' => 'General education option',
            ];
        });
    }

    private function getOptionDescription($type): string
    {
        return $type === 'technical' 
            ? 'Technical and vocational education option preparing students for professional careers'
            : 'General education option preparing students for university studies';
    }
}
