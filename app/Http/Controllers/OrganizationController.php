<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\OrganizationTiming;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    public function index()
    {
        return response()->json([
            'status' => true,
            'data' => Organization::with('timing')
                ->withCount('users')
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

        return response()->json([
            'status' => true,
            'message' => 'Organization created',
            'data' => $organization->load('timing'),
        ], 201);
    }

    public function show(Organization $organization)
    {
        return response()->json([
            'status' => true,
            'data' => $organization->load('timing')->loadCount('users'),
        ]);
    }

    public function update(Request $request, Organization $organization)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', Rule::in(['company', 'university', 'school', 'organization'])],
        ]);

        $organization->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Organization updated',
            'data' => $organization->load('timing'),
        ]);
    }

    public function destroy(Organization $organization)
    {
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
}
