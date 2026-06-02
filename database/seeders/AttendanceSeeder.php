<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Organization;
use App\Models\OrganizationAttendancePolicy;
use App\Models\OrganizationTiming;
use App\Models\UserModel;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class AttendanceSeeder extends Seeder
{
    public function run(): void
    {
        $buannel = Organization::firstOrCreate(
            ['name' => 'Buannel'],
            ['type' => 'company'],
        );
        $mizoramUniversity = Organization::firstOrCreate(
            ['name' => 'Mizoram University'],
            ['type' => 'university'],
        );

        $buannel->timing()->firstOrCreate([], OrganizationTiming::defaults());
        $mizoramUniversity->timing()->firstOrCreate([], OrganizationTiming::defaults());
        $buannel->attendancePolicy()->firstOrCreate([], OrganizationAttendancePolicy::defaults());
        $mizoramUniversity->attendancePolicy()->firstOrCreate([], OrganizationAttendancePolicy::defaults());

        $users = [
            [
                'user_id' => 'EMP001',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'phone_no' => '9000000001',
                'organization_id' => $buannel->id,
            ],
            [
                'user_id' => 'EMP002',
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'phone_no' => '9000000002',
                'organization_id' => $buannel->id,
            ],
            [
                'user_id' => 'EMP003',
                'first_name' => 'David',
                'last_name' => 'Lal',
                'phone_no' => '9000000003',
                'organization_id' => $mizoramUniversity->id,
            ],
        ];

        foreach ($users as $user) {
            $employee = UserModel::updateOrCreate(
                ['employee_id' => $user['user_id']],
                [
                    'first_name' => $user['first_name'],
                    'last_name' => $user['last_name'],
                    'phone_no' => $user['phone_no'],
                    'device_id' => 'MORPHO_01',
                    'organization_id' => $user['organization_id'],
                    'name' => $user['first_name'].' '.$user['last_name'],
                ],
            );

            // seed last 5 days
            for ($i = 0; $i < 5; $i++) {

                $date = Carbon::now()->subDays($i)->toDateString();

                Attendance::updateOrCreate([
                    'user_id' => $employee->id,
                    'attendance_date' => $date,
                ], [
                    'attendance_date' => $date,
                    'check_in' => '09:00:00',
                    'check_out' => '17:30:00',
                    'status' => 'present',
                ]);
            }
        }
    }
}
