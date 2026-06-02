<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\OrganizationTiming;
use App\Models\UserModel;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrganizationTimingController extends Controller
{
    public function show(Request $request, Organization $organization)
    {
        if (! $this->viewerCanAccessOrganization($request, $organization)) {
            return $this->organizationNotFoundResponse();
        }

        return response()->json([
            'status' => true,
            'data' => $organization->timing ?? $this->defaultTimingFor($organization),
        ]);
    }

    public function update(Request $request, Organization $organization)
    {
        if (! $this->viewerCanAccessOrganization($request, $organization)) {
            return $this->organizationNotFoundResponse();
        }

        $validated = $request->validate([
            'check_in_start' => ['sometimes', 'date_format:H:i:s'],
            'check_in_end' => ['sometimes', 'date_format:H:i:s'],
            'late_after' => ['sometimes', 'date_format:H:i:s'],
            'half_day_after' => ['sometimes', 'date_format:H:i:s'],
            'check_out_start' => ['sometimes', 'date_format:H:i:s'],
        ]);

        $timing = $organization->timing ?? $this->defaultTimingFor($organization);
        $payload = array_merge($timing->only([
            'check_in_start',
            'check_in_end',
            'late_after',
            'half_day_after',
            'check_out_start',
        ]), $validated);

        $this->validateTimingOrder($payload);

        $timing = OrganizationTiming::updateOrCreate(
            ['organization_id' => $organization->id],
            $payload,
        );

        return response()->json([
            'status' => true,
            'message' => 'Organization timing updated',
            'data' => $timing,
        ]);
    }

    private function defaultTimingFor(Organization $organization): OrganizationTiming
    {
        return new OrganizationTiming([
            ...OrganizationTiming::defaults(),
            'organization_id' => $organization->id,
        ]);
    }

    /**
     * @param  array<string, bool|int|string>  $payload
     */
    private function validateTimingOrder(array $payload): void
    {
        if ($payload['check_in_start'] > $payload['late_after']) {
            throw ValidationException::withMessages([
                'late_after' => 'Late time must be after or equal to check-in start time.',
            ]);
        }

        if ($payload['late_after'] > $payload['check_in_end']) {
            throw ValidationException::withMessages([
                'late_after' => 'Late time must be before or equal to check-in end time.',
            ]);
        }

        if ($payload['half_day_after'] < $payload['check_in_end']) {
            throw ValidationException::withMessages([
                'half_day_after' => 'Half-day time must be after or equal to check-in end time.',
            ]);
        }

        if ($payload['half_day_after'] > $payload['check_out_start']) {
            throw ValidationException::withMessages([
                'half_day_after' => 'Half-day time must be before or equal to check-out start time.',
            ]);
        }
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
