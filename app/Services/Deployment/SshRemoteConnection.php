<?php

namespace App\Services\Deployment;

use phpseclib3\Net\SSH2;

class SshRemoteConnection implements RemoteConnection
{
    public function __construct(private SSH2 $ssh) {}

    public function run(string $command): CommandResult
    {
        $output = '';
        $overflow = false;
        try {
            $this->ssh->exec($command, function (string $chunk) use (&$output, &$overflow) {
                if (strlen($output) + strlen($chunk) > 131072) {
                    $overflow = true;
                }
                $output .= substr($chunk, 0, max(0, 131072 - strlen($output)));
            });
            $exit = $this->ssh->getExitStatus();
            if ($this->ssh->isTimeout() || ! is_int($exit)) {
                throw new ConnectionLost('SSH command timed out or the connection was lost. Remote state needs reconciliation.');
            }
            if ($overflow) {
                throw new DeploymentException('Remote output exceeded the safe limit. Review the target manually.');
            }

            return new CommandResult($output, $exit);
        } catch (DeploymentException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new ConnectionLost('SSH connection was lost. Remote state needs reconciliation.');
        }
    }

    public function __destruct()
    {
        $this->ssh->disconnect();
    }
}
