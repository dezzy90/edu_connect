<?php

namespace Database\Factories;

use App\Models\Level;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SchoolClass>
 */
class SchoolClassFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $classLetters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
        $classNumbers = ['1', '2', '3', '4', '5'];
        
        // Generate class name with Cameroonian conventions
        $classLetter = $this->faker->randomElement($classLetters);
        $classNumber = $this->faker->randomElement($classNumbers);
        
        return [
            'level_id' => Level::factory(),
            'name' => $classLetter . $classNumber,
            'description' => function (array $attributes) use ($classLetter, $classNumber) {
                return "Class {$classLetter}{$classNumber}";
            },
            'room_number' => $this->faker->optional(0.8)->numerify('Room ###'),
            'capacity' => $this->faker->numberBetween(25, 60),
            'is_active' => true,
        ];
    }

    /**
     * Create class with specific naming pattern
     */
    public function withName($name)
    {
        return $this->state(function (array $attributes) use ($name) {
            return [
                'name' => $name,
                'description' => "Class {$name}",
            ];
        });
    }

    /**
     * Create classes for Francophone system
     */
    public function francophone()
    {
        $francoClasses = [
            'A1', 'A2', 'A3', 'A4', 'A5',
            'B1', 'B2', 'B3', 'B4', 'B5',
            'C1', 'C2', 'C3', 'C4', 'C5',
            'D1', 'D2', 'D3', 'D4', 'D5',
        ];
        
        $className = $this->faker->randomElement($francoClasses);
        
        return $this->state(function (array $attributes) use ($className) {
            return [
                'name' => $className,
                'description' => "Classe {$className}",
            ];
        });
    }

    /**
     * Create classes for Anglophone system  
     */
    public function anglophone()
    {
        $angloClasses = [
            'A', 'B', 'C', 'D', 'E', 'F',
            'Gold', 'Silver', 'Bronze', 
            'Red', 'Blue', 'Green', 'Yellow',
            'Alpha', 'Beta', 'Gamma', 'Delta'
        ];
        
        $className = $this->faker->randomElement($angloClasses);
        
        return $this->state(function (array $attributes) use ($className) {
            return [
                'name' => $className,
                'description' => "Class {$className}",
            ];
        });
    }

    /**
     * Create technical classes
     */
    public function technical()
    {
        $techClasses = [
            'ELEC1', 'ELEC2', 'ELEC3',
            'MECA1', 'MECA2', 'MECA3',
            'INFO1', 'INFO2', 'INFO3',
            'GC1', 'GC2', 'GC3',
            'COMP1', 'COMP2', 'COMP3',
            'SEC1', 'SEC2', 'SEC3'
        ];
        
        $className = $this->faker->randomElement($techClasses);
        
        return $this->state(function (array $attributes) use ($className) {
            return [
                'name' => $className,
                'description' => "Technical Class {$className}",
            ];
        });
    }

    /**
     * Create a large capacity class
     */
    public function largeClass()
    {
        return $this->state(function (array $attributes) {
            return [
                'capacity' => $this->faker->numberBetween(50, 80),
            ];
        });
    }

    /**
     * Create a small capacity class
     */
    public function smallClass()
    {
        return $this->state(function (array $attributes) {
            return [
                'capacity' => $this->faker->numberBetween(15, 35),
            ];
        });
    }
}
