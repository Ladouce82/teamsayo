<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeStaff extends Command
{
    protected $signature = 'teamsayo:staff {email} {--remove}';
    protected $description = 'Give (or remove) TeamSayo staff access to the operator console.';

    public function handle(): int
    {
        $user = User::where('email', strtolower($this->argument('email')))->first();
        if (! $user) {
            $this->error('No account with that email.');

            return self::FAILURE;
        }
        $user->forceFill(['is_staff' => ! $this->option('remove')])->save();
        $this->info($user->email . ($user->is_staff ? ' is now staff.' : ' is no longer staff.'));

        return self::SUCCESS;
    }
}
