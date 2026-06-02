<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\StaffDetail;
use App\Models\UserModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_be_created_separately_from_attendance(): void
    {
        $organization = Organization::factory()->create([
            'name' => 'Buannel',
            'type' => 'company',
        ]);

        $response = $this->postJson('/api/users', [
            'employee_id' => 'EMP001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'phone_no' => '9000000001',
            'device_id' => 'MORPHO_01',
            'organization_id' => $organization->id,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.employee_id', 'EMP001')
            ->assertJsonPath('data.first_name', 'John')
            ->assertJsonPath('data.name', 'John Doe')
            ->assertJsonPath('data.phone_no', '9000000001')
            ->assertJsonPath('data.organization.id', $organization->id)
            ->assertJsonMissingPath('data.password');

        $this->assertDatabaseHas('users', [
            'employee_id' => 'EMP001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'phone_no' => '9000000001',
            'device_id' => 'MORPHO_01',
            'organization_id' => $organization->id,
        ]);
    }

    public function test_user_can_be_updated(): void
    {
        $user = UserModel::factory()->create([
            'employee_id' => 'EMP001',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $this->putJson("/api/users/{$user->id}", [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'phone_no' => '9000000002',
            'device_id' => 'MORPHO_02',
        ])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Jane')
            ->assertJsonPath('data.name', 'Jane Smith');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'phone_no' => '9000000002',
            'device_id' => 'MORPHO_02',
        ]);
    }

    public function test_user_can_be_created_with_profile_image_link(): void
    {
        $organization = Organization::factory()->create();

        $response = $this->postJson('/api/users', [
            'employee_id' => 'EMP001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'profile_image' => 'https://example.com/profile.jpg',
            'organization_id' => $organization->id,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.employee_id', 'EMP001')
            ->assertJsonPath('data.profile_image', 'https://example.com/profile.jpg');

        $this->assertDatabaseHas('users', [
            'employee_id' => 'EMP001',
            'profile_image' => 'https://example.com/profile.jpg',
            'organization_id' => $organization->id,
        ]);
    }

    public function test_user_profile_image_link_can_be_updated(): void
    {
        $user = UserModel::factory()->create([
            'profile_image' => 'https://example.com/old-profile.jpg',
        ]);

        $this->putJson("/api/users/{$user->id}", [
            'profile_image' => 'https://example.com/new-profile.png',
        ])
            ->assertOk()
            ->assertJsonPath('data.profile_image', 'https://example.com/new-profile.png');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'profile_image' => 'https://example.com/new-profile.png',
        ]);
    }

    public function test_user_index_is_scoped_to_viewers_organization(): void
    {
        $buannel = Organization::factory()->create();
        $university = Organization::factory()->create();

        UserModel::factory()->create([
            'employee_id' => 'ADMIN001',
            'organization_id' => $buannel->id,
        ]);
        UserModel::factory()->create([
            'employee_id' => 'EMP001',
            'organization_id' => $buannel->id,
        ]);
        UserModel::factory()->create([
            'employee_id' => 'EMP002',
            'organization_id' => $university->id,
        ]);

        $this->getJson('/api/users?viewer_employee_id=ADMIN001')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.employee_id', 'ADMIN001')
            ->assertJsonPath('data.1.employee_id', 'EMP001');
    }

    public function test_user_cannot_be_created_without_an_organization(): void
    {
        $this->postJson('/api/users', [
            'employee_id' => 'EMP001',
            'first_name' => 'John',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('organization_id');
    }

    public function test_scoped_viewer_cannot_create_user_in_another_organization(): void
    {
        $buannel = Organization::factory()->create();
        $university = Organization::factory()->create();

        UserModel::factory()->create([
            'employee_id' => 'ADMIN001',
            'organization_id' => $buannel->id,
        ]);

        $this->postJson('/api/users?viewer_employee_id=ADMIN001', [
            'employee_id' => 'EMP001',
            'first_name' => 'John',
            'organization_id' => $university->id,
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Organization not allowed');
    }

    public function test_scoped_viewer_cannot_move_user_to_another_organization(): void
    {
        $buannel = Organization::factory()->create();
        $university = Organization::factory()->create();

        UserModel::factory()->create([
            'employee_id' => 'ADMIN001',
            'organization_id' => $buannel->id,
        ]);
        $user = UserModel::factory()->create([
            'employee_id' => 'EMP001',
            'organization_id' => $buannel->id,
        ]);

        $this->putJson("/api/users/{$user->id}?viewer_employee_id=ADMIN001", [
            'organization_id' => $university->id,
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Organization not allowed');
    }

    public function test_admin_user_can_login_with_phone_number_and_password(): void
    {
        $user = UserModel::factory()->create([
            'phone_no' => '9999999999',
            'password' => Hash::make('secret123'),
            'is_admin' => true,
        ]);

        $this->postJson('/api/attendance/login', [
            'phone_no' => '9999999999',
            'password' => 'secret123',
        ])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.isAdmin', true)
            ->assertJsonMissingPath('data.password');
    }

    public function test_login_rejects_wrong_password(): void
    {
        UserModel::factory()->create([
            'phone_no' => '9999999999',
            'password' => Hash::make('secret123'),
            'is_admin' => true,
        ]);

        $this->postJson('/api/attendance/login', [
            'phone_no' => '9999999999',
            'password' => 'wrong-password',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('status', false);
    }

    public function test_login_rejects_non_admin_user(): void
    {
        UserModel::factory()->create([
            'phone_no' => '9999999999',
            'password' => Hash::make('secret123'),
            'is_admin' => false,
        ]);

        $this->postJson('/api/attendance/login', [
            'phone_no' => '9999999999',
            'password' => 'secret123',
        ])
            ->assertForbidden()
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'User is not admin');
    }

    public function test_organization_admin_can_see_staff_salary_in_their_organization(): void
    {
        $organization = Organization::factory()->create();
        $admin = UserModel::factory()->create([
            'employee_id' => 'ADMIN001',
            'is_admin' => true,
            'organization_id' => $organization->id,
            'admin_access_token' => 'admin-token',
        ]);
        $user = UserModel::factory()->create([
            'employee_id' => 'EMP001',
            'organization_id' => $organization->id,
        ]);

        StaffDetail::factory()->create([
            'user_id' => $admin->id,
            'salary' => 80000,
        ]);
        StaffDetail::factory()->create([
            'user_id' => $user->id,
            'salary' => 65000,
        ]);

        $this->getJson('/api/attendance/users/'.$user->id, [
            'X-Admin-Access-Token' => 'admin-token',
        ])
            ->assertOk()
            ->assertJsonPath('data.staff_detail.salary', 65000);
    }

    public function test_organization_admin_cannot_see_staff_salary_in_another_organization(): void
    {
        $adminOrganization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        UserModel::factory()->create([
            'employee_id' => 'ADMIN001',
            'is_admin' => true,
            'organization_id' => $adminOrganization->id,
            'admin_access_token' => 'admin-token',
        ]);
        $otherUser = UserModel::factory()->create([
            'employee_id' => 'EMP002',
            'organization_id' => $otherOrganization->id,
        ]);

        StaffDetail::factory()->create([
            'user_id' => $otherUser->id,
            'salary' => 65000,
        ]);

        $this->getJson('/api/attendance/users/'.$otherUser->id, [
            'X-Admin-Access-Token' => 'admin-token',
        ])
            ->assertNotFound()
            ->assertJsonMissingPath('data.staff_detail.salary');
    }

    public function test_organization_admin_can_update_staff_details_with_encrypted_salary(): void
    {
        $organization = Organization::factory()->create();
        UserModel::factory()->create([
            'employee_id' => 'ADMIN001',
            'is_admin' => true,
            'organization_id' => $organization->id,
            'admin_access_token' => 'admin-token',
        ]);
        $user = UserModel::factory()->create([
            'employee_id' => 'EMP001',
            'organization_id' => $organization->id,
        ]);

        $this->patchJson('/api/attendance/users/'.$user->id, [
            'staff_detail' => [
                'position' => 'Developer',
                'department' => 'Engineering',
                'join_date' => '2026-06-01',
                'salary' => 72000,
                'salary_currency' => 'USD',
                'salary_frequency' => 'monthly',
                'notes' => 'Updated by admin.',
            ],
        ], [
            'X-Admin-Access-Token' => 'admin-token',
        ])
            ->assertOk()
            ->assertJsonPath('data.staff_detail.position', 'Developer')
            ->assertJsonPath('data.staff_detail.salary', 72000);

        $rawSalary = DB::table('staff_details')
            ->where('user_id', $user->id)
            ->value('salary');

        $this->assertIsString($rawSalary);
        $this->assertNotSame('72000', $rawSalary);
        $this->assertNotSame('72000.00', $rawSalary);
    }
}
