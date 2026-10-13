<?php

namespace Tests\Unit;

use App\Services\Deployment\CommandBuilder;
use App\Services\Deployment\CommandResult;
use App\Services\Deployment\DeploymentException;
use App\Services\Deployment\InspectionBatch;
use App\Services\Deployment\RemoteConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InspectionBatchTest extends TestCase
{
    private function remote(string $fault = ''): RemoteConnection
    {
        return new class($fault) implements RemoteConnection
        {
            public function __construct(private string $fault) {}

            public function run(string $command): CommandResult
            {
                preg_match('/DH[a-f0-9]{32}/', $command, $match);
                $marker = $match[0];
                $output = "\n{$marker}:first:start\nfirst\0line\nnext\n{$marker}:first:end:0\n";
                $output .= "\n{$marker}:second:start\nerror details\n{$marker}:second:end:7\n";
                $output = match ($this->fault) {
                    'truncated' => substr($output, 0, -10),
                    'wrong_order' => str_replace(':first:start', ':second:start', $output),
                    'extra' => $output.'unexpected bytes',
                    'prefix' => 'unexpected bytes'.$output,
                    'invalid_exit' => str_replace(':end:7', ':end:no', $output),
                    default => $output,
                };

                return new CommandResult($output, $this->fault === 'shell_failure' ? 1 : 0);
            }
        };
    }

    public function test_binary_outputs_and_individual_failures_remain_separate(): void
    {
        $results = (new InspectionBatch(new CommandBuilder))->run($this->remote(), '/home/deploy/app', ['first' => "printf 'first'", 'second' => 'false']);
        $this->assertSame("first\0line\nnext", $results['first']->output);
        $this->assertSame(0, $results['first']->exitCode);
        $this->assertSame('error details', $results['second']->output);
        $this->assertSame(7, $results['second']->exitCode);
    }

    public static function faults(): array
    {
        return array_map(fn ($fault) => [$fault], ['truncated', 'wrong_order', 'extra', 'prefix', 'invalid_exit', 'shell_failure']);
    }

    #[DataProvider('faults')]
    public function test_incomplete_or_malformed_frames_are_rejected(string $fault): void
    {
        $this->expectException(DeploymentException::class);
        (new InspectionBatch(new CommandBuilder))->run($this->remote($fault), '/home/deploy/app', ['first' => 'true', 'second' => 'false']);
    }
}
