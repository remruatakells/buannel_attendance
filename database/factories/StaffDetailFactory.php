<?php

namespace Database\Factories;

use App\Models\StaffDetail;
use App\Models\UserModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffDetail>
 */
class StaffDetailFactory extends Factory
{
    protected $model = StaffDetail::class;

    public function definition(): array
    {
        return [
            'user_id' => UserModel::factory(),
            'salary' => fake()->randomFloat(2, 20000, 120000),
            'salary_currency' => 'USD',
            'salary_frequency' => fake()->randomElement(['monthly', 'yearly', 'weekly']),
            'join_date' => fake()->dateTimeBetween('-5 years', 'now')->format('Y-m-d'),
            'position' => fake()->jobTitle(),
            'department' => fake()->randomElement(['Operations', 'Finance', 'HR', 'Development']),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
