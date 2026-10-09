<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InstallDeployHub extends Command
{
    protected $signature = 'deployhub:install {--email=} {--name=} {--workspace=My workspace} {--generate : Save a random initial password in a private local file}';

    protected $description = 'Create the first owner; never seeds a fixed password or real server';

    public function handle(): int
    {
        if (Workspace::exists()) {
            $this->error('Already initialized. Manage members from the dashboard.');

            return self::FAILURE;
        }
        $email = $this->option('email') ?: $this->ask('Owner email');
        $name = $this->option('name') ?: $this->ask('Owner name');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! $name) {
            $this->error('Valid email and name required.');

            return self::FAILURE;
        }
        $password = $this->option('generate') ? Str::random(32) : $this->secret('Owner password (at least 12 characters with letters and numbers)');
        if (strlen($password ?? '') < 12 || ! preg_match('/[a-zA-Z]/', $password) || ! preg_match('/[0-9]/', $password)) {
            $this->error('Password does not meet requirements.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($name, $email, $password) {
            $workspace = Workspace::create(['name' => $this->option('workspace'), 'slug' => Str::slug($this->option('workspace')).'-'.Str::lower(Str::random(6))]);
            $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
            $workspace->users()->attach($user, ['role' => 'owner', 'active' => true]);
            Audit::record($workspace->id, $user->id, 'workspace.initialized', 'workspace:'.$workspace->id);
        });
        if ($this->option('generate')) {
            $path = storage_path('app/private/initial-login.txt');
            file_put_contents($path, "DeployHub initial local login\nEmail: $email\nPassword: $password\n\nChange the password after login and delete this file. Configure MFA on the Security page.\n");
            if (PHP_OS_FAMILY !== 'Windows') {
                chmod($path, 0600);
            }
            $this->info('Initial credentials saved to storage/app/private/initial-login.txt (not committed or publicly served).');
        }
        $this->info('Workspace initialized. Sign in and enable MFA before adding targets.');

        return self::SUCCESS;
    }
}
