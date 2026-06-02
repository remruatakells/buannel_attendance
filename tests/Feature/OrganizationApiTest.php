<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\UserModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizations_can_be_listed(): void
    {
        Organization::factory()->create([
            'name' => 'Mizoram University',
            'type' => 'university',
        ]);
        Organization::factory()->create([
            'name' => 'Buannel',
            'type' => 'company',
        ]);

        $this->getJson('/api/organizations')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Buannel')
            ->assertJsonPath('data.1.name', 'Mizoram University');
    }

    public function test_authenticated_admin_only_lists_own_organization(): void
    {
        $buannel = Organization::factory()->create([
            'name' => 'Buannel',
            'type' => 'company',
        ]);
        Organization::factory()->create([
            'name' => 'Mizoram University',
            'type' => 'university',
        ]);
        $admin = UserModel::factory()->create([
            'employee_id' => 'ADMIN001',
            'is_admin' => true,
            'admin_access_token' => 'admin-token',
            'organization_id' => $buannel->id,
        ]);

        $this->getJson('/api/attendance/organizations', [
            'X-Admin-Access-Token' => $admin->admin_access_token,
        ])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $buannel->id)
            ->assertJsonPath('data.0.name', 'Buannel');
    }

    public function test_authenticated_admin_cannot_read_another_organization(): void
    {
        $buannel = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $admin = UserModel::factory()->create([
            'employee_id' => 'ADMIN001',
            'is_admin' => true,
            'admin_access_token' => 'admin-token',
            'organization_id' => $buannel->id,
        ]);

        $this->getJson("/api/attendance/organizations/{$otherOrganization->id}", [
            'X-Admin-Access-Token' => $admin->admin_access_token,
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Organization not found');
    }

    public function test_authenticated_admin_cannot_read_another_organizations_timing_or_policy(): void
    {
        $buannel = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $admin = UserModel::factory()->create([
            'employee_id' => 'ADMIN001',
            'is_admin' => true,
            'admin_access_token' => 'admin-token',
            'organization_id' => $buannel->id,
        ]);
        $headers = ['X-Admin-Access-Token' => $admin->admin_access_token];

        $this->getJson("/api/attendance/organizations/{$otherOrganization->id}/timing", $headers)
            ->assertNotFound()
            ->assertJsonPath('message', 'Organization not found');

        $this->getJson("/api/attendance/organizations/{$otherOrganization->id}/attendance-policy", $headers)
            ->assertNotFound()
            ->assertJsonPath('message', 'Organization not found');
    }

    public function test_organization_can_be_created(): void
    {
        $this->postJson('/api/organizations', [
            'name' => 'Buannel',
            'type' => 'company',
        ])
            ->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'Organization created')
            ->assertJsonPath('data.name', 'Buannel')
            ->assertJsonPath('data.type', 'company')
            ->assertJsonPath('data.timing.check_in_start', '09:00:00')
            ->assertJsonPath('data.timing.check_in_end', '10:00:00')
            ->assertJsonPath('data.timing.late_after', '09:30:00')
            ->assertJsonPath('data.timing.half_day_after', '13:00:00')
            ->assertJsonPath('data.timing.check_out_start', '16:00:00')
            ->assertJsonPath('data.attendance_policy.allow_half_day', true)
            ->assertJsonPath('data.attendance_policy.allow_leave', true)
            ->assertJsonPath('data.attendance_policy.annual_leave_limit', 0);

        $this->assertDatabaseHas('organizations', [
            'name' => 'Buannel',
            'type' => 'company',
        ]);

        $this->assertDatabaseHas('organization_timings', [
            'check_in_start' => '09:00:00',
            'check_in_end' => '10:00:00',
            'late_after' => '09:30:00',
            'half_day_after' => '13:00:00',
            'check_out_start' => '16:00:00',
        ]);

        $this->assertDatabaseHas('organization_attendance_policies', [
            'allow_half_day' => true,
            'allow_leave' => true,
            'annual_leave_limit' => 0,
        ]);
    }

    public function test_organization_type_must_be_supported(): void
    {
        $this->postJson('/api/organizations', [
            'name' => 'Invalid Org',
            'type' => 'department',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    public function test_organization_can_be_shown(): void
    {
        $organization = Organization::factory()->create([
            'name' => 'Buannel',
            'type' => 'company',
        ]);
        UserModel::factory()->create([
            'organization_id' => $organization->id,
        ]);

        $this->getJson("/api/organizations/{$organization->id}")
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.name', 'Buannel')
            ->assertJsonPath('data.users_count', 1);
    }

    public function test_organization_can_be_updated(): void
    {
        $organization = Organization::factory()->create([
            'name' => 'Old Name',
            'type' => 'organization',
        ]);

        $this->putJson("/api/organizations/{$organization->id}", [
            'name' => 'Mizoram University',
            'type' => 'university',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Organization updated')
            ->assertJsonPath('data.name', 'Mizoram University')
            ->assertJsonPath('data.type', 'university');

        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'name' => 'Mizoram University',
            'type' => 'university',
        ]);
    }

    public function test_organization_timing_can_be_shown_and_updated(): void
    {
        $organization = Organization::factory()->create();

        $this->getJson("/api/organizations/{$organization->id}/timing")
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.organization_id', $organization->id)
            ->assertJsonPath('data.check_in_start', '09:00:00');

        $this->putJson("/api/organizations/{$organization->id}/timing", [
            'check_in_start' => '10:00:00',
            'check_in_end' => '11:00:00',
            'late_after' => '10:30:00',
            'half_day_after' => '14:00:00',
            'check_out_start' => '17:00:00',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Organization timing updated')
            ->assertJsonPath('data.organization_id', $organization->id)
            ->assertJsonPath('data.check_in_start', '10:00:00')
            ->assertJsonPath('data.check_in_end', '11:00:00')
            ->assertJsonPath('data.late_after', '10:30:00')
            ->assertJsonPath('data.half_day_after', '14:00:00')
            ->assertJsonPath('data.check_out_start', '17:00:00');

        $this->assertDatabaseHas('organization_timings', [
            'organization_id' => $organization->id,
            'check_in_start' => '10:00:00',
            'check_in_end' => '11:00:00',
            'late_after' => '10:30:00',
            'half_day_after' => '14:00:00',
            'check_out_start' => '17:00:00',
        ]);

        $this->patchJson("/api/attendance/organizations/{$organization->id}/attendance-policy", [
            'allow_half_day' => false,
            'allow_leave' => true,
            'annual_leave_limit' => 2,
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Organization attendance policy updated')
            ->assertJsonPath('data.allow_half_day', false)
            ->assertJsonPath('data.allow_leave', true)
            ->assertJsonPath('data.annual_leave_limit', 2);

        $this->assertDatabaseHas('organization_attendance_policies', [
            'organization_id' => $organization->id,
            'allow_half_day' => false,
            'allow_leave' => true,
            'annual_leave_limit' => 2,
        ]);
    }

    public function test_organization_timing_late_time_must_be_inside_check_in_window(): void
    {
        $organization = Organization::factory()->create();

        $this->putJson("/api/organizations/{$organization->id}/timing", [
            'check_in_start' => '10:00:00',
            'check_in_end' => '11:00:00',
            'late_after' => '11:30:00',
            'check_out_start' => '17:00:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('late_after');
    }

    public function test_organization_can_be_deleted_when_no_users_belong_to_it(): void
    {
        $organization = Organization::factory()->create();

        $this->deleteJson("/api/organizations/{$organization->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Organization deleted');

        $this->assertModelMissing($organization);
    }

    public function test_organization_with_users_cannot_be_deleted(): void
    {
        $organization = Organization::factory()->create();
        UserModel::factory()->create([
            'organization_id' => $organization->id,
        ]);

        $this->deleteJson("/api/organizations/{$organization->id}")
            ->assertConflict()
            ->assertJsonPath('message', 'Organization has users');

        $this->assertModelExists($organization);
    }
}
