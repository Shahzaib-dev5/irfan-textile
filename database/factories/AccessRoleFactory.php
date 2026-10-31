<?php

namespace Database\Factories;

use App\Models\AccessRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AccessRole> */
class AccessRoleFactory extends Factory
{
    protected $model = AccessRole::class;

    public function definition(): array
    {
        return ['code' => fake()->unique()->slug(2), 'name' => fake()->jobTitle(), 'description' => fake()->sentence(), 'is_active' => true];
    }
}
