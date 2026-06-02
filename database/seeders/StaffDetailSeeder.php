<?php

namespace Database\Seeders;

use App\Models\StaffDetail;
use App\Models\UserModel;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class StaffDetailSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $users = UserModel::with('organization')->get();

        foreach ($users as $user) {
            $organizationType = $user->organization?->type ?? 'organization';

            StaffDetail::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'salary' => match ($organizationType) {
                        'company' => 50000.00,
                        'university' => 40000.00,
                        'school' => 30000.00,
                        default => 45000.00,
                    },
                    'salary_currency' => 'USD',
                    'salary_frequency' => 'monthly',
                    'join_date' => $user->created_at?->toDateString() ?? now()->toDateString(),
                    'position' => $user->is_admin ? 'Administrator' : 'Staff',
                    'department' => $organizationType === 'university' ? 'Academic' : 'Operations',
                    'notes' => 'Auto-seeded staff detail.',
                ],
            );
        }
    }
}
