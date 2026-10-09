<?php

namespace App\Services\Deployment;

class HostKeyVerifier
{
    public function fingerprint(string $key): string
    {
        $parts = explode(' ', trim($key));
        $decoded = base64_decode($parts[1] ?? '', true);
        if ($decoded === false || $decoded === '') {
            throw new DeploymentException('The server did not provide a valid host key.');
        }

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $decoded, true)), '=');
    }

    public function verify(?string $expected, string $actual): void
    {
        if (! $expected || ! hash_equals($expected, $actual)) {
            throw new DeploymentException('SSH host fingerprint is unverified or changed. Ask an administrator to verify it out of band.');
        }
    }
}
