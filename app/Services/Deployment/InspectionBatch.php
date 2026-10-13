<?php

namespace App\Services\Deployment;

class InspectionBatch
{
    public function __construct(private CommandBuilder $commands) {}

    /** @param array<string, string> $commands */
    public function run(RemoteConnection $remote, string $path, array $commands): array
    {
        $marker = 'DH'.bin2hex(random_bytes(16));
        $lines = [];
        foreach ($commands as $name => $command) {
            if (! preg_match('/^[a-z][a-z0-9_]*$/D', $name)) {
                throw new \LogicException('Invalid inspection field.');
            }
            $lines[] = "printf '\\n%s\\n' ".$this->commands->quote($marker.':'.$name.':start');
            $lines[] = $command.' 2>&1';
            $lines[] = '_deployhub_exit=$?';
            $lines[] = "printf '\\n%s:%s\\n' ".$this->commands->quote($marker.':'.$name.':end').' "$_deployhub_exit"';
        }
        $result = $remote->run($this->commands->at($path, ['sh', '-c', implode("\n", $lines)]));
        if ($result->exitCode !== 0) {
            throw new DeploymentException('Repository inspection could not complete. Verify the directory and shell.');
        }
        $remaining = $result->output;
        $results = [];
        foreach ($commands as $name => $command) {
            $start = "\n".$marker.':'.$name.":start\n";
            if (! str_starts_with($remaining, $start)) {
                throw new DeploymentException('Repository inspection returned incomplete or malformed results.');
            }
            $remaining = substr($remaining, strlen($start));
            if (! preg_match('/\n'.preg_quote($marker.':'.$name.':end:', '/').'([0-9]{1,3})\n/', $remaining, $match, PREG_OFFSET_CAPTURE)) {
                throw new DeploymentException('Repository inspection returned incomplete or malformed results.');
            }
            $results[$name] = new CommandResult(substr($remaining, 0, $match[0][1]), (int) $match[1][0]);
            $remaining = substr($remaining, $match[0][1] + strlen($match[0][0]));
        }
        if ($remaining !== '') {
            throw new DeploymentException('Repository inspection returned unexpected output.');
        }

        return $results;
    }
}
