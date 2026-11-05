<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class DemoAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = strtolower(trim((string) config('demo.admin_email')));
        $password = config('demo.admin_password');

        if ($password === null || $password === '') {
            return;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! is_string($password) || strlen($password) < 16) {
            throw new RuntimeException('Set DEMO_ADMIN_EMAIL to a valid email and DEMO_ADMIN_PASSWORD to at least 16 characters.');
        }

        $user = User::query()->firstOrNew(['email' => $email]);

        if ($user->exists) {
            if ($user->role !== 'admin') {
                throw new RuntimeException('DEMO_ADMIN_EMAIL already belongs to a non-admin account. Choose a different email or promote that account explicitly.');
            }

            return;
        }

        $user->forceFill([
            'name' => 'Demo Administrator',
            'password' => $password,
            'email_verified_at' => now(),
            'role' => 'admin',
            'is_active' => true,
        ])->save();
    }
}
