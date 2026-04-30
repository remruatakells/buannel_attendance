<?php

namespace App\Http\Controllers;

use App\Models\UserModel;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        return response()->json([
            'status' => true,
            'data' => UserModel::orderBy('employee_id')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'string', 'max:255', 'unique:users,employee_id'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'phone_no' => ['nullable', 'string', 'max:255'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'profile_image' => ['nullable', 'url', 'max:2048'],
        ]);

        $user = UserModel::create($this->userData($validated));

        return response()->json([
            'status' => true,
            'message' => 'User created',
            'data' => $user,
        ], 201);
    }

    public function show(UserModel $user)
    {
        return response()->json([
            'status' => true,
            'data' => $user->load('attendances'),
        ]);
    }

    public function update(Request $request, UserModel $user)
    {
        $validated = $request->validate([
            'employee_id' => ['sometimes', 'string', 'max:255', Rule::unique('users', 'employee_id')->ignore($user)],
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone_no' => ['sometimes', 'nullable', 'string', 'max:255'],
            'device_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile_image' => ['sometimes', 'nullable', 'url', 'max:2048'],
        ]);

        $user->update($this->userData($validated, $user));

        return response()->json([
            'status' => true,
            'message' => 'User updated',
            'data' => $user,
        ]);
    }

    public function destroy(UserModel $user)
    {
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
}
