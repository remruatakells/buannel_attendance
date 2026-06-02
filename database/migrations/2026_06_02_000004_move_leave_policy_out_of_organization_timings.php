<?php

use App\Models\OrganizationAttendancePolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('organization_attendance_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('allow_half_day')->default(OrganizationAttendancePolicy::DEFAULT_ALLOW_HALF_DAY);
            $table->boolean('allow_leave')->default(OrganizationAttendancePolicy::DEFAULT_ALLOW_LEAVE);
            $table->unsignedSmallInteger('annual_leave_limit')
                ->default(OrganizationAttendancePolicy::DEFAULT_ANNUAL_LEAVE_LIMIT);
            $table->timestamps();
        });

        $hasLegacyPolicyColumns = Schema::hasColumn('organization_timings', 'allow_half_day')
            && Schema::hasColumn('organization_timings', 'allow_leave')
            && Schema::hasColumn('organization_timings', 'annual_leave_limit');

        DB::table('organizations')
            ->leftJoin('organization_timings', 'organizations.id', '=', 'organization_timings.organization_id')
            ->orderBy('organizations.id')
            ->get([
                'organizations.id as organization_id',
                ...( $hasLegacyPolicyColumns ? [
                    'organization_timings.allow_half_day',
                    'organization_timings.allow_leave',
                    'organization_timings.annual_leave_limit',
                ] : [] ),
            ])
            ->each(function (object $organization) use ($hasLegacyPolicyColumns): void {
                DB::table('organization_attendance_policies')->insert([
                    'organization_id' => $organization->organization_id,
                    'allow_half_day' => $hasLegacyPolicyColumns
                        ? (bool) $organization->allow_half_day
                        : OrganizationAttendancePolicy::DEFAULT_ALLOW_HALF_DAY,
                    'allow_leave' => $hasLegacyPolicyColumns
                        ? (bool) $organization->allow_leave
                        : OrganizationAttendancePolicy::DEFAULT_ALLOW_LEAVE,
                    'annual_leave_limit' => $hasLegacyPolicyColumns
                        ? (int) $organization->annual_leave_limit
                        : OrganizationAttendancePolicy::DEFAULT_ANNUAL_LEAVE_LIMIT,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        if ($hasLegacyPolicyColumns) {
            Schema::table('organization_timings', function (Blueprint $table) {
                $table->dropColumn([
                    'allow_half_day',
                    'allow_leave',
                    'annual_leave_limit',
                ]);
            });
        }
    }

    public function down(): void
    {
        Schema::table('organization_timings', function (Blueprint $table) {
            $table->boolean('allow_half_day')
                ->default(OrganizationAttendancePolicy::DEFAULT_ALLOW_HALF_DAY)
                ->after('check_out_start');
            $table->boolean('allow_leave')
                ->default(OrganizationAttendancePolicy::DEFAULT_ALLOW_LEAVE)
                ->after('allow_half_day');
            $table->unsignedSmallInteger('annual_leave_limit')
                ->default(OrganizationAttendancePolicy::DEFAULT_ANNUAL_LEAVE_LIMIT)
                ->after('allow_leave');
        });

        DB::table('organization_attendance_policies')
            ->orderBy('id')
            ->get()
            ->each(function (object $policy): void {
                DB::table('organization_timings')
                    ->where('organization_id', $policy->organization_id)
                    ->update([
                        'allow_half_day' => $policy->allow_half_day,
                        'allow_leave' => $policy->allow_leave,
                        'annual_leave_limit' => $policy->annual_leave_limit,
                    ]);
            });

        Schema::dropIfExists('organization_attendance_policies');
    }
};
