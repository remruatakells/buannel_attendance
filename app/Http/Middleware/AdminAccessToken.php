<?php

namespace App\Http\Middleware;

use App\Models\UserModel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminAccessToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Admin-Access-Token')
            ?? $request->input('access_token')
            ?? $request->bearerToken();

        if (! $token) {
            return response()->json([
                'status' => false,
                'message' => 'Missing admin access token.',
            ], 401);
        }

        $admin = UserModel::where('admin_access_token', $token)
            ->where('is_admin', true)
            ->first();

        $expected = env('ADMIN_ACCESS_TOKEN', 'secret_admin_token');
        $employeeId = $request->input('admin_employee_id')
            ?? $request->header('X-Admin-Employee-Id');

        if (! $admin && hash_equals($expected, $token)) {
            if ($employeeId) {
                $admin = UserModel::where('employee_id', $employeeId)
                    ->where('is_admin', true)
                    ->first();
            }
        }

        if (! $admin) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid admin access token.',
            ], 401);
        }

        if ($employeeId && $employeeId !== $admin->employee_id) {
            return response()->json([
                'status' => false,
                'message' => 'Admin token does not match requested admin employee id.',
            ], 403);
        }

        $request->attributes->set('admin_user', $admin);

        return $next($request);
    }
}
