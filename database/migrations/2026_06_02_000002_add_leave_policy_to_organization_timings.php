<?php

use App\Models\OrganizationAttendancePolicy;
use App\Models\OrganizationTiming;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('organization_timings', function (Blueprint $table) {
            $table->time('half_day_after')
                ->default(OrganizationTiming::DEFAULT_HALF_DAY_AFTER)
                ->after('late_after');
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
    }

    public function down(): void
    {
        Schema::table('organization_timings', function (Blueprint $table) {
            $table->dropColumn([
                'half_day_after',
                'allow_half_day',
                'allow_leave',
                'annual_leave_limit',
            ]);
        });
    }
};
