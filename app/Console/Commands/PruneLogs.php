<?php

namespace App\Console\Commands;

use App\Models\DeploymentStep;
use Illuminate\Console\Command;

class PruneLogs extends Command
{
    protected $signature = 'deployhub:prune-logs';

    protected $description = 'Remove old step output while retaining deployment and audit metadata';

    public function handle(): int
    {
        $count = DeploymentStep::where('finished_at', '<', now()->subDays(max(1, config('deployhub.log_retention_days'))))->whereNotNull('output')->update(['output' => null]);
        $this->info("Pruned output from $count steps.");

        return self::SUCCESS;
    }
}
