<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Creates the admin account. Credentials come from ADMIN_EMAIL / ADMIN_PASSWORD when set.
     */
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@example.com')],
            ['name' => 'Admin', 'password' => env('ADMIN_PASSWORD', 'password')],
        );
    }
}
