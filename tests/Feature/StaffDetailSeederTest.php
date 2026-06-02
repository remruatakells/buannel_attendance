<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\UserModel;
use Database\Seeders\StaffDetailSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StaffDetailSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_detail_seeder_creates_decryptable_salary_for_each_user(): void
    {
        $company = Organization::factory()->create(['type' => 'company']);
        $university = Organization::factory()->create(['type' => 'university']);

        $admin = UserModel::factory()->create([
            'is_admin' => true,
            'organization_id' => $company->id,
        ]);
        $staff = UserModel::factory()->create([
            'is_admin' => false,
            'organization_id' => $university->id,
        ]);

        $this->seed(StaffDetailSeeder::class);

        $admin->refresh()->load('staffDetail');
        $staff->refresh()->load('staffDetail');

        $this->assertSame(50000.0, $admin->staffDetail->salary);
        $this->assertSame('Administrator', $admin->staffDetail->position);
        $this->assertSame(40000.0, $staff->staffDetail->salary);
        $this->assertSame('Academic', $staff->staffDetail->department);

        $rawSalary = DB::table('staff_details')
            ->where('user_id', $admin->id)
            ->value('salary');

        $this->assertIsString($rawSalary);
        $this->assertNotSame('50000', $rawSalary);
        $this->assertNotSame('50000.00', $rawSalary);
    }
}
