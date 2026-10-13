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
        if (! $expected) {
            throw new DeploymentException('SSH host fingerprint has not been trusted. Open Servers, discover the fingerprint and choose how to trust it before testing authentication.');
        }
        if (! hash_equals($expected, $actual)) {
            throw new DeploymentException('SSH host fingerprint changed. Connection blocked; independently verify the new host key before replacing the saved fingerprint.');
        }
    }
}
