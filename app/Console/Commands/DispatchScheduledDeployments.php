<?php

namespace App\Console\Commands;

use App\Services\Deployment\DeploymentScheduler;
use Illuminate\Console\Command;

class DispatchScheduledDeployments extends Command
{
    protected $signature = 'deployhub:dispatch-scheduled';

    protected $description = 'Queue due reviewed deployments after fresh permission and configuration checks';

    public function handle(DeploymentScheduler $scheduler): int
    {
        $counts = $scheduler->dispatchDue();
        $this->info('Scheduled deployments: '.collect($counts)->map(fn ($count, $state) => $state.'='.$count)->implode(', '));

        return self::SUCCESS;
    }
}
