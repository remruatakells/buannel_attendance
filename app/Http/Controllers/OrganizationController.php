<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\OrganizationAttendancePolicy;
use App\Models\OrganizationTiming;
use App\Models\UserModel;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    public function index(Request $request)
    {
        $viewer = $this->viewerFromRequest($request);

        return response()->json([
            'status' => true,
            'data' => Organization::with(['timing', 'attendancePolicy'])
                ->withCount('users')
                ->when($viewer, fn ($query) => $query->whereKey($viewer->organization_id))
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(['company', 'university', 'school', 'organization'])],
        ]);

        $organization = Organization::create($validated);
        $organization->timing()->create(OrganizationTiming::defaults());
        $organization->attendancePolicy()->create(OrganizationAttendancePolicy::defaults());

        return response()->json([
            'status' => true,
            'message' => 'Organization created',
            'data' => $organization->load(['timing', 'attendancePolicy']),
        ], 201);
    }

    public function show(Request $request, Organization $organization)
    {
        if (! $this->viewerCanAccessOrganization($request, $organization)) {
            return $this->organizationNotFoundResponse();
        }

        return response()->json([
            'status' => true,
            'data' => $organization->load(['timing', 'attendancePolicy'])->loadCount('users'),
        ]);
    }

    public function update(Request $request, Organization $organization)
    {
        if (! $this->viewerCanAccessOrganization($request, $organization)) {
            return $this->organizationNotFoundResponse();
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', Rule::in(['company', 'university', 'school', 'organization'])],
        ]);

        $organization->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Organization updated',
            'data' => $organization->load(['timing', 'attendancePolicy']),
        ]);
    }

    public function destroy(Request $request, Organization $organization)
    {
        if (! $this->viewerCanAccessOrganization($request, $organization)) {
            return $this->organizationNotFoundResponse();
        }

        if ($organization->users()->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Organization has users',
            ], 409);
        }

        $organization->delete();

        return response()->json([
            'status' => true,
            'message' => 'Organization deleted',
        ]);
    }

    private function viewerCanAccessOrganization(Request $request, Organization $organization): bool
    {
        $viewer = $this->viewerFromRequest($request);

        if (! $viewer) {
            return true;
        }

        return $organization->id === $viewer->organization_id;
    }

    private function viewerFromRequest(Request $request): ?UserModel
    {
        $admin = $request->attributes->get('admin_user');

        return $admin instanceof UserModel ? $admin : null;
    }

    private function organizationNotFoundResponse()
    {
        return response()->json([
            'status' => false,
            'message' => 'Organization not found',
        ], 404);
    }
}
