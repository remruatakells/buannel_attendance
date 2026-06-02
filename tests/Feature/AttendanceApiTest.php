<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Organization;
use App\Models\StaffDetail;
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
            ->assertJsonPath('data.check_in', '09:15:00 AM')
            ->assertJsonPath('data.detail.employee.employee_id', 'EMP001')
            ->assertJsonPath('data.detail.employee.device_id', 'MORPHO_01')
            ->assertJsonPath('data.detail.date.value', '2026-04-29')
            ->assertJsonPath('data.detail.late_duration', '00:00:00');

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
        UserModel::factory()->create([
            'employee_id' => 'ADMIN001',
            'is_admin' => true,
            'admin_access_token' => 'admin-token',
            'organization_id' => $user->organization_id,
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

    public function test_check_in_after_organization_late_time_is_marked_late(): void
    {
        Carbon::setTestNow('2026-04-29 09:45:00 AM');

        $organization = Organization::factory()->create();
        $organization->timing()->create([
            'check_in_start' => '09:00:00',
            'check_in_end' => '10:00:00',
            'late_after' => '09:30:00',
            'check_out_start' => '16:00:00',
        ]);

        $user = UserModel::factory()->create([
            'employee_id' => 'EMP001',
            'organization_id' => $organization->id,
        ]);

        $this->postJson('/api/attendance', [
            'user_id' => 'EMP001',
        ])
            ->assertOk()
            ->assertJsonPath('action', 'check_in')
            ->assertJsonPath('data.status', 'late');

        $this->assertDatabaseHas('attendances', [
            'user_id' => $user->id,
            'attendance_date' => '2026-04-29',
            'check_in' => '09:45:00',
            'status' => 'late',
        ]);
    }

    public function test_mark_attendance_uses_organization_timing_window(): void
    {
        Carbon::setTestNow('2026-04-29 10:30:00 AM');

        $organization = Organization::factory()->create();
        $organization->timing()->create([
            'check_in_start' => '10:00:00',
            'check_in_end' => '11:00:00',
            'late_after' => '10:45:00',
            'check_out_start' => '17:00:00',
        ]);

        $user = UserModel::factory()->create([
            'employee_id' => 'EMP001',
            'organization_id' => $organization->id,
        ]);

        $this->postJson('/api/attendance', [
            'user_id' => 'EMP001',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'present');

        $this->assertDatabaseHas('attendances', [
            'user_id' => $user->id,
            'attendance_date' => '2026-04-29',
            'status' => 'present',
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
        UserModel::factory()->create([
            'employee_id' => 'ADMIN001',
            'is_admin' => true,
            'admin_access_token' => 'admin-token',
            'organization_id' => $user->organization_id,
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
        Carbon::setTestNow('2026-05-06 10:00:00 AM');

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

        UserModel::factory()->create([
            'employee_id' => 'ADMIN001',
            'is_admin' => true,
            'admin_access_token' => 'admin-token',
            'organization_id' => $user->organization_id,
        ]);

        $this->getJson('/api/attendance/user/EMP001?month=2026-04', [
            'X-Admin-Access-Token' => 'admin-token',
        ])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('month', '2026-04')
            ->assertJsonCount(22, 'data')
            ->assertJsonPath('data.0.attendance_date', '2026-04-30')
            ->assertJsonPath('data.0.status', 'absent')
            ->assertJsonFragment([
                'attendance_date' => '2026-04-10',
                'check_in' => '09:00:00 AM',
                'status' => 'present',
            ]);
    }

    public function test_user_attendance_can_be_downloaded_as_excel_csv(): void
    {
        Carbon::setTestNow('2026-05-06 10:00:00 AM');

        $user = UserModel::factory()->create([
            'employee_id' => 'EMP001',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);
        UserModel::factory()->create([
            'employee_id' => 'ADMIN001',
            'is_admin' => true,
            'admin_access_token' => 'admin-token',
            'organization_id' => $user->organization_id,
        ]);

        Attendance::create([
            'user_id' => $user->id,
            'attendance_date' => '2026-04-10',
            'check_in' => '09:00:00',
            'check_out' => '17:30:00',
            'status' => 'present',
            'remark' => 'On time',
        ]);

        $response = $this->get('/api/attendance/user/EMP001/excel?month=2026-04', [
            'X-Admin-Access-Token' => 'admin-token',
        ]);

        $response
            ->assertOk()
            ->assertDownload('attendance-history-EMP001-2026-04.csv');

        $content = $response->streamedContent();

        $this->assertStringContainsString('"Employee ID",EMP001', $content);
        $this->assertStringContainsString('"Employee Name","John Doe"', $content);
        $this->assertStringContainsString('Date,Day,Status,"Check In","Check Out","Late Duration","Worked Duration","Salary Cut",Remark', $content);
        $this->assertStringContainsString('2026-04-10,Friday,present,"09:00:00 AM","05:30:00 PM"', $content);
        $this->assertStringContainsString('"On time"', $content);
    }

    public function test_user_attendance_includes_absent_days_for_current_month_until_today(): void
    {
        Carbon::setTestNow('2026-05-06 10:00:00 AM');

        $user = UserModel::factory()->create([
            'employee_id' => 'EMP001',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);
        UserModel::factory()->create([
            'employee_id' => 'ADMIN001',
            'is_admin' => true,
            'admin_access_token' => 'admin-token',
            'organization_id' => $user->organization_id,
        ]);

        Attendance::create([
            'user_id' => $user->id,
            'attendance_date' => '2026-05-04',
            'check_in' => '09:00:00',
            'check_out' => '17:30:00',
            'status' => 'present',
        ]);

        $this->getJson('/api/attendance/user/EMP001', [
            'X-Admin-Access-Token' => 'admin-token',
        ])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('month', '2026-05')
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.attendance_date', '2026-05-06')
            ->assertJsonPath('data.0.status', 'absent')
            ->assertJsonPath('data.2.attendance_date', '2026-05-04')
            ->assertJsonPath('data.2.status', 'present')
            ->assertJsonPath('data.2.check_in', '09:00:00 AM');
    }

    public function test_user_attendance_includes_late_minutes_and_absent_salary_cut(): void
    {
        Carbon::setTestNow('2026-04-30 10:00:00 AM');

        $organization = Organization::factory()->create();
        $organization->timing()->create([
            'check_in_start' => '09:00:00',
            'check_in_end' => '10:00:00',
            'late_after' => '09:30:00',
            'check_out_start' => '17:00:00',
        ]);
        UserModel::factory()->create([
            'employee_id' => 'ADMIN001',
            'is_admin' => true,
            'admin_access_token' => 'admin-token',
            'organization_id' => $organization->id,
        ]);
        $user = UserModel::factory()->create([
            'employee_id' => 'EMP001',
            'organization_id' => $organization->id,
        ]);

        StaffDetail::factory()->create([
            'user_id' => $user->id,
            'salary' => 30000,
            'salary_frequency' => 'monthly',
        ]);
        Attendance::create([
            'user_id' => $user->id,
            'attendance_date' => '2026-04-10',
            'check_in' => '09:45:30 AM',
            'check_out' => '17:30:00',
            'status' => 'late',
        ]);

        $this->getJson('/api/attendance/user/EMP001?month=2026-04', [
            'X-Admin-Access-Token' => 'admin-token',
        ])
            ->assertOk()
            ->assertJsonPath('summary.total_late_seconds', 930)
            ->assertJsonPath('summary.total_late_minutes', 15)
            ->assertJsonPath('summary.total_late_duration', '00:15:30')
            ->assertJsonPath('summary.total_salary_cut', 21031.25)
            ->assertJsonPath('summary.payable_salary', 8968.75)
            ->assertJsonPath('employee.employee_id', 'EMP001')
            ->assertJsonPath('employee.organization.id', $organization->id)
            ->assertJsonFragment([
                'attendance_date' => '2026-04-30',
                'status' => 'absent',
                'salary_cut' => 1000,
            ])
            ->assertJsonFragment([
                'attendance_date' => '2026-04-10',
                'late_seconds' => 930,
                'late_minutes' => 15,
                'late_duration' => '00:15:30',
                'salary_cut' => 31.25,
            ])
            ->assertJsonFragment([
                'worked_duration' => '07:44:30',
                'check_in_at' => '2026-04-10 09:45:30',
                'check_out_at' => '2026-04-10 17:30:00',
            ]);
    }

    public function test_user_attendance_summary_includes_month_and_annual_leave_usage(): void
    {
        Carbon::setTestNow('2026-04-30 10:00:00 AM');

        $organization = Organization::factory()->create();
        $organization->attendancePolicy()->create([
            'annual_leave_limit' => 12,
        ]);
        UserModel::factory()->create([
            'employee_id' => 'ADMIN001',
            'is_admin' => true,
            'admin_access_token' => 'admin-token',
            'organization_id' => $organization->id,
        ]);
        $user = UserModel::factory()->create([
            'employee_id' => 'EMP001',
            'organization_id' => $organization->id,
        ]);

        Attendance::create([
            'user_id' => $user->id,
            'attendance_date' => '2026-01-12',
            'status' => 'leave',
            'remark' => 'Annual leave',
        ]);
        Attendance::create([
            'user_id' => $user->id,
            'attendance_date' => '2026-04-10',
            'status' => 'leave',
            'remark' => 'Family leave',
        ]);
        Attendance::create([
            'user_id' => $user->id,
            'attendance_date' => '2025-12-31',
            'status' => 'leave',
            'remark' => 'Previous year leave',
        ]);

        $this->getJson('/api/attendance/user/EMP001?month=2026-04', [
            'X-Admin-Access-Token' => 'admin-token',
        ])
            ->assertOk()
            ->assertJsonPath('summary.leave_days', 1)
            ->assertJsonPath('summary.annual_leave_taken', 2)
            ->assertJsonPath('summary.annual_leave_limit', 12)
            ->assertJsonPath('summary.annual_leave_remaining', 10)
            ->assertJsonFragment([
                'attendance_date' => '2026-04-10',
                'status' => 'leave',
                'remark' => 'Family leave',
            ]);
    }

    public function test_admin_attendance_can_be_filtered_by_month(): void
    {
        $organization = Organization::factory()->create();
        $john = UserModel::factory()->create([
            'employee_id' => 'EMP001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'organization_id' => $organization->id,
        ]);
        $jane = UserModel::factory()->create([
            'employee_id' => 'EMP002',
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'organization_id' => $organization->id,
        ]);
        UserModel::factory()->create([
            'employee_id' => 'ADMIN001',
            'is_admin' => true,
            'admin_access_token' => 'admin-token',
            'organization_id' => $organization->id,
        ]);

        Attendance::create([
            'user_id' => $john->id,
            'attendance_date' => '2026-04-10',
            'check_in' => '09:00:00',
            'check_out' => '17:30:00',
            'status' => 'present',
        ]);

        Attendance::create([
            'user_id' => $jane->id,
            'attendance_date' => '2026-04-11',
            'check_in' => '09:30:00',
            'check_out' => '17:30:00',
            'status' => 'present',
        ]);

        Attendance::create([
            'user_id' => $john->id,
            'attendance_date' => '2026-05-10',
            'check_in' => '09:00:00',
            'check_out' => '17:30:00',
            'status' => 'present',
        ]);

        $this->getJson('/api/attendance/admin?month=2026-04', [
            'X-Admin-Access-Token' => 'admin-token',
        ])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('month', '2026-04')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.attendance_date', '2026-04-11')
            ->assertJsonPath('data.0.user.employee_id', 'EMP002')
            ->assertJsonPath('data.0.detail.employee.employee_id', 'EMP002')
            ->assertJsonPath('data.0.detail.worked_duration', '08:00:00')
            ->assertJsonPath('data.1.attendance_date', '2026-04-10')
            ->assertJsonPath('data.1.user.employee_id', 'EMP001');
    }

    public function test_admin_attendance_can_be_downloaded_as_excel_csv(): void
    {
        $organization = Organization::factory()->create([
            'name' => 'Buannel',
        ]);
        $john = UserModel::factory()->create([
            'employee_id' => 'EMP001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'organization_id' => $organization->id,
        ]);
        UserModel::factory()->create([
            'employee_id' => 'ADMIN001',
            'is_admin' => true,
            'admin_access_token' => 'admin-token',
            'organization_id' => $organization->id,
        ]);

        Attendance::create([
            'user_id' => $john->id,
            'attendance_date' => '2026-04-10',
            'check_in' => '09:00:00',
            'check_out' => '17:30:00',
            'status' => 'present',
            'remark' => 'On time',
        ]);

        $response = $this->get('/api/attendance/admin/excel?month=2026-04', [
            'X-Admin-Access-Token' => 'admin-token',
        ]);

        $response
            ->assertOk()
            ->assertDownload('admin-attendance-2026-04.csv');

        $content = $response->streamedContent();

        $this->assertStringContainsString('"Employee ID","Employee Name",Organization,Date,Status,"Check In","Check Out","Late Duration","Worked Duration","Salary Cut",Remark', $content);
        $this->assertStringContainsString('EMP001,"John Doe",Buannel,2026-04-10,present,"09:00:00 AM","05:30:00 PM"', $content);
        $this->assertStringContainsString('"On time"', $content);
    }

    public function test_admin_attendance_is_scoped_to_viewers_organization(): void
    {
        $buannel = Organization::factory()->create([
            'name' => 'Buannel',
            'type' => 'company',
        ]);
        $university = Organization::factory()->create([
            'name' => 'Mizoram University',
            'type' => 'university',
        ]);
        $admin = UserModel::factory()->create([
            'employee_id' => 'ADMIN001',
            'is_admin' => true,
            'admin_access_token' => 'admin-token',
            'organization_id' => $buannel->id,
        ]);
        $sameOrganizationUser = UserModel::factory()->create([
            'employee_id' => 'EMP001',
            'organization_id' => $admin->organization_id,
        ]);
        $otherOrganizationUser = UserModel::factory()->create([
            'employee_id' => 'EMP002',
            'organization_id' => $university->id,
        ]);

        foreach ([$sameOrganizationUser, $otherOrganizationUser] as $index => $user) {
            Attendance::create([
                'user_id' => $user->id,
                'attendance_date' => '2026-04-1'.($index + 1),
                'check_in' => '09:00:00',
                'check_out' => '17:30:00',
                'status' => 'present',
            ]);
        }

        $this->getJson('/api/attendance/admin?month=2026-04', [
            'X-Admin-Access-Token' => 'admin-token',
            'X-Admin-Employee-Id' => 'ADMIN001',
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.employee_id', 'EMP001')
            ->assertJsonPath('data.0.user.organization.id', $buannel->id);
    }

    public function test_admin_can_create_leave_attendance_with_remark(): void
    {
        $organization = Organization::factory()->create();
        $admin = UserModel::factory()->create([
            'employee_id' => 'ADMIN001',
            'is_admin' => true,
            'admin_access_token' => 'admin-token',
            'organization_id' => $organization->id,
        ]);
        $user = UserModel::factory()->create([
            'employee_id' => 'EMP001',
            'organization_id' => $organization->id,
        ]);

        $this->postJson('/api/attendance/admin', [
            'user_id' => $user->id,
            'attendance_date' => '2026-04-10',
            'status' => 'leave',
            'remark' => 'Approved annual leave',
        ], [
            'X-Admin-Access-Token' => $admin->admin_access_token,
        ])
            ->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.status', 'leave')
            ->assertJsonPath('data.remark', 'Approved annual leave');

        $this->assertDatabaseHas('attendances', [
            'user_id' => $user->id,
            'attendance_date' => '2026-04-10',
            'status' => 'leave',
            'remark' => 'Approved annual leave',
        ]);
    }

    public function test_admin_can_update_attendance_to_leave_with_remark(): void
    {
        $organization = Organization::factory()->create();
        $admin = UserModel::factory()->create([
            'employee_id' => 'ADMIN001',
            'is_admin' => true,
            'admin_access_token' => 'admin-token',
            'organization_id' => $organization->id,
        ]);
        $user = UserModel::factory()->create([
            'employee_id' => 'EMP001',
            'organization_id' => $organization->id,
        ]);
        $attendance = Attendance::create([
            'user_id' => $user->id,
            'attendance_date' => '2026-04-10',
            'check_in' => '09:00:00',
            'status' => 'present',
        ]);

        $this->putJson('/api/attendance/update/'.$attendance->id, [
            'status' => 'leave',
            'remark' => 'Medical leave',
            'check_in' => null,
            'check_out' => null,
        ], [
            'X-Admin-Access-Token' => $admin->admin_access_token,
        ])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.status', 'leave')
            ->assertJsonPath('data.remark', 'Medical leave');

        $this->assertDatabaseHas('attendances', [
            'id' => $attendance->id,
            'status' => 'leave',
            'remark' => 'Medical leave',
            'check_in' => null,
            'check_out' => null,
        ]);
    }

    public function test_admin_cannot_create_leave_when_organization_disables_leave(): void
    {
        $organization = Organization::factory()->create();
        $organization->attendancePolicy()->create([
            'allow_leave' => false,
        ]);
        $admin = UserModel::factory()->create([
            'employee_id' => 'ADMIN001',
            'is_admin' => true,
            'admin_access_token' => 'admin-token',
            'organization_id' => $organization->id,
        ]);
        $user = UserModel::factory()->create([
            'employee_id' => 'EMP001',
            'organization_id' => $organization->id,
        ]);

        $this->postJson('/api/attendance/admin', [
            'user_id' => $user->id,
            'attendance_date' => '2026-04-10',
            'status' => 'leave',
            'remark' => 'Requested leave',
        ], [
            'X-Admin-Access-Token' => $admin->admin_access_token,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'Leave attendance is disabled for this organization');

        $this->assertDatabaseMissing('attendances', [
            'user_id' => $user->id,
            'attendance_date' => '2026-04-10',
        ]);
    }

    public function test_leave_after_annual_limit_is_created_as_unpaid_leave(): void
    {
        Carbon::setTestNow('2026-04-30 10:00:00 AM');

        $organization = Organization::factory()->create();
        $organization->attendancePolicy()->create([
            'annual_leave_limit' => 1,
        ]);
        $admin = UserModel::factory()->create([
            'employee_id' => 'ADMIN001',
            'is_admin' => true,
            'admin_access_token' => 'admin-token',
            'organization_id' => $organization->id,
        ]);
        $user = UserModel::factory()->create([
            'employee_id' => 'EMP001',
            'organization_id' => $organization->id,
        ]);
        StaffDetail::factory()->create([
            'user_id' => $user->id,
            'salary' => 30000,
            'salary_frequency' => 'monthly',
        ]);
        Attendance::create([
            'user_id' => $user->id,
            'attendance_date' => '2026-04-10',
            'status' => 'leave',
            'remark' => 'First leave',
        ]);

        $this->postJson('/api/attendance/admin', [
            'user_id' => $user->id,
            'attendance_date' => '2026-04-13',
            'status' => 'leave',
            'remark' => 'Second leave',
        ], [
            'X-Admin-Access-Token' => $admin->admin_access_token,
        ])
            ->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.status', 'leave')
            ->assertJsonPath('data.remark', 'Second leave');

        $this->assertDatabaseHas('attendances', [
            'user_id' => $user->id,
            'attendance_date' => '2026-04-13',
            'status' => 'leave',
            'remark' => 'Second leave',
        ]);

        $this->getJson('/api/attendance/user/EMP001?month=2026-04', [
            'X-Admin-Access-Token' => $admin->admin_access_token,
        ])
            ->assertOk()
            ->assertJsonPath('summary.annual_leave_taken', 2)
            ->assertJsonPath('summary.annual_leave_limit', 1)
            ->assertJsonPath('summary.annual_leave_remaining', 0)
            ->assertJsonFragment([
                'attendance_date' => '2026-04-10',
                'status' => 'leave',
                'paid_leave' => true,
                'unpaid_leave' => false,
                'salary_cut_applied' => false,
                'salary_cut' => 0,
            ])
            ->assertJsonFragment([
                'attendance_date' => '2026-04-13',
                'status' => 'leave',
                'paid_leave' => false,
                'unpaid_leave' => true,
                'salary_cut_applied' => true,
                'salary_cut' => 1000,
            ]);
    }

    public function test_user_attendance_cannot_read_another_organization_when_viewer_is_supplied(): void
    {
        $buannel = Organization::factory()->create();
        $university = Organization::factory()->create();

        UserModel::factory()->create([
            'employee_id' => 'ADMIN001',
            'is_admin' => true,
            'admin_access_token' => 'admin-token',
            'organization_id' => $buannel->id,
        ]);
        $otherOrganizationUser = UserModel::factory()->create([
            'employee_id' => 'EMP002',
            'organization_id' => $university->id,
        ]);

        Attendance::create([
            'user_id' => $otherOrganizationUser->id,
            'attendance_date' => '2026-04-10',
            'check_in' => '09:00:00',
            'check_out' => '17:30:00',
            'status' => 'present',
        ]);

        $this->getJson('/api/attendance/user/EMP002?viewer_employee_id=ADMIN001', [
            'X-Admin-Access-Token' => 'admin-token',
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'User not found');
    }

    public function test_admin_attendance_rejects_invalid_month_filter(): void
    {
        UserModel::factory()->create([
            'employee_id' => 'ADMIN001',
            'is_admin' => true,
            'admin_access_token' => 'admin-token',
        ]);

        $this->getJson('/api/attendance/admin?month=04-2026', [
            'X-Admin-Access-Token' => 'admin-token',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('month');
    }

    public function test_user_attendance_rejects_invalid_month_filter(): void
    {
        UserModel::factory()->create([
            'employee_id' => 'EMP001',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);
        UserModel::factory()->create([
            'employee_id' => 'ADMIN001',
            'is_admin' => true,
            'admin_access_token' => 'admin-token',
        ]);

        $this->getJson('/api/attendance/user/EMP001?month=04-2026', [
            'X-Admin-Access-Token' => 'admin-token',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('month');
    }
}
