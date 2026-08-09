<?php

namespace Database\Factories;

use App\Models\Option;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Level>
 */
class LevelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // We'll determine the level based on the section type through the option
        $levelData = $this->getRandomLevelData();
        
        return [
            'option_id' => Option::factory(),
            'name' => $levelData['name'],
            'order' => $levelData['order'],
            'description' => $levelData['description'],
            'is_active' => true,
        ];
    }

    /**
     * Create Francophone levels (6ème to Terminale)
     */
    public function francophone()
    {
        $levels = [
            ['name' => 'Sixième (6ème)', 'order' => 1, 'description' => 'First year of secondary education'],
            ['name' => 'Cinquième (5ème)', 'order' => 2, 'description' => 'Second year of secondary education'],
            ['name' => 'Quatrième (4ème)', 'order' => 3, 'description' => 'Third year of secondary education'],
            ['name' => 'Troisième (3ème)', 'order' => 4, 'description' => 'Fourth year of secondary education - BEPC year'],
            ['name' => 'Seconde (2nde)', 'order' => 5, 'description' => 'Fifth year of secondary education'],
            ['name' => 'Première (1ère)', 'order' => 6, 'description' => 'Sixth year of secondary education - Probatoire year'],
            ['name' => 'Terminale (Tle)', 'order' => 7, 'description' => 'Final year of secondary education - Baccalauréat year'],
        ];
        
        $level = $this->faker->randomElement($levels);
        
        return $this->state(function (array $attributes) use ($level) {
            return [
                'name' => $level['name'],
                'order' => $level['order'],
                'description' => $level['description'],
            ];
        });
    }

    /**
     * Create Anglophone levels (Form 1 to Upper Sixth)
     */
    public function anglophone()
    {
        $levels = [
            ['name' => 'Form 1', 'order' => 1, 'description' => 'First year of secondary education'],
            ['name' => 'Form 2', 'order' => 2, 'description' => 'Second year of secondary education'],
            ['name' => 'Form 3', 'order' => 3, 'description' => 'Third year of secondary education'],
            ['name' => 'Form 4', 'order' => 4, 'description' => 'Fourth year of secondary education - GCE O/L year'],
            ['name' => 'Form 5 (Lower Sixth)', 'order' => 5, 'description' => 'Fifth year of secondary education'],
            ['name' => 'Form 6 (Upper Sixth)', 'order' => 6, 'description' => 'Final year of secondary education - GCE A/L year'],
        ];
        
        $level = $this->faker->randomElement($levels);
        
        return $this->state(function (array $attributes) use ($level) {
            return [
                'name' => $level['name'],
                'order' => $level['order'],
                'description' => $level['description'],
            ];
        });
    }

    /**
     * Create Technical levels
     */
    public function technical()
    {
        $levels = [
            ['name' => 'SIL (Seconde Industrielle)', 'order' => 1, 'description' => 'First year of technical education'],
            ['name' => 'PIB (Première Industrielle B)', 'order' => 2, 'description' => 'Second year of technical education'],
            ['name' => 'TIB (Terminale Industrielle B)', 'order' => 3, 'description' => 'Third year of technical education - CAP year'],
            ['name' => 'TIC (Terminale Industrielle C)', 'order' => 4, 'description' => 'Fourth year of technical education - BEP year'],
            ['name' => 'TID (Terminale Industrielle D)', 'order' => 5, 'description' => 'Fifth year of technical education - Bac Technique year'],
        ];
        
        $level = $this->faker->randomElement($levels);
        
        return $this->state(function (array $attributes) use ($level) {
            return [
                'name' => $level['name'],
                'order' => $level['order'],
                'description' => $level['description'],
            ];
        });
    }

    private function getRandomLevelData(): array
    {
        $allLevels = [
            // Francophone levels
            ['name' => 'Sixième (6ème)', 'order' => 1, 'description' => 'First year of secondary education'],
            ['name' => 'Cinquième (5ème)', 'order' => 2, 'description' => 'Second year of secondary education'],
            ['name' => 'Quatrième (4ème)', 'order' => 3, 'description' => 'Third year of secondary education'],
            ['name' => 'Troisième (3ème)', 'order' => 4, 'description' => 'Fourth year of secondary education - BEPC year'],
            ['name' => 'Seconde (2nde)', 'order' => 5, 'description' => 'Fifth year of secondary education'],
            ['name' => 'Première (1ère)', 'order' => 6, 'description' => 'Sixth year of secondary education - Probatoire year'],
            ['name' => 'Terminale (Tle)', 'order' => 7, 'description' => 'Final year of secondary education - Baccalauréat year'],
            
            // Anglophone levels
            ['name' => 'Form 1', 'order' => 1, 'description' => 'First year of secondary education'],
            ['name' => 'Form 2', 'order' => 2, 'description' => 'Second year of secondary education'],
            ['name' => 'Form 3', 'order' => 3, 'description' => 'Third year of secondary education'],
            ['name' => 'Form 4', 'order' => 4, 'description' => 'Fourth year of secondary education - GCE O/L year'],
            ['name' => 'Form 5 (Lower Sixth)', 'order' => 5, 'description' => 'Fifth year of secondary education'],
            ['name' => 'Form 6 (Upper Sixth)', 'order' => 6, 'description' => 'Final year of secondary education - GCE A/L year'],
            
            // Technical levels
            ['name' => 'SIL (Seconde Industrielle)', 'order' => 1, 'description' => 'First year of technical education'],
            ['name' => 'PIB (Première Industrielle B)', 'order' => 2, 'description' => 'Second year of technical education'],
            ['name' => 'TIB (Terminale Industrielle B)', 'order' => 3, 'description' => 'Third year of technical education - CAP year'],
            ['name' => 'TIC (Terminale Industrielle C)', 'order' => 4, 'description' => 'Fourth year of technical education - BEP year'],
            ['name' => 'TID (Terminale Industrielle D)', 'order' => 5, 'description' => 'Fifth year of technical education - Bac Technique year'],
        ];
        
        return $this->faker->randomElement($allLevels);
    }
}
