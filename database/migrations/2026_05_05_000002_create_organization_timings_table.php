<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('organization_timings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->time('check_in_start')->default('09:00:00');
            $table->time('check_in_end')->default('10:00:00');
            $table->time('late_after')->default('09:30:00');
            $table->time('check_out_start')->default('16:00:00');
            $table->timestamps();
        });

        DB::table('organizations')
            ->orderBy('id')
            ->get(['id'])
            ->each(function (object $organization): void {
                DB::table('organization_timings')->insert([
                    'organization_id' => $organization->id,
                    'check_in_start' => '09:00:00',
                    'check_in_end' => '10:00:00',
                    'late_after' => '09:30:00',
                    'check_out_start' => '16:00:00',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_timings');
    }
};
