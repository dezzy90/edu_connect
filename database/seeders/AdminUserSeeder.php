<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\AdminUser;
use App\Models\School;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create Super Admin
        AdminUser::create([
            'name' => 'Rod Connect Super Admin',
            'email' => 'super@rodconnect.com',
            'password' => Hash::make('password123'),
            'role' => 'super_admin',
            'school_id' => null,
            'is_active' => true,
        ]);

        // Create School Admins for each school
        $schools = School::all();
        
        foreach ($schools as $school) {
            AdminUser::create([
                'name' => $school->name . ' Administrator',
                'email' => strtolower(str_replace(' ', '', $school->name)) . '@admin.com',
                'password' => Hash::make('password123'),
                'role' => 'school_admin',
                'school_id' => $school->id,
                'is_active' => true,
            ]);
        }

        $this->command->info('Admin users created successfully!');
        $this->command->info('Super Admin: super@rodconnect.com / password123');
        $this->command->info('School Admins: [schoolname]@admin.com / password123');
    }
}
