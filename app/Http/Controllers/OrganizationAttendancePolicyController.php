<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\OrganizationAttendancePolicy;
use App\Models\UserModel;
use Illuminate\Http\Request;

class OrganizationAttendancePolicyController extends Controller
{
    public function show(Request $request, Organization $organization)
    {
        if (! $this->viewerCanAccessOrganization($request, $organization)) {
            return $this->organizationNotFoundResponse();
        }

        return response()->json([
            'status' => true,
            'data' => $organization->attendancePolicy ?? $this->defaultPolicyFor($organization),
        ]);
    }

    public function update(Request $request, Organization $organization)
    {
        if (! $this->viewerCanAccessOrganization($request, $organization)) {
            return $this->organizationNotFoundResponse();
        }

        $validated = $request->validate([
            'allow_half_day' => ['sometimes', 'boolean'],
            'allow_leave' => ['sometimes', 'boolean'],
            'annual_leave_limit' => ['sometimes', 'integer', 'min:0', 'max:366'],
        ]);

        $policy = OrganizationAttendancePolicy::updateOrCreate(
            ['organization_id' => $organization->id],
            $validated,
        );

        return response()->json([
            'status' => true,
            'message' => 'Organization attendance policy updated',
            'data' => $policy,
        ]);
    }

    private function defaultPolicyFor(Organization $organization): OrganizationAttendancePolicy
    {
        return new OrganizationAttendancePolicy([
            ...OrganizationAttendancePolicy::defaults(),
            'organization_id' => $organization->id,
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
