<?php

namespace Database\Factories;

use App\Models\UserModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UserModel>
 */
class UserFactory extends Factory
{
    protected $model = UserModel::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => fake()->unique()->bothify('EMP###'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone_no' => fake()->optional()->phoneNumber(),
            'device_id' => fake()->optional()->bothify('DEVICE_##'),
            'name' => fake()->name(),
            'remember_token' => Str::random(10),
        ];
    }
}
