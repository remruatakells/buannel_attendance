<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\UserModel;
use Carbon\Carbon;
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
                'message' => 'User not found',
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
            if (! $now->betweenIncluded($now->copy()->setTime(9, 0), $now->copy()->setTime(10, 0))) {
                return response()->json([
                    'status' => false,
                    'message' => 'Check-in is allowed only between 09:00 AM and 10:00 AM',
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

    public function today()
    {
        $data = Attendance::with('user')
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

        $query = Attendance::with('user');

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

        $user = UserModel::where('employee_id', $userId)->first();

        if (! $user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ], 404);
        }

        $query = Attendance::with('user')
            ->where('user_id', $user->id);

        if (isset($validated['month'])) {
            $month = Carbon::createFromFormat('Y-m', $validated['month']);

            $query->whereBetween('attendance_date', [
                $month->copy()->startOfMonth()->toDateString(),
                $month->copy()->endOfMonth()->toDateString(),
            ]);
        }

        $data = $query->orderByDesc('attendance_date')->get();

        return response()->json([
            'status' => true,
            'month' => $validated['month'] ?? null,
            'data' => $data,
        ]);
    }

    public function update(Request $request, $id)
    {
        $attendance = Attendance::with('user')->find($id);

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

    public function delete($id)
    {
        $attendance = Attendance::find($id);

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
}
