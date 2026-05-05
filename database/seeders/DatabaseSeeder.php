<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\UserModel;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // UserModel::factory(10)->create();

        $organization = Organization::firstOrCreate(
            ['name' => 'Buannel'],
            ['type' => 'company'],
        );

        UserModel::factory()->create([
            'employee_id' => 'ADMIN001',
            'first_name' => 'Test',
            'last_name' => 'User',
            'phone_no' => '9999999999',
            'device_id' => null,
            'organization_id' => $organization->id,
            'name' => 'Test User',
        ]);

        $this->call([
            AttendanceSeeder::class,
        ]);
    }
}
