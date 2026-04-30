<?php

namespace Tests\Feature;

use App\Models\UserModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_be_created_separately_from_attendance(): void
    {
        $response = $this->postJson('/api/users', [
            'employee_id' => 'EMP001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'phone_no' => '9000000001',
            'device_id' => 'MORPHO_01',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.employee_id', 'EMP001')
            ->assertJsonPath('data.first_name', 'John')
            ->assertJsonPath('data.name', 'John Doe')
            ->assertJsonPath('data.phone_no', '9000000001')
            ->assertJsonMissingPath('data.password');

        $this->assertDatabaseHas('users', [
            'employee_id' => 'EMP001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'phone_no' => '9000000001',
            'device_id' => 'MORPHO_01',
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
        $response = $this->postJson('/api/users', [
            'employee_id' => 'EMP001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'profile_image' => 'https://example.com/profile.jpg',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.employee_id', 'EMP001')
            ->assertJsonPath('data.profile_image', 'https://example.com/profile.jpg');

        $this->assertDatabaseHas('users', [
            'employee_id' => 'EMP001',
            'profile_image' => 'https://example.com/profile.jpg',
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
}
