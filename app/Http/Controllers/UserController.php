<?php

namespace App\Http\Controllers;

use App\Models\UserModel;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $viewer = $this->viewerFromRequest($request);

        if ($this->viewerWasRequested($request) && ! $viewer) {
            return $this->viewerNotFoundResponse();
        }

        return response()->json([
            'status' => true,
            'data' => UserModel::with('organization')
                ->visibleTo($viewer)
                ->orderBy('employee_id')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $viewer = $this->viewerFromRequest($request);

        if ($this->viewerWasRequested($request) && ! $viewer) {
            return $this->viewerNotFoundResponse();
        }

        $validated = $request->validate([
            'employee_id' => ['required', 'string', 'max:255', 'unique:users,employee_id'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'phone_no' => ['nullable', 'string', 'max:255'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'profile_image' => ['nullable', 'url', 'max:2048'],
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
        ]);

        if ($viewer && (int) $validated['organization_id'] !== $viewer->organization_id) {
            return $this->organizationNotAllowedResponse();
        }

        $user = UserModel::create($this->userData($validated))->load('organization');

        return response()->json([
            'status' => true,
            'message' => 'User created',
            'data' => $user,
        ], 201);
    }

    public function show(Request $request, UserModel $user)
    {
        if (! $this->viewerCanAccessUser($request, $user)) {
            return $this->userNotFoundResponse();
        }

        return response()->json([
            'status' => true,
            'data' => $user->load(['organization', 'attendances']),
        ]);
    }

    public function update(Request $request, UserModel $user)
    {
        if (! $this->viewerCanAccessUser($request, $user)) {
            return $this->userNotFoundResponse();
        }

        $validated = $request->validate([
            'employee_id' => ['sometimes', 'string', 'max:255', Rule::unique('users', 'employee_id')->ignore($user)],
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone_no' => ['sometimes', 'nullable', 'string', 'max:255'],
            'device_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile_image' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'organization_id' => ['sometimes', 'integer', 'exists:organizations,id'],
        ]);

        $viewer = $this->viewerFromRequest($request);

        if ($viewer && isset($validated['organization_id']) && (int) $validated['organization_id'] !== $viewer->organization_id) {
            return $this->organizationNotAllowedResponse();
        }

        $user->update($this->userData($validated, $user));
        $user->load('organization');

        return response()->json([
            'status' => true,
            'message' => 'User updated',
            'data' => $user,
        ]);
    }

    public function destroy(Request $request, UserModel $user)
    {
        if (! $this->viewerCanAccessUser($request, $user)) {
            return $this->userNotFoundResponse();
        }

        $user->delete();

        return response()->json([
            'status' => true,
            'message' => 'User deleted',
        ]);
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

        if ($firstName) {
            $payload['name'] = trim($firstName.' '.($lastName ?? ''));
        }

        return $payload;
    }

    private function viewerCanAccessUser(Request $request, UserModel $user): bool
    {
        $viewer = $this->viewerFromRequest($request);

        if ($this->viewerWasRequested($request) && ! $viewer) {
            return false;
        }

        if (! $viewer) {
            return true;
        }

        return $user->organization_id === $viewer->organization_id;
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
