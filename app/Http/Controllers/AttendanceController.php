<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
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
        $user = UserModel::where('employee_id', $validated['user_id'])->first();

        if (! $user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found, enroll first.',
            ], 404);
        }

        $attendance = Attendance::where('user_id', $user->id)
            ->whereDate('attendance_date', $today)
            ->first();

        if ($attendance?->check_out) {
            return response()->json([
                'status' => false,
                'message' => 'Already checked out today',
                'action' => 'completed',
                'data' => $attendance->load('user'),
            ], 409);
        }

        if (! $attendance) {
            if (! $now->betweenIncluded($now->copy()->setTime(9, 0), $now->copy()->setTime(12, 0))) {
                return response()->json([
                    'status' => false,
                    'message' => 'Check-in is allowed only between 09:00 AM and 12:00 PM',
                    'action' => 'check_in_closed',
                ], 409);
            }

            $attendance = Attendance::create([
                'user_id' => $user->id,
                'attendance_date' => $today,
                'check_in' => $time,
                'status' => 'present',
            ])->load('user');

            return response()->json([
                'status' => true,
                'message' => 'Check-in successful',
                'action' => 'check_in',
                'data' => $attendance,
            ]);
        }

        if ($now->lt($now->copy()->setTime(16, 0))) {
            return response()->json([
                'status' => false,
                'message' => 'Check-out is allowed from 04:00 PM',
                'action' => 'check_out_closed',
                'data' => $attendance->load('user'),
            ], 409);
        }

        $attendance->update([
            'check_out' => $time,
        ]);
        $attendance->load('user');

        return response()->json([
            'status' => true,
            'message' => 'Check-out successful',
            'action' => 'check_out',
            'data' => $attendance,
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
            ->get();

        return response()->json([
            'status' => true,
            'data' => $data,
        ]);
    }

    public function adminAttendance(Request $request)
    {
        $validated = $request->validate([
            'month' => ['sometimes', 'date_format:Y-m'],
        ]);

        $viewer = $this->viewerFromRequest($request);

        if ($this->viewerWasRequested($request) && ! $viewer) {
            return $this->viewerNotFoundResponse();
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
            ->get();

        return response()->json([
            'status' => true,
            'month' => $validated['month'] ?? null,
            'data' => $data,
        ]);
    }

    public function userAttendance(Request $request, $userId)
    {
        $validated = $request->validate([
            'month' => ['sometimes', 'date_format:Y-m'],
        ]);

        $viewer = $this->viewerFromRequest($request);

        if ($this->viewerWasRequested($request) && ! $viewer) {
            return $this->viewerNotFoundResponse();
        }

        $user = UserModel::visibleTo($viewer)
            ->where('employee_id', $userId)
            ->first();

        if (! $user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ], 404);
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

        for ($date = $endDate->copy(); $date->gte($startDate); $date->subDay()) {
            $dateString = $date->toDateString();

            $data->push($attendances->get($dateString) ?? [
                'id' => null,
                'user_id' => $user->id,
                'attendance_date' => $dateString,
                'check_in' => null,
                'check_out' => null,
                'status' => 'absent',
                'created_at' => null,
                'updated_at' => null,
                'user' => $user,
            ]);
        }

        return response()->json([
            'status' => true,
            'month' => $month->format('Y-m'),
            'data' => $data,
        ]);
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
            'status' => ['sometimes', 'string', Rule::in(['present', 'absent', 'late', 'half_day'])],
        ]);

        $attendance->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Attendance updated',
            'data' => $attendance,
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

    private function visibleAttendanceQuery(?UserModel $viewer): Builder
    {
        return Attendance::with('user.organization')
            ->whereHas('user', fn (Builder $query) => $query->visibleTo($viewer));
    }

    private function viewerFromRequest(Request $request): ?UserModel
    {
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
