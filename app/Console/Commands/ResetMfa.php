<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Audit;
use App\Services\Deployment\LogSanitizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetMfa extends Command
{
    protected $signature = 'deployhub:reset-mfa {email} {--reason=}';

    protected $description = 'Local administrator recovery for a lost authenticator, with session revocation';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->firstOrFail();
        if (! $this->option('reason') || ! $this->confirm('Reset MFA and revoke all sessions for this member?', false)) {
            return self::FAILURE;
        }
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_counter' => null, 'remember_token' => null])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        foreach ($user->workspaces as $workspace) {
            Audit::record($workspace->id, null, 'identity.mfa_recovered', 'user:'.$user->id, ['reason' => app(LogSanitizer::class)->clean($this->option('reason'))]);
        }
        $this->info('MFA reset. Member must enroll again before privileged actions.');

        return self::SUCCESS;
    }
}
