<?php

namespace CipiGui\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SeedGuiUser extends Command
{
    protected $signature = 'cipi:seed-gui-user
                            {--email= : Admin email address}
                            {--password= : Admin password (random if omitted)}
                            {--name= : Admin display name}
                            {--reset : Reset password and clear 2FA for the admin user}';

    protected $description = 'Create or update the Cipi GUI admin user';

    public function handle(): int
    {
        $email = $this->option('email') ?? config('cipi-gui.default_admin_email');
        $name = $this->option('name') ?? config('cipi-gui.default_admin_name');
        $password = $this->option('password');

        if ($this->option('reset')) {
            return $this->resetAdmin($email, $name, $password);
        }

        $password ??= Str::password(16);

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
            ],
        );

        $this->info('Cipi GUI admin user ready.');
        $this->line("  Email:    {$email}");
        $this->line("  Password: {$password}");
        $this->newLine();
        $this->comment('Store the password securely. Enable 2FA from Settings after first login.');

        return self::SUCCESS;
    }

    private function resetAdmin(string $email, string $name, ?string $password): int
    {
        if ($password === null || $password === '') {
            $this->error('Password is required when using --reset.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No GUI admin user found with email: {$email}");

            return self::FAILURE;
        }

        $attributes = [
            'name' => $name,
            'password' => Hash::make($password),
        ];

        if (Schema::hasColumn('users', 'two_factor_secret')) {
            $attributes['two_factor_secret'] = null;
        }
        if (Schema::hasColumn('users', 'two_factor_enabled')) {
            $attributes['two_factor_enabled'] = false;
        }
        if (Schema::hasColumn('users', 'two_factor_confirmed_at')) {
            $attributes['two_factor_confirmed_at'] = null;
        }

        $user->update($attributes);
        $this->clearUserSessions($user);

        $this->info('Cipi GUI admin user reset.');
        $this->line("  Email:    {$email}");
        $this->line("  Password: {$password}");
        $this->newLine();
        $this->comment('2FA has been disabled. Sign in and re-enable it from Settings if needed.');

        return self::SUCCESS;
    }

    private function clearUserSessions(User $user): void
    {
        if (! Schema::hasTable('sessions')) {
            return;
        }

        if (Schema::hasColumn('sessions', 'user_id')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
    }
}
