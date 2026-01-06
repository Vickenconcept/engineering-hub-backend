<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create admin user
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@engineeringhub.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        // Create client users
        User::create([
            'name' => 'John Doe',
            'email' => 'client@example.com',
            'phone' => '+1234567890',
            'password' => Hash::make('password'),
            'role' => User::ROLE_CLIENT,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        User::create([
            'name' => 'Jane Smith',
            'email' => 'jane@example.com',
            'phone' => '+1234567891',
            'password' => Hash::make('password'),
            'role' => User::ROLE_CLIENT,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        // Create company users
        User::create([
            'name' => 'Building Masters Ltd',
            'email' => 'company@example.com',
            'phone' => '+2341234567890',
            'password' => Hash::make('password'),
            'role' => User::ROLE_COMPANY,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        User::create([
            'name' => 'Elite Construction',
            'email' => 'elite@example.com',
            'phone' => '+2341234567891',
            'password' => Hash::make('password'),
            'role' => User::ROLE_COMPANY,
            'status' => User::STATUS_PENDING,
            'email_verified_at' => now(),
        ]);
    }
}
