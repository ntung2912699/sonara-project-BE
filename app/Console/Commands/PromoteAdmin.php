<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class PromoteAdmin extends Command
{
    protected $signature = 'users:promote-admin {email : Email address of the existing account to promote}';

    protected $description = 'Promote an existing user account to administrator';

    public function handle(): int
    {
        $user = User::query()->where('email', strtolower($this->argument('email')))->first();

        if ($user === null) {
            $this->error('No account was found with that email address.');

            return self::FAILURE;
        }

        $user->forceFill(['role' => 'admin'])->save();

        $this->info("{$user->email} is now an administrator.");

        return self::SUCCESS;
    }
}
