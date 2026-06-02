<?php

namespace App\Http\Controllers;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\OrganizationAttendancePolicy;
use App\Models\OrganizationTiming;
use App\Models\UserModel;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AttendanceController extends Controller
{
    public function mark(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|string',
        ]);

        $today = Carbon::today()->toDateString();
        $now = Carbon::now();
        $time = $now->format('H:i:s');
        $user = UserModel::with(['organization.timing', 'organization.attendancePolicy', 'staffDetail'])
            ->where('employee_id', $validated['user_id'])
            ->first();

        if (! $user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ], 404);
        }

        $timing = $user->organization?->timing ?? OrganizationTiming::defaultTiming();

        $attendance = Attendance::where('user_id', $user->id)
            ->whereDate('attendance_date', $today)
            ->first();

        if ($attendance?->check_out) {
            return response()->json([
                'status' => false,
                'message' => 'Already checked out today',
                'action' => 'completed',
                'data' => $this->withLateSalaryCut(
                    $attendance->load('user.organization.timing', 'user.staffDetail'),
                    $user,
                    $timing
                ),
            ], 409);
        }

        if (! $attendance) {
            $checkInStart = $this->timeToday($now, $timing->check_in_start);
            $checkInEnd = $this->timeToday($now, $timing->check_in_end);

            if (! $now->betweenIncluded($checkInStart, $checkInEnd)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Check-in is allowed only between '.$checkInStart->format('h:i A').' and '.$checkInEnd->format('h:i A'),
                    'action' => 'check_in_closed',
                ], 409);
            }

            $lateAfter = $this->timeToday($now, $timing->late_after);

            $attendance = Attendance::create([
                'user_id' => $user->id,
                'attendance_date' => $today,
                'check_in' => $time,
                'status' => $now->gt($lateAfter) ? AttendanceStatus::Late : AttendanceStatus::Present,
            ])->load('user.organization.timing', 'user.staffDetail');

            return response()->json([
                'status' => true,
                'message' => 'Check-in successful',
                'action' => 'check_in',
                'data' => $this->withLateSalaryCut($attendance, $user, $timing),
            ]);
        }

        $checkOutStart = $this->timeToday($now, $timing->check_out_start);

        if ($now->lt($checkOutStart)) {
            return response()->json([
                'status' => false,
                'message' => 'Check-out is allowed from '.$checkOutStart->format('h:i A'),
                'action' => 'check_out_closed',
                'data' => $this->withLateSalaryCut(
                    $attendance->load('user.organization.timing', 'user.staffDetail'),
                    $user,
                    $timing
                ),
            ], 409);
        }

        $attendance->update([
            'check_out' => $time,
        ]);
        $attendance->load('user.organization.timing', 'user.staffDetail');

        return response()->json([
            'status' => true,
            'message' => 'Check-out successful',
            'action' => 'check_out',
            'data' => $this->withLateSalaryCut($attendance, $user, $timing),
        ]);
    }

    public function today(Request $request)
    {
        $viewer = $this->viewerFromRequest($request);

        if ($this->viewerWasRequested($request) && ! $viewer) {
            return $this->viewerNotFoundResponse();
        }

        $data = $this->visibleAttendanceQuery($viewer)
            ->whereDate('attendance_date', Carbon::today())
            ->orderByDesc('id')
            ->get()
            ->map(fn (Attendance $attendance) => $this->withLateSalaryCut(
                $attendance,
                $attendance->user,
                $attendance->user->organization?->timing ?? OrganizationTiming::defaultTiming()
            ));

        return response()->json([
            'status' => true,
            'data' => $data,
        ]);
    }

    public function adminAttendance(Request $request)
    {
        $payload = $this->adminAttendancePayload($request);

        if (isset($payload['response'])) {
            return $payload['response'];
        }

        return response()->json([
            'status' => true,
            'month' => $payload['month'],
            'data' => $payload['data'],
        ]);
    }

    public function adminAttendanceExcel(Request $request)
    {
        $validated = $request->validate([
            'month' => ['sometimes', 'date_format:Y-m'],
        ]);

        $payload = $this->adminAttendancePayload($request, $validated);

        if (isset($payload['response'])) {
            return $payload['response'];
        }

        $month = $payload['month'] ?? Carbon::today()->format('Y-m');
        $rows = collect([
            ['Admin Attendance Report'],
            ['Generated At', Carbon::now()->format('Y-m-d h:i:s A')],
            ['Month', $this->reportMonthLabel($month)],
            ['Total Records', $payload['data']->count()],
            [],
            [
                '#',
                'Employee ID',
                'Employee Name',
                'Organization',
                'Date',
                'Day',
                'Status',
                'Check In',
                'Check Out',
                'Late Duration',
                'Worked Duration',
                'Salary Cut',
                'Remark',
            ],
        ])->merge($payload['data']->values()->map(fn ($record, int $index) => [
            $index + 1,
            data_get($record, 'user.employee_id'),
            $this->employeeName(data_get($record, 'user')),
            data_get($record, 'user.organization.name'),
            data_get($record, 'attendance_date'),
            data_get($record, 'detail.date.day_name'),
            $this->reportStatus($this->attendanceStatusValue($record)),
            data_get($record, 'check_in'),
            data_get($record, 'check_out'),
            data_get($record, 'late_duration'),
            data_get($record, 'detail.worked_duration'),
            data_get($record, 'salary_cut'),
            data_get($record, 'remark'),
        ]));

        return $this->csvDownload('admin-attendance-'.$month.'.csv', $rows);
    }

    public function storeAdmin(Request $request)
    {
        $viewer = $this->viewerFromRequest($request);

        if ($this->viewerWasRequested($request) && ! $viewer) {
            return $this->viewerNotFoundResponse();
        }

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'attendance_date' => ['required', 'date'],
            'check_in' => ['sometimes', 'nullable', 'date_format:h:i:s A'],
            'check_out' => ['sometimes', 'nullable', 'date_format:h:i:s A'],
            'status' => ['required', Rule::enum(AttendanceStatus::class)],
            'remark' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $user = UserModel::visibleTo($viewer)->find($validated['user_id']);

        if (! $user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ], 404);
        }

        $policyResponse = $this->attendancePolicyViolation(
            $user->loadMissing('organization.attendancePolicy'),
            AttendanceStatus::from($validated['status']),
            Carbon::parse($validated['attendance_date'])
        );

        if ($policyResponse) {
            return $policyResponse;
        }

        $exists = Attendance::where('user_id', $user->id)
            ->whereDate('attendance_date', $validated['attendance_date'])
            ->exists();

        if ($exists) {
            return response()->json([
                'status' => false,
                'message' => 'Attendance already exists for this date',
            ], 409);
        }

        $attendance = Attendance::create($validated)
            ->load('user.organization.timing', 'user.staffDetail');

        return response()->json([
            'status' => true,
            'message' => 'Attendance created',
            'data' => $this->withLateSalaryCut(
                $attendance,
                $attendance->user,
                $attendance->user->organization?->timing ?? OrganizationTiming::defaultTiming()
            ),
        ], 201);
    }

    public function userAttendance(Request $request, $userId)
    {
        $payload = $this->userAttendancePayload($request, $userId);

        if (isset($payload['response'])) {
            return $payload['response'];
        }

        return response()->json([
            'status' => true,
            'month' => $payload['month'],
            'employee' => $payload['employee'],
            'summary' => $payload['summary'],
            'data' => $payload['data'],
        ]);
    }

    public function userAttendanceExcel(Request $request, $userId)
    {
        $validated = $request->validate([
            'month' => ['sometimes', 'date_format:Y-m'],
        ]);

        $payload = $this->userAttendancePayload($request, $userId, $validated);

        if (isset($payload['response'])) {
            return $payload['response'];
        }

        $summaryRows = collect([
            ['Attendance History Report'],
            ['Generated At', Carbon::now()->format('Y-m-d h:i:s A')],
            ['Month', $this->reportMonthLabel($payload['month'])],
            [],
            ['Employee'],
            ['Employee ID', $payload['employee']['employee_id']],
            ['Employee Name', $this->employeeName($payload['employee'])],
            ['Organization', data_get($payload, 'employee.organization.name')],
            [],
            ['Summary'],
            ['Total Late Duration', $payload['summary']['total_late_duration']],
            ['Total Salary Cut', $payload['summary']['total_salary_cut']],
            ['Payable Salary', $payload['summary']['payable_salary']],
            ['Leave Days', $payload['summary']['leave_days']],
            ['Annual Leave Taken', $payload['summary']['annual_leave_taken']],
            ['Annual Leave Limit', $payload['summary']['annual_leave_limit']],
            ['Annual Leave Remaining', $payload['summary']['annual_leave_remaining'] ?? 'Unlimited'],
            [],
            [
                '#',
                'Date',
                'Day',
                'Status',
                'Check In',
                'Check Out',
                'Late Duration',
                'Worked Duration',
                'Salary Cut',
                'Remark',
            ],
        ]);
        $attendanceRows = $payload['data']->values()->map(fn ($record, int $index) => [
            $index + 1,
            data_get($record, 'attendance_date'),
            data_get($record, 'detail.date.day_name'),
            $this->reportStatus($this->attendanceStatusValue($record)),
            data_get($record, 'check_in'),
            data_get($record, 'check_out'),
            data_get($record, 'late_duration'),
            data_get($record, 'detail.worked_duration'),
            data_get($record, 'salary_cut'),
            data_get($record, 'remark'),
        ]);

        return $this->csvDownload(
            'attendance-history-'.$payload['employee']['employee_id'].'-'.$payload['month'].'.csv',
            $summaryRows->merge($attendanceRows)
        );
    }

    public function update(Request $request, $id)
    {
        $viewer = $this->viewerFromRequest($request);

        if ($this->viewerWasRequested($request) && ! $viewer) {
            return $this->viewerNotFoundResponse();
        }

        $attendance = $this->visibleAttendanceQuery($viewer)->find($id);

        if (! $attendance) {
            return response()->json([
                'status' => false,
                'message' => 'Attendance not found',
            ], 404);
        }

        $validated = $request->validate([
            'attendance_date' => 'sometimes|date',
            'check_in' => ['sometimes', 'nullable', 'date_format:h:i:s A'],
            'check_out' => ['sometimes', 'nullable', 'date_format:h:i:s A'],
            'status' => ['sometimes', Rule::enum(AttendanceStatus::class)],
            'remark' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $status = isset($validated['status'])
            ? AttendanceStatus::from($validated['status'])
            : $attendance->status;
        $attendanceDate = Carbon::parse($validated['attendance_date'] ?? $attendance->attendance_date);
        $policyResponse = $this->attendancePolicyViolation(
            $attendance->user->loadMissing('organization.attendancePolicy'),
            $status,
            $attendanceDate,
            $attendance->id
        );

        if ($policyResponse) {
            return $policyResponse;
        }

        $attendance->update($validated);
        $attendance->load('user.organization.timing', 'user.staffDetail');

        return response()->json([
            'status' => true,
            'message' => 'Attendance updated',
            'data' => $this->withLateSalaryCut(
                $attendance,
                $attendance->user,
                $attendance->user->organization?->timing ?? OrganizationTiming::defaultTiming()
            ),
        ]);
    }

    public function delete(Request $request, $id)
    {
        $viewer = $this->viewerFromRequest($request);

        if ($this->viewerWasRequested($request) && ! $viewer) {
            return $this->viewerNotFoundResponse();
        }

        $attendance = $this->visibleAttendanceQuery($viewer)->find($id);

        if (! $attendance) {
            return response()->json([
                'status' => false,
                'message' => 'Attendance not found',
            ], 404);
        }

        $attendance->delete();

        return response()->json([
            'status' => true,
            'message' => 'Attendance deleted',
        ]);
    }

    private function adminAttendancePayload(Request $request, ?array $validated = null): array
    {
        $validated ??= $request->validate([
            'month' => ['sometimes', 'date_format:Y-m'],
        ]);

        $viewer = $this->viewerFromRequest($request);

        if ($this->viewerWasRequested($request) && ! $viewer) {
            return ['response' => $this->viewerNotFoundResponse()];
        }

        $query = $this->visibleAttendanceQuery($viewer);

        if (isset($validated['month'])) {
            $month = Carbon::createFromFormat('Y-m', $validated['month']);

            $query->whereBetween('attendance_date', [
                $month->copy()->startOfMonth()->toDateString(),
                $month->copy()->endOfMonth()->toDateString(),
            ]);
        }

        $data = $query
            ->orderByDesc('attendance_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Attendance $attendance) => $this->withLateSalaryCut(
                $attendance,
                $attendance->user,
                $attendance->user->organization?->timing ?? OrganizationTiming::defaultTiming()
            ));

        return [
            'month' => $validated['month'] ?? null,
            'data' => $data,
        ];
    }

    private function userAttendancePayload(Request $request, $userId, ?array $validated = null): array
    {
        $validated ??= $request->validate([
            'month' => ['sometimes', 'date_format:Y-m'],
        ]);

        $viewer = $this->viewerFromRequest($request);

        if ($this->viewerWasRequested($request) && ! $viewer) {
            return ['response' => $this->viewerNotFoundResponse()];
        }

        $user = UserModel::with(['organization.attendancePolicy', 'organization.timing', 'staffDetail'])
            ->visibleTo($viewer)
            ->where('employee_id', $userId)
            ->first();

        if (! $user) {
            return ['response' => response()->json([
                'status' => false,
                'message' => 'User not found',
            ], 404)];
        }

        $month = isset($validated['month'])
            ? Carbon::createFromFormat('Y-m', $validated['month'])
            : Carbon::today();

        $startDate = $month->copy()->startOfMonth();
        $endDate = $month->copy()->endOfMonth();

        if ($endDate->isFuture()) {
            $endDate = Carbon::today();
        }

        $attendances = Attendance::with('user')
            ->where('user_id', $user->id)
            ->whereBetween('attendance_date', [
                $startDate->toDateString(),
                $endDate->toDateString(),
            ])
            ->get()
            ->keyBy(fn (Attendance $attendance) => Carbon::parse($attendance->attendance_date)->toDateString());

        $data = collect();
        $timing = $user->organization?->timing ?? OrganizationTiming::defaultTiming();
        $leaveSummary = $this->leaveSummary($user, $startDate, $endDate);

        for ($date = $endDate->copy(); $date->gte($startDate); $date->subDay()) {
            if ($date->isWeekend()) {
                continue;
            }

            $dateString = $date->toDateString();
            $record = $attendances->get($dateString) ?? [
                'id' => null,
                'user_id' => $user->id,
                'attendance_date' => $dateString,
                'check_in' => null,
                'check_out' => null,
                'status' => AttendanceStatus::Absent->value,
                'remark' => null,
                'created_at' => null,
                'updated_at' => null,
                'user' => $user,
            ];

            $data->push($this->withLateSalaryCut($record, $user, $timing));
        }

        $totalSalaryCut = round($data->sum('salary_cut'), 2);

        return [
            'month' => $month->format('Y-m'),
            'employee' => $this->employeeDetail($user),
            'summary' => [
                'total_late_seconds' => $data->sum('late_seconds'),
                'total_late_minutes' => $data->sum('late_minutes'),
                'total_late_duration' => $this->formatDuration($data->sum('late_seconds')),
                'total_salary_cut' => $totalSalaryCut,
                'payable_salary' => $this->payableSalary($user, $startDate, $totalSalaryCut),
                'leave_days' => $leaveSummary['leave_days'],
                'annual_leave_taken' => $leaveSummary['annual_leave_taken'],
                'annual_leave_limit' => $leaveSummary['annual_leave_limit'],
                'annual_leave_remaining' => $leaveSummary['annual_leave_remaining'],
            ],
            'data' => $data,
        ];
    }

    private function csvDownload(string $filename, $rows)
    {
        return response()->streamDownload(function () use ($rows) {
            $output = fopen('php://output', 'w');

            fwrite($output, "\xEF\xBB\xBF");
            fwrite($output, "sep=,\r\n");

            foreach ($rows as $row) {
                fputcsv($output, $row);
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function reportMonthLabel(string $month): string
    {
        return Carbon::createFromFormat('Y-m', $month)->format('F Y');
    }

    private function reportStatus(?string $status): ?string
    {
        return $status ? str($status)->replace('_', ' ')->title()->toString() : null;
    }

    private function employeeName($user): ?string
    {
        $name = trim((string) data_get($user, 'first_name').' '.(string) data_get($user, 'last_name'));

        return $name !== '' ? $name : data_get($user, 'name');
    }

    private function visibleAttendanceQuery(?UserModel $viewer): Builder
    {
        return Attendance::with([
            'user.organization.timing',
            'user.organization.attendancePolicy',
            'user.staffDetail',
        ])
            ->whereHas('user', fn (Builder $query) => $query->visibleTo($viewer));
    }

    private function attendancePolicyViolation(
        UserModel $user,
        AttendanceStatus $status,
        Carbon $attendanceDate,
        ?int $ignoreAttendanceId = null
    ) {
        $policy = $user->organization?->attendancePolicy
            ?? OrganizationAttendancePolicy::defaultPolicy();

        if ($status === AttendanceStatus::HalfDay && ! $policy->allow_half_day) {
            return response()->json([
                'status' => false,
                'message' => 'Half-day attendance is disabled for this organization',
            ], 422);
        }

        if ($status !== AttendanceStatus::Leave) {
            return null;
        }

        if (! $policy->allow_leave) {
            return response()->json([
                'status' => false,
                'message' => 'Leave attendance is disabled for this organization',
            ], 422);
        }

        return null;
    }

    /**
     * @return array{leave_days: int, annual_leave_taken: int, annual_leave_limit: int, annual_leave_remaining: int|null}
     */
    private function leaveSummary(UserModel $user, Carbon $startDate, Carbon $endDate): array
    {
        $policy = $user->organization?->attendancePolicy
            ?? OrganizationAttendancePolicy::defaultPolicy();

        $yearStart = $startDate->copy()->startOfYear();
        $yearEnd = $startDate->copy()->endOfYear();

        $leaveDays = Attendance::where('user_id', $user->id)
            ->where('status', AttendanceStatus::Leave->value)
            ->whereBetween('attendance_date', [
                $startDate->toDateString(),
                $endDate->toDateString(),
            ])
            ->count();

        $annualLeaveTaken = Attendance::where('user_id', $user->id)
            ->where('status', AttendanceStatus::Leave->value)
            ->whereBetween('attendance_date', [
                $yearStart->toDateString(),
                $yearEnd->toDateString(),
            ])
            ->count();

        $annualLeaveRemaining = $policy->annual_leave_limit > 0
            ? max(0, $policy->annual_leave_limit - $annualLeaveTaken)
            : null;

        return [
            'leave_days' => $leaveDays,
            'annual_leave_taken' => $annualLeaveTaken,
            'annual_leave_limit' => $policy->annual_leave_limit,
            'annual_leave_remaining' => $annualLeaveRemaining,
        ];
    }

    private function withLateSalaryCut($record, UserModel $user, OrganizationTiming $timing)
    {
        $attendanceDate = Carbon::parse(data_get($record, 'attendance_date'));
        $checkIn = data_get($record, 'check_in');
        $lateSeconds = $this->lateSeconds($attendanceDate, $checkIn, $timing);
        $lateMinutes = (int) floor($lateSeconds / 60);
        $salaryCut = $this->salaryCut($record, $user, $timing, $attendanceDate, $lateMinutes);

        data_set($record, 'late_seconds', $lateSeconds);
        data_set($record, 'late_minutes', $lateMinutes);
        data_set($record, 'late_duration', $this->formatDuration($lateSeconds));
        data_set($record, 'salary_cut', round($salaryCut, 2));
        data_set($record, 'detail', $this->attendanceDetail($record, $user, $timing, $lateSeconds, $salaryCut));

        if ($this->attendanceStatusValue($record) === AttendanceStatus::Leave->value) {
            $unpaidLeave = $this->isUnpaidLeave($record, $user, $attendanceDate);

            data_set($record, 'unpaid_leave', $unpaidLeave);
            data_set($record, 'salary_cut_applied', $unpaidLeave);
            data_set($record, 'paid_leave', ! $unpaidLeave);
        }

        return $record;
    }

    private function employeeDetail(UserModel $user): array
    {
        return [
            'id' => $user->id,
            'employee_id' => $user->employee_id,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'phone_no' => $user->phone_no,
            'device_id' => $user->device_id,
            'is_admin' => $user->is_admin,
            'profile_image' => $user->profile_image,
            'organization' => $user->organization,
            'staff_detail' => $user->staffDetail,
        ];
    }

    private function attendanceDetail($record, UserModel $user, OrganizationTiming $timing, int $lateSeconds, float $salaryCut): array
    {
        $attendanceDate = Carbon::parse(data_get($record, 'attendance_date'));
        $checkIn = data_get($record, 'check_in');
        $checkOut = data_get($record, 'check_out');
        $checkInAt = $checkIn ? $this->timeToday($attendanceDate, $checkIn) : null;
        $checkOutAt = $checkOut ? $this->timeToday($attendanceDate, $checkOut) : null;
        $workedSeconds = $checkInAt && $checkOutAt
            ? max(0, (int) $checkInAt->diffInSeconds($checkOutAt))
            : 0;

        return [
            'employee' => $this->employeeDetail($user),
            'date' => [
                'value' => $attendanceDate->toDateString(),
                'day_name' => $attendanceDate->format('l'),
                'is_weekend' => $attendanceDate->isWeekend(),
            ],
            'timing' => [
                'check_in_start' => $this->timeToday($attendanceDate, $timing->check_in_start)->format('h:i:s A'),
                'check_in_end' => $this->timeToday($attendanceDate, $timing->check_in_end)->format('h:i:s A'),
                'late_after' => $this->timeToday($attendanceDate, $timing->late_after)->format('h:i:s A'),
                'check_out_start' => $this->timeToday($attendanceDate, $timing->check_out_start)->format('h:i:s A'),
            ],
            'check_in_at' => $checkInAt?->toDateTimeString(),
            'check_out_at' => $checkOutAt?->toDateTimeString(),
            'worked_seconds' => $workedSeconds,
            'worked_minutes' => (int) floor($workedSeconds / 60),
            'worked_duration' => $this->formatDuration($workedSeconds),
            'late_seconds' => $lateSeconds,
            'late_minutes' => (int) floor($lateSeconds / 60),
            'late_duration' => $this->formatDuration($lateSeconds),
            'salary_cut' => round($salaryCut, 2),
        ];
    }

    private function salaryCut($record, UserModel $user, OrganizationTiming $timing, Carbon $date, int $lateMinutes): float
    {
        if ($this->attendanceStatusValue($record) === AttendanceStatus::Absent->value) {
            return $this->salaryPerWorkingDay($user, $date);
        }

        if (
            $this->attendanceStatusValue($record) === AttendanceStatus::Leave->value
            && $this->isUnpaidLeave($record, $user, $date)
        ) {
            return $this->salaryPerWorkingDay($user, $date);
        }

        return $lateMinutes * $this->salaryPerWorkingMinute($user, $timing, $date);
    }

    private function isUnpaidLeave($record, UserModel $user, Carbon $date): bool
    {
        $policy = $user->organization?->attendancePolicy
            ?? OrganizationAttendancePolicy::defaultPolicy();

        if ($policy->annual_leave_limit <= 0) {
            return false;
        }

        $leaveNumberForYear = Attendance::where('user_id', $user->id)
            ->where('status', AttendanceStatus::Leave->value)
            ->whereBetween('attendance_date', [
                $date->copy()->startOfYear()->toDateString(),
                $date->toDateString(),
            ])
            ->when(
                data_get($record, 'id'),
                fn (Builder $query, int $id) => $query
                    ->where(fn (Builder $query) => $query
                        ->where('attendance_date', '<', $date->toDateString())
                        ->orWhere(fn (Builder $query) => $query
                            ->whereDate('attendance_date', $date->toDateString())
                            ->whereKey($id)))
            )
            ->count();

        return $leaveNumberForYear > $policy->annual_leave_limit;
    }

    private function attendanceStatusValue($record): ?string
    {
        $status = data_get($record, 'status');

        return $status instanceof AttendanceStatus ? $status->value : $status;
    }

    private function lateSeconds(Carbon $date, ?string $checkIn, OrganizationTiming $timing): int
    {
        if (! $checkIn) {
            return 0;
        }

        $lateAfter = $this->timeToday($date, $timing->late_after);
        $checkedInAt = $this->timeToday($date, $checkIn);

        if ($checkedInAt->lte($lateAfter)) {
            return 0;
        }

        return (int) $lateAfter->diffInSeconds($checkedInAt);
    }

    private function formatDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds);
    }

    private function salaryPerWorkingMinute(UserModel $user, OrganizationTiming $timing, Carbon $date): float
    {
        $salary = $user->staffDetail?->salary;

        if (! $salary || $salary <= 0) {
            return 0;
        }

        $workingMinutes = max(1, $this->timeToday($date, $timing->check_in_start)
            ->diffInMinutes($this->timeToday($date, $timing->check_out_start)));

        return match ($user->staffDetail?->salary_frequency) {
            'daily' => $salary / $workingMinutes,
            'weekly' => $salary / (7 * $workingMinutes),
            'yearly' => $salary / ($date->daysInYear * $workingMinutes),
            default => $salary / ($date->daysInMonth * $workingMinutes),
        };
    }

    private function payableSalary(UserModel $user, Carbon $month, float $salaryCut): float
    {
        $salary = $user->staffDetail?->salary;

        if (! $salary || $salary <= 0) {
            return 0;
        }

        $monthlySalary = match ($user->staffDetail?->salary_frequency) {
            'daily' => $salary * $month->daysInMonth,
            'weekly' => ($salary / 7) * $month->daysInMonth,
            'yearly' => ($salary / $month->daysInYear) * $month->daysInMonth,
            default => $salary,
        };

        return round(max(0, $monthlySalary - $salaryCut), 2);
    }

    private function salaryPerWorkingDay(UserModel $user, Carbon $date): float
    {
        $salary = $user->staffDetail?->salary;

        if (! $salary || $salary <= 0) {
            return 0;
        }

        return match ($user->staffDetail?->salary_frequency) {
            'daily' => $salary,
            'weekly' => $salary / 7,
            'yearly' => $salary / $date->daysInYear,
            default => $salary / $date->daysInMonth,
        };
    }

    private function timeToday(Carbon $date, string $time): Carbon
    {
        return $date->copy()->setTimeFromTimeString($time);
    }

    private function viewerFromRequest(Request $request): ?UserModel
    {
        $admin = $request->attributes->get('admin_user');

        if ($admin instanceof UserModel) {
            return $admin;
        }

        $employeeId = $this->viewerIdentifier($request);

        if (! $employeeId) {
            return null;
        }

        return UserModel::where('employee_id', $employeeId)->first();
    }

    private function viewerWasRequested(Request $request): bool
    {
        return (bool) $this->viewerIdentifier($request);
    }

    private function viewerIdentifier(Request $request): ?string
    {
        return $request->input('viewer_employee_id')
            ?? $request->input('admin_employee_id')
            ?? $request->header('X-Viewer-Employee-Id')
            ?? $request->header('X-Admin-Employee-Id');
    }

    private function viewerNotFoundResponse()
    {
        return response()->json([
            'status' => false,
            'message' => 'Viewer not found',
        ], 404);
    }
}
