<?php

namespace CipiGui\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SeedGuiUser extends Command
{
    protected $signature = 'cipi:seed-gui-user
                            {--email= : Admin email address}
                            {--password= : Admin password (random if omitted)}
                            {--name= : Admin display name}';

    protected $description = 'Create or update the Cipi GUI admin user';

    public function handle(): int
    {
        $email = $this->option('email') ?? config('cipi-gui.default_admin_email');
        $name = $this->option('name') ?? config('cipi-gui.default_admin_name');
        $password = $this->option('password') ?? Str::password(16);

        $user = User::updateOrCreate(
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
}
