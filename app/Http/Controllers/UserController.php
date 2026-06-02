<?php

namespace App\Http\Controllers;

use App\Models\UserModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Log;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $viewer = $this->viewerFromRequest($request);

        if ($this->viewerWasRequested($request) && !$viewer) {
            return $this->viewerNotFoundResponse();
        }

        $users = UserModel::with(['organization', 'staffDetail'])
            ->visibleTo($viewer)
            ->orderBy('employee_id')
            ->get();

        $users->each(fn (UserModel $user) => $this->applySalaryVisibility($request, $user));

        return response()->json([
            'status' => true,
            'data' => $users,
        ]);
    }

    public function store(Request $request)
    {
        $viewer = $this->viewerFromRequest($request);

        if ($this->viewerWasRequested($request) && !$viewer) {
            return $this->viewerNotFoundResponse();
        }

        $validated = $request->validate([
            'employee_id' => ['required', 'string', 'max:255', 'unique:users,employee_id'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'phone_no' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'min:6', 'max:255'],
            'isAdmin' => ['nullable', 'boolean'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'profile_image' => ['nullable', 'url', 'max:2048'],
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
        ]);

        if ($viewer && (int) $validated['organization_id'] !== $viewer->organization_id) {
            return $this->organizationNotAllowedResponse();
        }

        $user = UserModel::create($this->userData($validated))->load('organization');

        $this->applySalaryVisibility($request, $user);

        return response()->json([
            'status' => true,
            'message' => 'User created',
            'data' => $user,
        ], 201);
    }

    public function show(Request $request, UserModel $user)
    {
        if (!$this->viewerCanAccessUser($request, $user)) {
            return $this->userNotFoundResponse();
        }

        $user->load(['organization', 'attendances', 'staffDetail']);

        $this->applySalaryVisibility($request, $user);

        return response()->json([
            'status' => true,
            'data' => $user,
        ]);
    }

    public function update(Request $request, UserModel $user)
    {
        if (!$this->viewerCanAccessUser($request, $user)) {
            return $this->userNotFoundResponse();
        }

        $validated = $request->validate([
            'employee_id' => ['sometimes', 'string', 'max:255', Rule::unique('users', 'employee_id')->ignore($user)],
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone_no' => ['sometimes', 'nullable', 'string', 'max:255'],
            'password' => ['sometimes', 'nullable', 'string', 'min:6', 'max:255'],
            'isAdmin' => ['sometimes', 'boolean'],
            'device_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile_image' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'organization_id' => ['sometimes', 'integer', 'exists:organizations,id'],
            'staff_detail' => ['sometimes', 'array'],
            'staff_detail.position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'staff_detail.department' => ['sometimes', 'nullable', 'string', 'max:255'],
            'staff_detail.join_date' => ['sometimes', 'nullable', 'date'],
            'staff_detail.salary' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'staff_detail.salary_currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'staff_detail.salary_frequency' => ['sometimes', 'nullable', 'string', 'max:255'],
            'staff_detail.notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $viewer = $this->viewerFromRequest($request);
        $staffDetail = $validated['staff_detail'] ?? null;
        unset($validated['staff_detail']);

        if ($viewer && isset($validated['organization_id']) && (int) $validated['organization_id'] !== $viewer->organization_id) {
            return $this->organizationNotAllowedResponse();
        }

        $user->update($this->userData($validated, $user));

        if (is_array($staffDetail)) {
            $user->staffDetail()->updateOrCreate(
                ['user_id' => $user->id],
                $this->staffDetailData($staffDetail),
            );
        }

        $user->load(['organization', 'staffDetail']);

        $this->applySalaryVisibility($request, $user);

        return response()->json([
            'status' => true,
            'message' => 'User updated',
            'data' => $user,
        ]);
    }

    public function destroy(Request $request, UserModel $user)
    {
        if (!$this->viewerCanAccessUser($request, $user)) {
            return $this->userNotFoundResponse();
        }

        $user->delete();

        return response()->json([
            'status' => true,
            'message' => 'User deleted',
        ]);
    }

    public function login(Request $request)
    {
        try {
            $validated = $request->validate([
                'phone_no' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'max:255'],
            ]);

            $user = UserModel::with('organization')
                ->where('phone_no', $validated['phone_no'])
                ->first();

            // 🔐 Invalid credentials
            if (!$user || !$user->password || !Hash::check($validated['password'], $user->password)) {
                return response()->json([
                    'status' => false,
                    'title' => 'Authentication Failed',
                    'message' => 'The provided phone number or password is incorrect. Please verify your credentials and try again.',
                ], 401);
            }

            // 🚫 Not admin
            if (! $user->is_admin) {
                return response()->json([
                    'status' => false,
                    'title' => 'Access Denied',
                    'message' => 'User is not admin',
                ], 403);
            }

            $user->admin_access_token = Str::random(60);
            $user->save();

            // ✅ Success
            return response()->json([
                'status' => true,
                'message' => 'Login successful.',
                'data' => [
                    'id' => $user->id,
                    'employee_id' => $user->employee_id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'phone_no' => $user->phone_no,
                    'isAdmin' => $user->is_admin,
                    'organization_id' => $user->organization_id,
                    'access_token' => $user->admin_access_token,
                    'organization' => $user->organization ? [
                        'id' => $user->organization->id,
                        'name' => $user->organization->name,
                    ] : null,
                ],
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'status' => false,
                'title' => 'Validation Error',
                'message' => 'Invalid input provided.',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {

            // Log for debugging (important in production)
            Log::error('Login Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => false,
                'title' => 'Server Error',
                'message' => 'An unexpected error occurred. Please try again later.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function userData(array $data, ?UserModel $user = null): array
    {
        $firstName = $data['first_name'] ?? $user?->first_name;
        $lastName = array_key_exists('last_name', $data) ? $data['last_name'] : $user?->last_name;

        $payload = $data;

        if (array_key_exists('isAdmin', $payload)) {
            $payload['is_admin'] = $payload['isAdmin'];
            unset($payload['isAdmin']);
        }

        if (array_key_exists('password', $payload)) {
            if ($payload['password']) {
                $payload['password'] = Hash::make($payload['password']);
            } else {
                unset($payload['password']);
            }
        }

        if ($firstName) {
            $payload['name'] = trim($firstName . ' ' . ($lastName ?? ''));
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function staffDetailData(array $data): array
    {
        $payload = $data;

        foreach (['position', 'department', 'join_date', 'salary_currency', 'salary_frequency', 'notes'] as $field) {
            if (array_key_exists($field, $payload) && $payload[$field] === '') {
                $payload[$field] = null;
            }
        }

        if (array_key_exists('salary', $payload) && $payload['salary'] === '') {
            $payload['salary'] = null;
        }

        return $payload;
    }

    private function viewerCanAccessUser(Request $request, UserModel $user): bool
    {
        $viewer = $this->viewerFromRequest($request);

        if ($this->viewerWasRequested($request) && !$viewer) {
            return false;
        }

        if (!$viewer) {
            return true;
        }

        return $user->organization_id === $viewer->organization_id;
    }

    private function applySalaryVisibility(Request $request, UserModel $user): void
    {
        if (! $user->staffDetail) {
            return;
        }

        $viewer = $this->viewerFromRequest($request);

        if ($viewer && $viewer->is_admin && $viewer->organization_id === $user->organization_id) {
            $user->staffDetail->makeVisible('salary');

            return;
        }

        if ($viewer && $viewer->id === $user->id) {
            $user->staffDetail->makeVisible('salary');
        }
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

    private function userNotFoundResponse()
    {
        return response()->json([
            'status' => false,
            'message' => 'User not found',
        ], 404);
    }

    private function organizationNotAllowedResponse()
    {
        return response()->json([
            'status' => false,
            'message' => 'Organization not allowed',
        ], 403);
    }
}
