<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\UserModel;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AttendanceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_check_in_uses_existing_user_profile_separately_from_attendance(): void
    {
        Carbon::setTestNow('2026-04-29 09:15:00 AM');

        $user = UserModel::factory()->create([
            'employee_id' => 'EMP001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'device_id' => 'MORPHO_01',
        ]);

        $response = $this->postJson('/api/attendance', [
            'user_id' => 'EMP001',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('action', 'check_in')
            ->assertJsonPath('data.user.employee_id', 'EMP001')
            ->assertJsonPath('data.user.first_name', 'John')
            ->assertJsonPath('data.check_in', '09:15:00 AM');

        $this->assertDatabaseHas('users', [
            'employee_id' => 'EMP001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'device_id' => 'MORPHO_01',
        ]);

        $this->assertDatabaseHas('attendances', [
            'user_id' => $user->id,
            'attendance_date' => '2026-04-29',
            'check_in' => '09:15:00',
            'status' => 'present',
        ]);

        $this->assertFalse(Schema::hasColumn('attendances', 'first_name'));
        $this->assertFalse(Schema::hasColumn('attendances', 'last_name'));
        $this->assertFalse(Schema::hasColumn('attendances', 'device_id'));
    }

    public function test_single_attendance_api_checks_out_after_check_in(): void
    {
        Carbon::setTestNow('2026-04-29 09:15:00 AM');

        $user = UserModel::factory()->create([
            'employee_id' => 'EMP001',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $payload = ['user_id' => 'EMP001'];

        $this->postJson('/api/attendance', $payload)
            ->assertOk()
            ->assertJsonPath('action', 'check_in')
            ->assertJsonPath('data.check_in', '09:15:00 AM')
            ->assertJsonPath('data.check_out', null);

        Carbon::setTestNow('2026-04-29 05:30:00 PM');

        $this->postJson('/api/attendance', $payload)
            ->assertOk()
            ->assertJsonPath('action', 'check_out')
            ->assertJsonPath('data.check_out', '05:30:00 PM');

        $this->assertDatabaseHas('attendances', [
            'user_id' => $user->id,
            'attendance_date' => '2026-04-29',
            'check_in' => '09:15:00',
            'check_out' => '17:30:00',
        ]);
    }

    public function test_single_attendance_api_rejects_after_check_out_is_complete(): void
    {
        Carbon::setTestNow('2026-04-29 09:15:00 AM');

        UserModel::factory()->create([
            'employee_id' => 'EMP001',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $payload = ['user_id' => 'EMP001'];

        $this->postJson('/api/attendance', $payload)->assertOk();

        Carbon::setTestNow('2026-04-29 05:30:00 PM');

        $this->postJson('/api/attendance', $payload)->assertOk();

        Carbon::setTestNow('2026-04-29 06:00 PM');

        $response = $this->postJson('/api/attendance', $payload)
            ->assertConflict()
            ->assertJsonPath('action', 'completed')
            ->assertJsonPath('message', 'Already checked out today');

        $this->assertFalse($response->json('status'));
    }

    public function test_check_in_returns_not_found_for_unknown_user(): void
    {
        $this->postJson('/api/attendance', [
            'user_id' => 'EMP404',
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'User not found');
    }

    public function test_existing_user_can_check_in_with_employee_code_only(): void
    {
        Carbon::setTestNow('2026-04-29 09:15:00 AM');

        UserModel::factory()->create([
            'employee_id' => 'EMP001',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $this->postJson('/api/attendance', [
            'user_id' => 'EMP001',
        ])
            ->assertOk()
            ->assertJsonPath('action', 'check_in')
            ->assertJsonPath('data.user.employee_id', 'EMP001')
            ->assertJsonPath('data.user.first_name', 'John');
    }

    public function test_check_in_is_rejected_before_allowed_window(): void
    {
        Carbon::setTestNow('2026-04-29 08:59:59 AM');

        UserModel::factory()->create([
            'employee_id' => 'EMP001',
        ]);

        $this->postJson('/api/attendance', [
            'user_id' => 'EMP001',
        ])
            ->assertConflict()
            ->assertJsonPath('status', false)
            ->assertJsonPath('action', 'check_in_closed');

        $this->assertDatabaseMissing('attendances', [
            'attendance_date' => '2026-04-29',
        ]);
    }

    public function test_check_in_is_rejected_after_allowed_window(): void
    {
        Carbon::setTestNow('2026-04-29 10:00:01 AM');

        UserModel::factory()->create([
            'employee_id' => 'EMP001',
        ]);

        $this->postJson('/api/attendance', [
            'user_id' => 'EMP001',
        ])
            ->assertConflict()
            ->assertJsonPath('status', false)
            ->assertJsonPath('action', 'check_in_closed');

        $this->assertDatabaseMissing('attendances', [
            'attendance_date' => '2026-04-29',
        ]);
    }

    public function test_check_in_accepts_end_of_allowed_window(): void
    {
        Carbon::setTestNow('2026-04-29 10:00:00 AM');

        $user = UserModel::factory()->create([
            'employee_id' => 'EMP001',
        ]);

        $this->postJson('/api/attendance', [
            'user_id' => 'EMP001',
        ])
            ->assertOk()
            ->assertJsonPath('action', 'check_in')
            ->assertJsonPath('data.check_in', '10:00:00 AM');

        $this->assertDatabaseHas('attendances', [
            'user_id' => $user->id,
            'attendance_date' => '2026-04-29',
            'check_in' => '10:00:00',
        ]);
    }

    public function test_check_out_is_rejected_before_allowed_window(): void
    {
        Carbon::setTestNow('2026-04-29 09:15:00 AM');

        $user = UserModel::factory()->create([
            'employee_id' => 'EMP001',
        ]);

        Attendance::create([
            'user_id' => $user->id,
            'attendance_date' => '2026-04-29',
            'check_in' => '09:15:00 AM',
            'status' => 'present',
        ]);

        Carbon::setTestNow('2026-04-29 03:59:59 PM');

        $this->postJson('/api/attendance', [
            'user_id' => 'EMP001',
        ])
            ->assertConflict()
            ->assertJsonPath('status', false)
            ->assertJsonPath('action', 'check_out_closed');

        $this->assertDatabaseHas('attendances', [
            'user_id' => $user->id,
            'attendance_date' => '2026-04-29',
            'check_out' => null,
        ]);
    }

    public function test_check_out_accepts_start_of_allowed_window(): void
    {
        Carbon::setTestNow('2026-04-29 09:15:00 AM');

        $user = UserModel::factory()->create([
            'employee_id' => 'EMP001',
        ]);

        Attendance::create([
            'user_id' => $user->id,
            'attendance_date' => '2026-04-29',
            'check_in' => '09:15:00 AM',
            'status' => 'present',
        ]);

        Carbon::setTestNow('2026-04-29 04:00:00 PM');

        $this->postJson('/api/attendance', [
            'user_id' => 'EMP001',
        ])
            ->assertOk()
            ->assertJsonPath('action', 'check_out')
            ->assertJsonPath('data.check_out', '04:00:00 PM');

        $this->assertDatabaseHas('attendances', [
            'user_id' => $user->id,
            'attendance_date' => '2026-04-29',
            'check_out' => '16:00:00',
        ]);
    }

    public function test_single_attendance_api_checks_out_existing_open_attendance(): void
    {
        Carbon::setTestNow('2026-04-29 09:15:00 AM');

        $user = UserModel::factory()->create([
            'employee_id' => 'EMP001',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        Attendance::create([
            'user_id' => $user->id,
            'attendance_date' => '2026-04-29',
            'check_in' => '09:15:00 AM',
            'status' => 'present',
        ]);

        Carbon::setTestNow('2026-04-29 05:30:00 PM');

        $this->postJson('/api/attendance', [
            'user_id' => 'EMP001',
        ])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('action', 'check_out')
            ->assertJsonPath('data.check_out', '05:30:00 PM')
            ->assertJsonPath('data.user.employee_id', 'EMP001');

        $this->assertDatabaseHas('attendances', [
            'user_id' => $user->id,
            'attendance_date' => '2026-04-29',
            'check_out' => '17:30:00',
        ]);
    }

    public function test_user_attendance_can_be_filtered_by_month(): void
    {
        $user = UserModel::factory()->create([
            'employee_id' => 'EMP001',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        Attendance::create([
            'user_id' => $user->id,
            'attendance_date' => '2026-04-10',
            'check_in' => '09:00:00',
            'check_out' => '17:30:00',
            'status' => 'present',
        ]);

        Attendance::create([
            'user_id' => $user->id,
            'attendance_date' => '2026-05-10',
            'check_in' => '09:00:00',
            'check_out' => '17:30:00',
            'status' => 'present',
        ]);

        $this->getJson('/api/attendance/user/EMP001?month=2026-04')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('month', '2026-04')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.attendance_date', '2026-04-10')
            ->assertJsonPath('data.0.check_in', '09:00:00 AM');
    }

    public function test_user_attendance_rejects_invalid_month_filter(): void
    {
        UserModel::factory()->create([
            'employee_id' => 'EMP001',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $this->getJson('/api/attendance/user/EMP001?month=04-2026')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('month');
    }
}
