<?php

namespace Database\Factories;

use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Section>
 */
class SectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'name' => $this->faker->randomElement(['Francophone', 'Anglophone']),
            'description' => function (array $attributes) {
                return $attributes['name'] === 'Francophone' 
                    ? 'French-speaking section following the French educational system'
                    : 'English-speaking section following the Anglo-Saxon educational system';
            },
            'is_active' => true,
        ];
    }

    /**
     * Create a Francophone section
     */
    public function francophone()
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => 'Francophone',
                'description' => 'French-speaking section following the French educational system',
            ];
        });
    }

    /**
     * Create an Anglophone section
     */
    public function anglophone()
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => 'Anglophone',
                'description' => 'English-speaking section following the Anglo-Saxon educational system',
            ];
        });
    }
}
