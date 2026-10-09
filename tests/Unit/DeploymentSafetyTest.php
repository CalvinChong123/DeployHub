<?php

namespace Tests\Unit;

use App\Services\Deployment\CommandBuilder;
use App\Services\Deployment\DeploymentException;
use App\Services\Deployment\HostKeyVerifier;
use App\Services\Deployment\LogSanitizer;
use Tests\TestCase;

class DeploymentSafetyTest extends TestCase
{
    public function test_posix_quoting_is_correct_even_on_windows(): void
    {
        $builder = new CommandBuilder;
        $this->assertSame("'a'\"'\"'b'", $builder->quote("a'b"));
        $this->assertStringContainsString("'--ff-only'", $builder->git('/home/deploy/app', ['merge', '--ff-only', str_repeat('a', 40)]));
    }

    public function test_shell_injection_and_traversal_paths_and_refs_are_rejected(): void
    {
        $builder = new CommandBuilder;
        foreach (['/', 'relative', '/home/../root', '/home/app;id', '/home/$(id)', '/home/app\nwhoami', '/home//app', '/home/./app'] as $path) {
            try {
                $builder->validatePath($path);
                $this->fail('Unsafe path accepted.');
            } catch (DeploymentException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['-main', 'main..other', 'branch;id', 'main@{0}', 'main/../other', 'main.lock', 'main/'] as $branch) {
            try {
                $builder->validateBranch($branch);
                $this->fail('Unsafe ref accepted.');
            } catch (DeploymentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_host_fingerprint_mismatch_or_missing_pin_fails_closed(): void
    {
        $verifier = new HostKeyVerifier;
        $key = 'ssh-ed25519 '.base64_encode('fixture host key');
        $fp = $verifier->fingerprint($key);
        $this->assertSame('SHA256:'.rtrim(base64_encode(hash('sha256', 'fixture host key', true)), '='), $fp);
        $verifier->verify($fp, $fp);
        foreach ([null, 'SHA256:changed'] as $pin) {
            try {
                $verifier->verify($pin, $fp);
                $this->fail('Bad pin accepted.');
            } catch (DeploymentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_logs_redact_secrets_keys_tokens_urls_and_control_sequences(): void
    {
        $input = "APP_KEY=base64:unsafe\nhttps://user:password@github.com/repo\nghp_secretToken123\nAuthorization: Bearer unsafe\n-----BEGIN OPENSSH PRIVATE KEY-----\nunsafe\n-----END OPENSSH PRIVATE KEY-----\n\033[31mHello\033[0m";
        $output = (new LogSanitizer)->clean($input);
        foreach (['unsafe', 'user:password', 'ghp_secretToken123', '\033'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
        $this->assertStringContainsString('Hello', $output);
        $this->assertLessThanOrEqual(32768, strlen((new LogSanitizer)->clean(str_repeat('a', 40000))));
    }

    public function test_health_check_rejects_credentials_and_insecure_protocols(): void
    {
        $builder = new CommandBuilder;
        foreach (['http://example.test', 'https://user:pass@example.test', 'https://example.test?token=x', 'file:///etc/passwd', 'https://example.test/#secret'] as $url) {
            try {
                $builder->healthUrl($url);
                $this->fail('Unsafe health URL accepted.');
            } catch (DeploymentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('https://example.test/up', $builder->healthUrl('https://example.test/up'));
    }

    public function test_free_form_and_migration_commands_cannot_be_built(): void
    {
        $this->expectException(DeploymentException::class);
        (new CommandBuilder)->step('/home/deploy/app', 'migrate --force', null);
    }
}
