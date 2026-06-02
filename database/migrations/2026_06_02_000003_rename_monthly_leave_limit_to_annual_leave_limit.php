<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('organization_timings', 'monthly_leave_limit')) {
            return;
        }

        Schema::table('organization_timings', function (Blueprint $table) {
            $table->renameColumn('monthly_leave_limit', 'annual_leave_limit');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('organization_timings', 'annual_leave_limit')) {
            return;
        }

        Schema::table('organization_timings', function (Blueprint $table) {
            $table->renameColumn('annual_leave_limit', 'monthly_leave_limit');
        });
    }
};
