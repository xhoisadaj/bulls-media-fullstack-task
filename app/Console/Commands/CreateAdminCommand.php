<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('sheets:admin {email} {--password= : Password for a new account (generated when empty)}')]
#[Description('Create the admin account if it does not exist; prints "created <password>" or "exists"')]
class CreateAdminCommand extends Command
{
    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if (User::query()->where('email', $email)->exists()) {
            $this->line('exists');

            return self::SUCCESS;
        }

        $password = (string) ($this->option('password') ?: Str::password(16, symbols: false));
        User::query()->create(['name' => 'Admin', 'email' => $email, 'password' => $password, 'role' => UserRole::Admin]);
        $this->line('created '.$password);

        return self::SUCCESS;
    }
}
