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
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('organization');
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->nullable()
                ->after('profile_image')
                ->constrained()
                ->restrictOnDelete();
        });

        if (DB::table('users')->exists()) {
            $organizationId = DB::table('organizations')->insertGetId([
                'name' => 'Default Organization',
                'type' => 'organization',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('users')
                ->whereNull('organization_id')
                ->update(['organization_id' => $organizationId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });

        Schema::dropIfExists('organizations');
    }
};
